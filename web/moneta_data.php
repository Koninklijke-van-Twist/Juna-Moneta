<?php

/**
 * Moneta data layer: SQLite PDO + nightly Rekeningschema-snapshots.
 */

require_once __DIR__ . '/project_data.php';

/** OData-cache tijdens nightly: 5 uur, zodat hertesten snel via cache gaat. */
const MONETA_NIGHTLY_ODATA_TTL = 18000;

require_once __DIR__ . '/moneta_gl.php';
require_once __DIR__ . '/moneta_forecast_user.php';
require_once __DIR__ . '/moneta_charts.php';

function moneta_data_dir(): string
{
    return __DIR__ . DIRECTORY_SEPARATOR . 'data';
}

function moneta_db_path(): string
{
    return moneta_data_dir() . DIRECTORY_SEPARATOR . 'moneta.sqlite';
}

function moneta_pdo(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    if (!extension_loaded('pdo_sqlite')) {
        throw new RuntimeException('De PDO SQLite-extensie is niet beschikbaar.');
    }

    $dir = moneta_data_dir();
    if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) {
        throw new RuntimeException('Kan data-map niet aanmaken.');
    }

    $pdo = new PDO('sqlite:' . moneta_db_path(), null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->exec('PRAGMA foreign_keys = ON');
    moneta_ensure_schema($pdo);

    return $pdo;
}

function moneta_table_has_column(PDO $pdo, string $table, string $column): bool
{
    $statement = $pdo->query('PRAGMA table_info(' . $table . ')');
    if ($statement === false) {
        return false;
    }

    foreach ($statement->fetchAll() as $row) {
        if ((string) ($row['name'] ?? '') === $column) {
            return true;
        }
    }

    return false;
}

function moneta_ensure_column(PDO $pdo, string $table, string $column, string $definition): void
{
    if (moneta_table_has_column($pdo, $table, $column)) {
        return;
    }

    $pdo->exec('ALTER TABLE ' . $table . ' ADD COLUMN ' . $column . ' ' . $definition);
}

function moneta_ensure_schema(PDO $pdo): void
{
    moneta_ensure_gl_schema($pdo);

    // Oude BC-prognose caches (termijnen / baseline / ProjectPosten-gewichten) zijn niet meer nodig.
    $pdo->exec('DROP TABLE IF EXISTS planned_installments');
    $pdo->exec('DROP TABLE IF EXISTS planned_installments_legacy');
    $pdo->exec('DROP TABLE IF EXISTS planned_baseline_costs');
    $pdo->exec('DROP TABLE IF EXISTS job_gl_account_weights');
    $pdo->exec('DROP TABLE IF EXISTS bank_balance_snapshots');
}

function moneta_parse_date(string $value): string
{
    $value = trim($value);
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
        return '';
    }

    $parts = explode('-', $value);
    if (!checkdate((int) $parts[1], (int) $parts[2], (int) $parts[0])) {
        return '';
    }

    return $value;
}

function moneta_default_date_from(): string
{
    return date('Y-m-d', strtotime('-1 year'));
}

function moneta_default_date_to(): string
{
    return date('Y-m-d', strtotime('+1 year'));
}

/**
 * Nightly: Rekeningschema-snapshot + groepscache per bedrijf.
 *
 * @return array{
 *   snapshot_date: string,
 *   gl: list<array>,
 *   errors: list<array{company: string, step: string, error: string}>
 * }
 */
function moneta_run_nightly_jobs(string $snapshotDate = '', int $ttl = MONETA_NIGHTLY_ODATA_TTL): array
{
    $snapshotDate = moneta_parse_date($snapshotDate);
    if ($snapshotDate === '') {
        $snapshotDate = date('Y-m-d');
    }
    $ttl = max(MONETA_NIGHTLY_ODATA_TTL, (int) $ttl);

    $companies = project_companies_for_page($ttl);
    $glResults = [];
    $errors = [];

    foreach ($companies as $company) {
        $company = trim((string) $company);
        if ($company === '') {
            continue;
        }

        if (PHP_SAPI === 'cli') {
            echo '[' . date('H:i:s') . "] Rekeningschema snapshot: {$company}\n";
        }

        try {
            $glResults[] = moneta_snapshot_gl_balances_for_company($company, $snapshotDate, $ttl);
        } catch (Throwable $error) {
            $errors[] = [
                'company' => $company,
                'step' => 'gl',
                'error' => $error->getMessage(),
            ];
        }
    }

    return [
        'snapshot_date' => $snapshotDate,
        'gl' => $glResults,
        'errors' => $errors,
    ];
}

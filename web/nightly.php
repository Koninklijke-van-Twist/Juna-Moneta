<?php

/**
 * Nightly job: Rekeningschema-saldi → SQLite (groepscache mee).
 * Wordt via GET/CLI aangeroepen door het bestaande nightly-script (geen UI).
 *
 * Voorbeeld: GET /Moneta/web/nightly.php
 *            php nightly.php
 */

set_time_limit(1800);
ini_set('max_execution_time', '1800');
ignore_user_abort(true);

require_once __DIR__ . '/auth.php';
if (PHP_SAPI !== 'cli') {
    require_once __DIR__ . '/logincheck.php';
}
require_once __DIR__ . '/odata.php';
require_once __DIR__ . '/auth_helper.php';
require_once __DIR__ . '/moneta_data.php';

$startedAt = hrtime(true);
$snapshotDate = moneta_parse_date((string) ($_GET['date'] ?? ''));
if ($snapshotDate === '' && PHP_SAPI === 'cli') {
    $snapshotDate = moneta_parse_date((string) ($argv[1] ?? ''));
}

try {
    $run = moneta_run_nightly_jobs($snapshotDate, MONETA_GL_ODATA_TTL);
    $hasResults = ($run['gl'] ?? []) !== [];
    $payload = [
        'ok' => $hasResults || ($run['errors'] ?? []) === [],
        'generated_at' => gmdate('c'),
        'snapshot_date' => (string) ($run['snapshot_date'] ?? ''),
        'odata_ttl_seconds' => MONETA_GL_ODATA_TTL,
        'companies_odata_ttl_seconds' => AUTH_COMPANIES_ODATA_TTL,
        'total_duration_ms' => (int) round((hrtime(true) - $startedAt) / 1_000_000),
        'gl' => $run['gl'] ?? [],
        'errors' => $run['errors'] ?? [],
    ];

    if (PHP_SAPI === 'cli') {
        echo 'OK snapshot_date=' . $payload['snapshot_date']
            . ' gl_odata_ttl=' . MONETA_GL_ODATA_TTL . 's'
            . ' companies_ttl=' . AUTH_COMPANIES_ODATA_TTL . "s\n";
        echo "Rekeningschema:\n";
        foreach ($payload['gl'] as $row) {
            echo sprintf(
                "  %s: accounts=%d stored=%d group_balances=%d\n",
                (string) ($row['company'] ?? ''),
                (int) ($row['accounts'] ?? 0),
                (int) ($row['stored'] ?? 0),
                (int) ($row['group_balances_stored'] ?? 0)
            );
        }
        foreach ($payload['errors'] as $error) {
            echo sprintf(
                "  ERROR [%s] %s: %s\n",
                (string) ($error['step'] ?? ''),
                (string) ($error['company'] ?? ''),
                (string) ($error['error'] ?? '')
            );
        }
        exit($payload['ok'] ? 0 : 1);
    }

    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    http_response_code($payload['ok'] ? 200 : 500);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $error) {
    if (PHP_SAPI === 'cli') {
        fwrite(STDERR, 'FAIL ' . $error->getMessage() . "\n");
        exit(1);
    }

    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'generated_at' => gmdate('c'),
        'error' => $error->getMessage(),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

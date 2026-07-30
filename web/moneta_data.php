<?php

/**
 * Includes/requires
 */
require_once __DIR__ . '/project_data.php';

/**
 * Constants
 */
const MONETA_BANK_SELECT = 'No,Name,BalanceLCY';

/**
 * Functies
 */

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

function moneta_ensure_schema(PDO $pdo): void
{
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS bank_balance_snapshots (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            company TEXT NOT NULL,
            account_no TEXT NOT NULL,
            account_name TEXT NOT NULL,
            balance_lcy REAL NOT NULL,
            snapshot_date TEXT NOT NULL,
            created_at TEXT NOT NULL,
            UNIQUE(company, account_no, snapshot_date)
        )'
    );
    $pdo->exec(
        'CREATE INDEX IF NOT EXISTS idx_bank_balance_company_date
         ON bank_balance_snapshots (company, snapshot_date)'
    );
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
    return date('Y-m-d');
}

function moneta_fetch_bank_accounts(string $company, int $ttl = 60): array
{
    $rows = project_fetch_rows($company, 'Bankrekeningen', [
        '$select' => MONETA_BANK_SELECT,
    ], $ttl);

    $accounts = [];
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }

        $accountNo = trim((string) ($row['No'] ?? ''));
        if ($accountNo === '') {
            continue;
        }

        $name = trim((string) ($row['Name'] ?? ''));
        if ($name === '') {
            $name = $accountNo;
        }

        $accounts[] = [
            'account_no' => $accountNo,
            'account_name' => $name,
            'balance_lcy' => (float) ($row['BalanceLCY'] ?? 0),
        ];
    }

    usort($accounts, static function (array $a, array $b): int {
        return strcasecmp((string) $a['account_name'], (string) $b['account_name']);
    });

    return $accounts;
}

function moneta_store_bank_snapshot(string $company, string $snapshotDate, array $accounts): int
{
    $snapshotDate = moneta_parse_date($snapshotDate);
    if ($snapshotDate === '') {
        throw new InvalidArgumentException('Ongeldige snapshot-datum.');
    }

    $pdo = moneta_pdo();
    $createdAt = gmdate('c');
    $statement = $pdo->prepare(
        'INSERT INTO bank_balance_snapshots
            (company, account_no, account_name, balance_lcy, snapshot_date, created_at)
         VALUES
            (:company, :account_no, :account_name, :balance_lcy, :snapshot_date, :created_at)
         ON CONFLICT(company, account_no, snapshot_date) DO UPDATE SET
            account_name = excluded.account_name,
            balance_lcy = excluded.balance_lcy,
            created_at = excluded.created_at'
    );

    $stored = 0;
    foreach ($accounts as $account) {
        if (!is_array($account)) {
            continue;
        }

        $accountNo = trim((string) ($account['account_no'] ?? ''));
        if ($accountNo === '') {
            continue;
        }

        $accountName = trim((string) ($account['account_name'] ?? ''));
        if ($accountName === '') {
            $accountName = $accountNo;
        }

        $statement->execute([
            ':company' => $company,
            ':account_no' => $accountNo,
            ':account_name' => $accountName,
            ':balance_lcy' => (float) ($account['balance_lcy'] ?? 0),
            ':snapshot_date' => $snapshotDate,
            ':created_at' => $createdAt,
        ]);
        $stored++;
    }

    return $stored;
}

function moneta_snapshot_bank_balances_for_company(string $company, string $snapshotDate = '', int $ttl = 60): array
{
    $snapshotDate = moneta_parse_date($snapshotDate);
    if ($snapshotDate === '') {
        $snapshotDate = date('Y-m-d');
    }

    $accounts = moneta_fetch_bank_accounts($company, $ttl);
    $stored = moneta_store_bank_snapshot($company, $snapshotDate, $accounts);

    return [
        'company' => $company,
        'snapshot_date' => $snapshotDate,
        'accounts' => count($accounts),
        'stored' => $stored,
    ];
}

function moneta_run_nightly_bank_snapshots(string $snapshotDate = '', int $ttl = 60): array
{
    $snapshotDate = moneta_parse_date($snapshotDate);
    if ($snapshotDate === '') {
        $snapshotDate = date('Y-m-d');
    }

    $companies = project_companies_for_page($ttl);
    $results = [];
    $errors = [];

    foreach ($companies as $company) {
        $company = trim((string) $company);
        if ($company === '') {
            continue;
        }

        try {
            $results[] = moneta_snapshot_bank_balances_for_company($company, $snapshotDate, $ttl);
        } catch (Throwable $error) {
            $errors[] = [
                'company' => $company,
                'error' => $error->getMessage(),
            ];
        }
    }

    return [
        'snapshot_date' => $snapshotDate,
        'results' => $results,
        'errors' => $errors,
    ];
}

/**
 * Bouwt chart-klare series uit SQLite (geen live BC-calls).
 *
 * @return array{labels: string[], series: list<array{account_no: string, name: string, data: list<float|null>}>}
 */
function moneta_bank_chart_data(string $company, string $dateFrom, string $dateTo): array
{
    $dateFrom = moneta_parse_date($dateFrom);
    $dateTo = moneta_parse_date($dateTo);
    if ($dateFrom === '' || $dateTo === '') {
        return ['labels' => [], 'series' => []];
    }
    if ($dateFrom > $dateTo) {
        $tmp = $dateFrom;
        $dateFrom = $dateTo;
        $dateTo = $tmp;
    }

    $pdo = moneta_pdo();
    $statement = $pdo->prepare(
        'SELECT account_no, account_name, balance_lcy, snapshot_date
         FROM bank_balance_snapshots
         WHERE company = :company
           AND snapshot_date >= :date_from
           AND snapshot_date <= :date_to
         ORDER BY snapshot_date ASC, account_name COLLATE NOCASE ASC'
    );
    $statement->execute([
        ':company' => $company,
        ':date_from' => $dateFrom,
        ':date_to' => $dateTo,
    ]);
    $rows = $statement->fetchAll();

    if ($rows === []) {
        return ['labels' => [], 'series' => []];
    }

    $labelsMap = [];
    $accounts = [];
    foreach ($rows as $row) {
        $date = (string) ($row['snapshot_date'] ?? '');
        $accountNo = (string) ($row['account_no'] ?? '');
        if ($date === '' || $accountNo === '') {
            continue;
        }

        $labelsMap[$date] = true;
        if (!isset($accounts[$accountNo])) {
            $accounts[$accountNo] = [
                'account_no' => $accountNo,
                'name' => (string) ($row['account_name'] ?? $accountNo),
                'points' => [],
            ];
        } else {
            $name = trim((string) ($row['account_name'] ?? ''));
            if ($name !== '') {
                $accounts[$accountNo]['name'] = $name;
            }
        }
        $accounts[$accountNo]['points'][$date] = (float) ($row['balance_lcy'] ?? 0);
    }

    $labels = array_keys($labelsMap);
    sort($labels);

    uasort($accounts, static function (array $a, array $b): int {
        return strcasecmp((string) $a['name'], (string) $b['name']);
    });

    $series = [];
    foreach ($accounts as $account) {
        $data = [];
        foreach ($labels as $label) {
            $data[] = array_key_exists($label, $account['points'])
                ? (float) $account['points'][$label]
                : null;
        }
        $series[] = [
            'account_no' => (string) $account['account_no'],
            'name' => (string) $account['name'],
            'data' => $data,
        ];
    }

    return [
        'labels' => $labels,
        'series' => $series,
    ];
}

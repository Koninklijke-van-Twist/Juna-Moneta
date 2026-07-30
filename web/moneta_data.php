<?php

/**
 * Includes/requires
 */
require_once __DIR__ . '/project_data.php';

/**
 * Constants
 */
const MONETA_BANK_SELECT = 'No,Name,BalanceLCY,Currency_Code';
const MONETA_OPEN_PROJECT_SELECT = 'No,Status,Ending_Date,Description';
const MONETA_INSTALLMENT_SELECT = 'Job_No,Job_Task_No,Line_No,Line_Type,Description,Document_No,Line_Amount_LCY,Planning_Date,Invoiced_Amount_LCY,Qty_Invoiced,Quantity,LVS_Invoice_Currency_Code';
const MONETA_UNASSIGNED_ACCOUNT_NO = '__unassigned__';

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
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS bank_balance_snapshots (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            company TEXT NOT NULL,
            account_no TEXT NOT NULL,
            account_name TEXT NOT NULL,
            balance_lcy REAL NOT NULL,
            currency_code TEXT NOT NULL DEFAULT \'\',
            snapshot_date TEXT NOT NULL,
            created_at TEXT NOT NULL,
            UNIQUE(company, account_no, snapshot_date)
        )'
    );
    moneta_ensure_column($pdo, 'bank_balance_snapshots', 'currency_code', "TEXT NOT NULL DEFAULT ''");
    $pdo->exec(
        'CREATE INDEX IF NOT EXISTS idx_bank_balance_company_date
         ON bank_balance_snapshots (company, snapshot_date)'
    );

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS planned_installments (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            company TEXT NOT NULL,
            job_no TEXT NOT NULL,
            job_task_no TEXT NOT NULL DEFAULT \'\',
            line_no INTEGER NOT NULL,
            description TEXT NOT NULL DEFAULT \'\',
            amount_lcy REAL NOT NULL,
            planning_date TEXT NOT NULL,
            currency_code TEXT NOT NULL DEFAULT \'\',
            document_no TEXT NOT NULL DEFAULT \'\',
            bank_account_no TEXT NOT NULL DEFAULT \'\',
            refreshed_at TEXT NOT NULL,
            UNIQUE(company, job_no, job_task_no, line_no)
        )'
    );
    moneta_ensure_column($pdo, 'planned_installments', 'job_task_no', "TEXT NOT NULL DEFAULT ''");
    // Oude unique op (company, job_no, line_no) kan botsen; herbouw tabel indien nodig.
    moneta_migrate_planned_installments_unique($pdo);
    $pdo->exec(
        'CREATE INDEX IF NOT EXISTS idx_planned_installments_company_date
         ON planned_installments (company, planning_date)'
    );
}

function moneta_migrate_planned_installments_unique(PDO $pdo): void
{
    $indexRows = $pdo->query("SELECT sql FROM sqlite_master WHERE type = 'table' AND name = 'planned_installments'")->fetchAll();
    $createSql = (string) ($indexRows[0]['sql'] ?? '');
    if ($createSql === '') {
        return;
    }

    $hasTaskInUnique = str_contains($createSql, 'job_task_no')
        && str_contains($createSql, 'UNIQUE(company, job_no, job_task_no, line_no)');
    if ($hasTaskInUnique) {
        return;
    }

    $pdo->exec('ALTER TABLE planned_installments RENAME TO planned_installments_legacy');
    $pdo->exec(
        'CREATE TABLE planned_installments (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            company TEXT NOT NULL,
            job_no TEXT NOT NULL,
            job_task_no TEXT NOT NULL DEFAULT \'\',
            line_no INTEGER NOT NULL,
            description TEXT NOT NULL DEFAULT \'\',
            amount_lcy REAL NOT NULL,
            planning_date TEXT NOT NULL,
            currency_code TEXT NOT NULL DEFAULT \'\',
            document_no TEXT NOT NULL DEFAULT \'\',
            bank_account_no TEXT NOT NULL DEFAULT \'\',
            refreshed_at TEXT NOT NULL,
            UNIQUE(company, job_no, job_task_no, line_no)
        )'
    );
    $pdo->exec(
        'INSERT OR IGNORE INTO planned_installments
            (company, job_no, job_task_no, line_no, description, amount_lcy, planning_date, currency_code, document_no, bank_account_no, refreshed_at)
         SELECT company, job_no, COALESCE(job_task_no, \'\'), line_no, description, amount_lcy, planning_date, currency_code, document_no, bank_account_no, refreshed_at
         FROM planned_installments_legacy'
    );
    $pdo->exec('DROP TABLE planned_installments_legacy');
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

function moneta_is_bc_blank_date(string $value): bool
{
    $value = trim($value);
    if ($value === '') {
        return true;
    }

    return strncmp($value, '0001-01-01', 10) === 0
        || strncmp($value, '1753-01-01', 10) === 0;
}

function moneta_normalize_currency_code(string $value): string
{
    return strtoupper(trim($value));
}

function moneta_default_date_from(): string
{
    return date('Y-m-d', strtotime('-1 year'));
}

function moneta_default_date_to(): string
{
    return date('Y-m-d');
}

function moneta_default_forecast_from(): string
{
    return date('Y-m-d');
}

function moneta_default_forecast_to(): string
{
    return date('Y-m-d', strtotime('+1 year'));
}

function moneta_clamp_forecast_from(string $dateFrom): string
{
    $today = date('Y-m-d');
    $dateFrom = moneta_parse_date($dateFrom);
    if ($dateFrom === '' || $dateFrom < $today) {
        return $today;
    }

    return $dateFrom;
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
            'currency_code' => moneta_normalize_currency_code((string) ($row['Currency_Code'] ?? '')),
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
            (company, account_no, account_name, balance_lcy, currency_code, snapshot_date, created_at)
         VALUES
            (:company, :account_no, :account_name, :balance_lcy, :currency_code, :snapshot_date, :created_at)
         ON CONFLICT(company, account_no, snapshot_date) DO UPDATE SET
            account_name = excluded.account_name,
            balance_lcy = excluded.balance_lcy,
            currency_code = excluded.currency_code,
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
            ':currency_code' => moneta_normalize_currency_code((string) ($account['currency_code'] ?? '')),
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

/**
 * Lopende projecten: Status = Open, of Ending_Date leeg/in de toekomst (FinRap-achtig).
 *
 * @return list<string>
 */
function moneta_fetch_open_project_nos(string $company, int $ttl = 60): array
{
    $rows = project_try_fetch_rows($company, 'Projecten', [
        '$select' => MONETA_OPEN_PROJECT_SELECT,
        '$filter' => "Status eq 'Open'",
    ], $ttl);

    if ($rows === []) {
        $rows = project_try_fetch_rows($company, 'AppProjecten', [
            '$select' => 'No,Status,LVS_Ending_Date,Description',
            '$filter' => "Status eq 'Open'",
        ], $ttl);
    }

    $today = date('Y-m-d');
    $jobNos = [];
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }

        $jobNo = trim((string) ($row['No'] ?? ''));
        if ($jobNo === '') {
            continue;
        }

        $endingDate = trim((string) ($row['Ending_Date'] ?? $row['LVS_Ending_Date'] ?? ''));
        if (!moneta_is_bc_blank_date($endingDate)) {
            $endingDay = substr($endingDate, 0, 10);
            if (moneta_parse_date($endingDay) !== '' && $endingDay < $today) {
                continue;
            }
        }

        $jobNos[$jobNo] = true;
    }

    $list = array_keys($jobNos);
    natcasesort($list);

    return array_values($list);
}

function moneta_is_factureerbare_line_type(string $lineType): bool
{
    $lineType = strtolower(trim($lineType));
    if ($lineType === '') {
        return false;
    }

    $isFactureerbaar = str_contains($lineType, 'factureer') || str_contains($lineType, 'billable');
    $isForecast = str_contains($lineType, 'prognose') || str_contains($lineType, 'forecast');

    return $isFactureerbaar && !$isForecast;
}

function moneta_installment_remaining_amount(array $row): float
{
    $lineAmount = (float) ($row['Line_Amount_LCY'] ?? 0);
    $invoicedAmount = (float) ($row['Invoiced_Amount_LCY'] ?? 0);
    $remaining = $lineAmount - $invoicedAmount;
    if (abs($remaining) < 0.000001) {
        $qty = (float) ($row['Quantity'] ?? 0);
        $qtyInvoiced = (float) ($row['Qty_Invoiced'] ?? 0);
        if ($qty > 0 && $qtyInvoiced >= $qty) {
            return 0.0;
        }

        return $lineAmount;
    }

    return $remaining;
}

/**
 * @param list<string> $jobNos
 * @return list<array<string, mixed>>
 */
function moneta_fetch_planned_installments_for_jobs(string $company, array $jobNos, string $dateFrom, int $ttl = 60): array
{
    $dateFrom = moneta_parse_date($dateFrom);
    if ($dateFrom === '' || $jobNos === []) {
        return [];
    }

    $openJobs = [];
    foreach ($jobNos as $jobNo) {
        $jobNo = trim((string) $jobNo);
        if ($jobNo !== '') {
            $openJobs[$jobNo] = true;
        }
    }
    if ($openJobs === []) {
        return [];
    }

    // Eén (gepagineerde) OData-call i.p.v. N chunks per Job_No.
    $dateTo = date('Y-m-d', strtotime($dateFrom . ' +2 years'));
    $rows = project_try_fetch_rows($company, 'FactureerbareProjectPlanningsRegels', [
        '$select' => MONETA_INSTALLMENT_SELECT,
        '$filter' => 'Planning_Date ge ' . $dateFrom . ' and Planning_Date le ' . $dateTo,
    ], $ttl);

    $installments = [];
    foreach ($rows as $row) {
        if (!is_array($row) || !moneta_is_factureerbare_line_type((string) ($row['Line_Type'] ?? ''))) {
            continue;
        }

        $jobNo = trim((string) ($row['Job_No'] ?? ''));
        if ($jobNo === '' || !isset($openJobs[$jobNo])) {
            continue;
        }

        $planningDate = moneta_parse_date(substr(trim((string) ($row['Planning_Date'] ?? '')), 0, 10));
        if ($planningDate === '' || $planningDate < $dateFrom) {
            continue;
        }

        $amount = moneta_installment_remaining_amount($row);
        if (abs($amount) < 0.000001) {
            continue;
        }

        $lineNo = (int) ($row['Line_No'] ?? 0);
        if ($lineNo <= 0) {
            continue;
        }

        $installments[] = [
            'job_no' => $jobNo,
            'job_task_no' => trim((string) ($row['Job_Task_No'] ?? '')),
            'line_no' => $lineNo,
            'description' => trim((string) ($row['Description'] ?? '')),
            'amount_lcy' => $amount,
            'planning_date' => $planningDate,
            'currency_code' => moneta_normalize_currency_code((string) ($row['LVS_Invoice_Currency_Code'] ?? '')),
            'document_no' => trim((string) ($row['Document_No'] ?? '')),
            'bank_account_no' => '',
        ];
    }

    return $installments;
}

/**
 * Probeer Company_Bank_Account_Code via SalesInvoice te resolven (max. beperkte set).
 *
 * @param list<array<string, mixed>> $installments
 * @return list<array<string, mixed>>
 */
function moneta_resolve_installment_banks_from_documents(string $company, array $installments, int $ttl = 60): array
{
    $documentNos = [];
    foreach ($installments as $row) {
        $documentNo = trim((string) ($row['document_no'] ?? ''));
        if ($documentNo !== '') {
            $documentNos[$documentNo] = true;
        }
    }

    // Beperk lookups: documentresolutie is optioneel; valuta-match dekt de rest.
    $documentList = array_slice(array_keys($documentNos), 0, 60);
    if ($documentList === []) {
        return $installments;
    }

    $bankByDocument = [];
    $chunks = array_chunk($documentList, 15);
    foreach ($chunks as $chunk) {
        $filters = [];
        foreach ($chunk as $documentNo) {
            $filters[] = "No eq '" . project_escape_odata_string($documentNo) . "'";
        }
        $filter = implode(' or ', $filters);

        $rows = project_try_fetch_rows($company, 'SalesInvoice', [
            '$select' => 'No,Company_Bank_Account_Code',
            '$filter' => $filter,
        ], $ttl);
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $no = trim((string) ($row['No'] ?? ''));
            $bank = trim((string) ($row['Company_Bank_Account_Code'] ?? ''));
            if ($no !== '' && $bank !== '') {
                $bankByDocument[$no] = $bank;
            }
        }
    }

    foreach ($installments as &$row) {
        $documentNo = trim((string) ($row['document_no'] ?? ''));
        if ($documentNo !== '' && isset($bankByDocument[$documentNo])) {
            $row['bank_account_no'] = $bankByDocument[$documentNo];
        }
    }
    unset($row);

    return $installments;
}

/**
 * Valuta-matching: lege currency = LCY. Bij meerdere rekeningen de hoogste |BalanceLCY|.
 *
 * @param list<array<string, mixed>> $installments
 * @param list<array<string, mixed>> $accounts
 * @return list<array<string, mixed>>
 */
function moneta_resolve_installment_banks_by_currency(array $installments, array $accounts): array
{
    $byCurrency = [];
    foreach ($accounts as $account) {
        if (!is_array($account)) {
            continue;
        }
        $accountNo = trim((string) ($account['account_no'] ?? ''));
        if ($accountNo === '') {
            continue;
        }
        $currency = moneta_normalize_currency_code((string) ($account['currency_code'] ?? ''));
        $candidate = [
            'account_no' => $accountNo,
            'balance_abs' => abs((float) ($account['balance_lcy'] ?? 0)),
        ];
        if (!isset($byCurrency[$currency]) || $candidate['balance_abs'] > $byCurrency[$currency]['balance_abs']) {
            $byCurrency[$currency] = $candidate;
        }
    }

    foreach ($installments as &$row) {
        if (trim((string) ($row['bank_account_no'] ?? '')) !== '') {
            continue;
        }
        $currency = moneta_normalize_currency_code((string) ($row['currency_code'] ?? ''));
        if (isset($byCurrency[$currency])) {
            $row['bank_account_no'] = $byCurrency[$currency]['account_no'];
        }
    }
    unset($row);

    return $installments;
}

function moneta_store_planned_installments(string $company, array $installments): int
{
    $pdo = moneta_pdo();
    $pdo->beginTransaction();
    try {
        $delete = $pdo->prepare('DELETE FROM planned_installments WHERE company = :company');
        $delete->execute([':company' => $company]);

        $insert = $pdo->prepare(
            'INSERT INTO planned_installments
                (company, job_no, job_task_no, line_no, description, amount_lcy, planning_date, currency_code, document_no, bank_account_no, refreshed_at)
             VALUES
                (:company, :job_no, :job_task_no, :line_no, :description, :amount_lcy, :planning_date, :currency_code, :document_no, :bank_account_no, :refreshed_at)
             ON CONFLICT(company, job_no, job_task_no, line_no) DO UPDATE SET
                description = excluded.description,
                amount_lcy = excluded.amount_lcy,
                planning_date = excluded.planning_date,
                currency_code = excluded.currency_code,
                document_no = excluded.document_no,
                bank_account_no = excluded.bank_account_no,
                refreshed_at = excluded.refreshed_at'
        );

        $refreshedAt = gmdate('c');
        $stored = 0;
        foreach ($installments as $row) {
            if (!is_array($row)) {
                continue;
            }

            $jobNo = trim((string) ($row['job_no'] ?? ''));
            $lineNo = (int) ($row['line_no'] ?? 0);
            $planningDate = moneta_parse_date((string) ($row['planning_date'] ?? ''));
            if ($jobNo === '' || $lineNo <= 0 || $planningDate === '') {
                continue;
            }

            $insert->execute([
                ':company' => $company,
                ':job_no' => $jobNo,
                ':job_task_no' => trim((string) ($row['job_task_no'] ?? '')),
                ':line_no' => $lineNo,
                ':description' => trim((string) ($row['description'] ?? '')),
                ':amount_lcy' => (float) ($row['amount_lcy'] ?? 0),
                ':planning_date' => $planningDate,
                ':currency_code' => moneta_normalize_currency_code((string) ($row['currency_code'] ?? '')),
                ':document_no' => trim((string) ($row['document_no'] ?? '')),
                ':bank_account_no' => trim((string) ($row['bank_account_no'] ?? '')),
                ':refreshed_at' => $refreshedAt,
            ]);
            $stored++;
        }

        $pdo->commit();

        return $stored;
    } catch (Throwable $error) {
        $pdo->rollBack();
        throw $error;
    }
}

function moneta_snapshot_planned_installments_for_company(string $company, string $fromDate = '', int $ttl = 60): array
{
    $fromDate = moneta_parse_date($fromDate);
    if ($fromDate === '') {
        $fromDate = date('Y-m-d');
    }

    $openJobs = moneta_fetch_open_project_nos($company, $ttl);
    $installments = moneta_fetch_planned_installments_for_jobs($company, $openJobs, $fromDate, $ttl);
    $accounts = moneta_fetch_bank_accounts($company, $ttl);
    $installments = moneta_resolve_installment_banks_from_documents($company, $installments, $ttl);
    $installments = moneta_resolve_installment_banks_by_currency($installments, $accounts);
    $stored = moneta_store_planned_installments($company, $installments);

    return [
        'company' => $company,
        'open_projects' => count($openJobs),
        'installments' => count($installments),
        'stored' => $stored,
    ];
}

function moneta_run_nightly_jobs(string $snapshotDate = '', int $ttl = 60): array
{
    $snapshotDate = moneta_parse_date($snapshotDate);
    if ($snapshotDate === '') {
        $snapshotDate = date('Y-m-d');
    }

    $companies = project_companies_for_page($ttl);
    $bankResults = [];
    $installmentResults = [];
    $errors = [];

    foreach ($companies as $company) {
        $company = trim((string) $company);
        if ($company === '') {
            continue;
        }

        if (PHP_SAPI === 'cli') {
            echo '[' . date('H:i:s') . "] bank snapshot: {$company}\n";
        }

        try {
            $bankResults[] = moneta_snapshot_bank_balances_for_company($company, $snapshotDate, $ttl);
        } catch (Throwable $error) {
            $errors[] = [
                'company' => $company,
                'step' => 'bank',
                'error' => $error->getMessage(),
            ];
            continue;
        }

        if (PHP_SAPI === 'cli') {
            echo '[' . date('H:i:s') . "] installments: {$company}\n";
        }

        try {
            $installmentResults[] = moneta_snapshot_planned_installments_for_company($company, $snapshotDate, $ttl);
        } catch (Throwable $error) {
            $errors[] = [
                'company' => $company,
                'step' => 'installments',
                'error' => $error->getMessage(),
            ];
        }
    }

    return [
        'snapshot_date' => $snapshotDate,
        'bank' => $bankResults,
        'installments' => $installmentResults,
        'errors' => $errors,
    ];
}

/**
 * @deprecated Gebruik moneta_run_nightly_jobs
 */
function moneta_run_nightly_bank_snapshots(string $snapshotDate = '', int $ttl = 60): array
{
    $run = moneta_run_nightly_jobs($snapshotDate, $ttl);

    return [
        'snapshot_date' => $run['snapshot_date'],
        'results' => $run['bank'],
        'errors' => $run['errors'],
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

/**
 * @return list<array{account_no: string, account_name: string, balance_lcy: float, currency_code: string}>
 */
function moneta_latest_bank_balances(string $company, string $asOfDate = ''): array
{
    $asOfDate = moneta_parse_date($asOfDate);
    if ($asOfDate === '') {
        $asOfDate = date('Y-m-d');
    }

    $pdo = moneta_pdo();
    $dateStatement = $pdo->prepare(
        'SELECT MAX(snapshot_date) AS snapshot_date
         FROM bank_balance_snapshots
         WHERE company = :company
           AND snapshot_date <= :as_of'
    );
    $dateStatement->execute([
        ':company' => $company,
        ':as_of' => $asOfDate,
    ]);
    $snapshotDate = moneta_parse_date((string) ($dateStatement->fetch()['snapshot_date'] ?? ''));
    if ($snapshotDate === '') {
        return [];
    }

    $statement = $pdo->prepare(
        'SELECT account_no, account_name, balance_lcy, currency_code
         FROM bank_balance_snapshots
         WHERE company = :company
           AND snapshot_date = :snapshot_date
         ORDER BY account_name COLLATE NOCASE ASC'
    );
    $statement->execute([
        ':company' => $company,
        ':snapshot_date' => $snapshotDate,
    ]);

    $accounts = [];
    foreach ($statement->fetchAll() as $row) {
        $accountNo = trim((string) ($row['account_no'] ?? ''));
        if ($accountNo === '') {
            continue;
        }
        $accounts[] = [
            'account_no' => $accountNo,
            'account_name' => trim((string) ($row['account_name'] ?? $accountNo)),
            'balance_lcy' => (float) ($row['balance_lcy'] ?? 0),
            'currency_code' => moneta_normalize_currency_code((string) ($row['currency_code'] ?? '')),
        ];
    }

    return $accounts;
}

/**
 * @return list<array<string, mixed>>
 */
function moneta_load_planned_installments(string $company, string $dateFrom, string $dateTo): array
{
    $dateFrom = moneta_parse_date($dateFrom);
    $dateTo = moneta_parse_date($dateTo);
    if ($dateFrom === '' || $dateTo === '') {
        return [];
    }
    if ($dateFrom > $dateTo) {
        $tmp = $dateFrom;
        $dateFrom = $dateTo;
        $dateTo = $tmp;
    }

    $pdo = moneta_pdo();
    $statement = $pdo->prepare(
        'SELECT job_no, line_no, description, amount_lcy, planning_date, currency_code, document_no, bank_account_no
         FROM planned_installments
         WHERE company = :company
           AND planning_date >= :date_from
           AND planning_date <= :date_to
         ORDER BY planning_date ASC, job_no ASC, line_no ASC'
    );
    $statement->execute([
        ':company' => $company,
        ':date_from' => $dateFrom,
        ':date_to' => $dateTo,
    ]);

    return $statement->fetchAll();
}

/**
 * Prognose: startsaldi vandaag + cumulatieve geplande termijnfacturen per rekening.
 *
 * Koppeling factuur→rekening (beste beschikbare signalen):
 * 1) SalesInvoice/SalesOrder.Company_Bank_Account_Code via Document_No
 * 2) Valuta-match LVS_Invoice_Currency_Code ↔ Bankrekeningen.Currency_Code
 * 3) Anders serie "Niet toegewezen"
 *
 * @return array{labels: string[], series: list<array{account_no: string, name: string, data: list<float|null>}>, meta: array}
 */
function moneta_forecast_chart_data(string $company, string $dateFrom, string $dateTo): array
{
    $dateFrom = moneta_clamp_forecast_from($dateFrom);
    $dateTo = moneta_parse_date($dateTo);
    if ($dateTo === '') {
        $dateTo = moneta_default_forecast_to();
    }
    if ($dateFrom > $dateTo) {
        $dateTo = $dateFrom;
    }

    $accounts = moneta_latest_bank_balances($company, $dateFrom);
    $installments = moneta_load_planned_installments($company, $dateFrom, $dateTo);

    if ($accounts === [] && $installments === []) {
        return [
            'labels' => [],
            'series' => [],
            'meta' => [
                'installment_count' => 0,
                'installment_total' => 0.0,
                'unassigned_count' => 0,
            ],
        ];
    }

    $accountMap = [];
    foreach ($accounts as $account) {
        $accountMap[$account['account_no']] = [
            'account_no' => $account['account_no'],
            'name' => $account['account_name'],
            'start' => (float) $account['balance_lcy'],
            'inflows' => [],
        ];
    }

    $unassignedCount = 0;
    $installmentTotal = 0.0;
    $eventDates = [$dateFrom => true];

    foreach ($installments as $row) {
        $planningDate = moneta_parse_date((string) ($row['planning_date'] ?? ''));
        $amount = (float) ($row['amount_lcy'] ?? 0);
        if ($planningDate === '' || abs($amount) < 0.000001) {
            continue;
        }

        $bankAccountNo = trim((string) ($row['bank_account_no'] ?? ''));
        if ($bankAccountNo === '' || !isset($accountMap[$bankAccountNo])) {
            $bankAccountNo = MONETA_UNASSIGNED_ACCOUNT_NO;
            if (!isset($accountMap[$bankAccountNo])) {
                $accountMap[$bankAccountNo] = [
                    'account_no' => MONETA_UNASSIGNED_ACCOUNT_NO,
                    'name' => 'Niet toegewezen',
                    'start' => 0.0,
                    'inflows' => [],
                ];
            }
            $unassignedCount++;
        }

        if (!isset($accountMap[$bankAccountNo]['inflows'][$planningDate])) {
            $accountMap[$bankAccountNo]['inflows'][$planningDate] = 0.0;
        }
        $accountMap[$bankAccountNo]['inflows'][$planningDate] += $amount;
        $installmentTotal += $amount;
        $eventDates[$planningDate] = true;
    }

    $labels = array_keys($eventDates);
    sort($labels);

    uasort($accountMap, static function (array $a, array $b): int {
        if ($a['account_no'] === MONETA_UNASSIGNED_ACCOUNT_NO) {
            return 1;
        }
        if ($b['account_no'] === MONETA_UNASSIGNED_ACCOUNT_NO) {
            return -1;
        }

        return strcasecmp((string) $a['name'], (string) $b['name']);
    });

    $series = [];
    foreach ($accountMap as $account) {
        if (($account['inflows'] ?? []) === []) {
            continue;
        }

        $running = (float) $account['start'];
        $data = [];
        foreach ($labels as $label) {
            $running += (float) ($account['inflows'][$label] ?? 0);
            $data[] = $running;
        }

        $series[] = [
            'account_no' => (string) $account['account_no'],
            'name' => (string) $account['name'],
            'data' => $data,
        ];
    }

    if ($series === []) {
        return [
            'labels' => [],
            'series' => [],
            'meta' => [
                'installment_count' => count($installments),
                'installment_total' => round($installmentTotal, 2),
                'unassigned_count' => $unassignedCount,
            ],
        ];
    }

    return [
        'labels' => $labels,
        'series' => $series,
        'meta' => [
            'installment_count' => count($installments),
            'installment_total' => round($installmentTotal, 2),
            'unassigned_count' => $unassignedCount,
        ],
    ];
}

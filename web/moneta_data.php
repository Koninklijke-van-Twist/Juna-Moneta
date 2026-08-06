<?php

/**
 * Includes/requires
 */
require_once __DIR__ . '/project_data.php';

/**
 * Constants
 */
const MONETA_BANK_SELECT = 'No,Name,BalanceLCY,Currency_Code';
const MONETA_OPEN_PROJECT_SELECT = 'No,Status,Starting_Date,Ending_Date,Description';
const MONETA_INSTALLMENT_SELECT = 'Job_No,Job_Task_No,Line_No,Line_Type,Description,Document_No,Line_Amount_LCY,Planning_Date,Invoiced_Amount_LCY,Qty_Invoiced,Quantity,LVS_Invoice_Currency_Code';
const MONETA_BASELINE_COST_SELECT = 'Job_No,Job_Task_No,Line_No,Total_Cost_LCY,Remaining_Total_Cost_LCY,Currency_Code,Baseline_Version_in_Filter';
const MONETA_PROJECTPOSTEN_GL_SELECT = 'Job_No,Type,No';
/** BC Type-waarden voor grootboekregels (NL/EN). */
const MONETA_PROJECTPOSTEN_GL_TYPES = [
    'Grootboekrekening',
    'G/L Account',
    'G_L Account',
    'GL Account',
];
const MONETA_UNASSIGNED_ACCOUNT_NO = '__unassigned__';
/** OData-cache tijdens nightly: 5 uur, zodat hertesten snel via cache gaat. */
const MONETA_NIGHTLY_ODATA_TTL = 18000;

require_once __DIR__ . '/moneta_gl.php';
require_once __DIR__ . '/moneta_forecast_user.php';
require_once __DIR__ . '/moneta_charts.php';

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

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS planned_baseline_costs (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            company TEXT NOT NULL,
            job_no TEXT NOT NULL,
            currency_code TEXT NOT NULL DEFAULT \'\',
            amount_lcy REAL NOT NULL,
            period_start TEXT NOT NULL,
            period_end TEXT NOT NULL,
            bank_account_no TEXT NOT NULL DEFAULT \'\',
            refreshed_at TEXT NOT NULL,
            UNIQUE(company, job_no, currency_code)
        )'
    );
    $pdo->exec(
        'CREATE INDEX IF NOT EXISTS idx_planned_baseline_costs_company
         ON planned_baseline_costs (company, period_start, period_end)'
    );

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS job_gl_account_weights (
            company TEXT NOT NULL,
            job_no TEXT NOT NULL,
            account_no TEXT NOT NULL,
            hit_count INTEGER NOT NULL,
            weight REAL NOT NULL,
            refreshed_at TEXT NOT NULL,
            PRIMARY KEY (company, job_no, account_no)
        )'
    );
    $pdo->exec(
        'CREATE INDEX IF NOT EXISTS idx_job_gl_weights_company_job
         ON job_gl_account_weights (company, job_no)'
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
    return date('Y-m-d', strtotime('+1 year'));
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

function moneta_fetch_bank_accounts(string $company, int $ttl = MONETA_NIGHTLY_ODATA_TTL): array
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
    $previousByAccount = [];
    foreach (moneta_bank_balances_as_of($company, $snapshotDate, true) as $previous) {
        $previousByAccount[(string) $previous['account_no']] = $previous;
    }

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
    $deleteUnchanged = $pdo->prepare(
        'DELETE FROM bank_balance_snapshots
         WHERE company = :company
           AND account_no = :account_no
           AND snapshot_date = :snapshot_date'
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

        $balance = (float) ($account['balance_lcy'] ?? 0);
        $currency = moneta_normalize_currency_code((string) ($account['currency_code'] ?? ''));
        $previous = $previousByAccount[$accountNo] ?? null;
        $unchanged = is_array($previous)
            && abs((float) ($previous['balance_lcy'] ?? 0) - $balance) < 0.00001
            && trim((string) ($previous['account_name'] ?? '')) === $accountName
            && moneta_normalize_currency_code((string) ($previous['currency_code'] ?? '')) === $currency;

        if ($unchanged) {
            // Geen nieuw datapunt; ruim eventuele eerdere same-day row op.
            $deleteUnchanged->execute([
                ':company' => $company,
                ':account_no' => $accountNo,
                ':snapshot_date' => $snapshotDate,
            ]);
            continue;
        }

        $statement->execute([
            ':company' => $company,
            ':account_no' => $accountNo,
            ':account_name' => $accountName,
            ':balance_lcy' => $balance,
            ':currency_code' => $currency,
            ':snapshot_date' => $snapshotDate,
            ':created_at' => $createdAt,
        ]);
        $stored++;
    }

    return $stored;
}

function moneta_snapshot_bank_balances_for_company(string $company, string $snapshotDate = '', int $ttl = MONETA_NIGHTLY_ODATA_TTL): array
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
 * Lopende projecten met start-/einddatum voor kostenperiode.
 *
 * @return list<array{job_no: string, starting_date: string, ending_date: string}>
 */
function moneta_fetch_open_projects(string $company, int $ttl = MONETA_NIGHTLY_ODATA_TTL): array
{
    $rows = project_try_fetch_rows($company, 'Projecten', [
        '$select' => MONETA_OPEN_PROJECT_SELECT,
        '$filter' => "Status eq 'Open'",
    ], $ttl);

    if ($rows === []) {
        $rows = project_try_fetch_rows($company, 'AppProjecten', [
            '$select' => 'No,Status,LVS_Starting_Date,LVS_Ending_Date,Description',
            '$filter' => "Status eq 'Open'",
        ], $ttl);
    }

    $today = date('Y-m-d');
    $projects = [];
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }

        $jobNo = trim((string) ($row['No'] ?? ''));
        if ($jobNo === '') {
            continue;
        }

        $endingRaw = trim((string) ($row['Ending_Date'] ?? $row['LVS_Ending_Date'] ?? ''));
        $endingDate = '';
        if (!moneta_is_bc_blank_date($endingRaw)) {
            $endingDate = moneta_parse_date(substr($endingRaw, 0, 10));
            if ($endingDate !== '' && $endingDate < $today) {
                continue;
            }
        }

        $startingRaw = trim((string) ($row['Starting_Date'] ?? $row['LVS_Starting_Date'] ?? ''));
        $startingDate = '';
        if (!moneta_is_bc_blank_date($startingRaw)) {
            $startingDate = moneta_parse_date(substr($startingRaw, 0, 10));
        }

        $projects[$jobNo] = [
            'job_no' => $jobNo,
            'starting_date' => $startingDate,
            'ending_date' => $endingDate,
        ];
    }

    ksort($projects, SORT_NATURAL | SORT_FLAG_CASE);

    return array_values($projects);
}

/**
 * @return list<string>
 */
function moneta_fetch_open_project_nos(string $company, int $ttl = MONETA_NIGHTLY_ODATA_TTL): array
{
    $jobNos = [];
    foreach (moneta_fetch_open_projects($company, $ttl) as $project) {
        $jobNo = trim((string) ($project['job_no'] ?? ''));
        if ($jobNo !== '') {
            $jobNos[] = $jobNo;
        }
    }

    return $jobNos;
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
function moneta_fetch_planned_installments_for_jobs(string $company, array $jobNos, string $dateFrom, int $ttl = MONETA_NIGHTLY_ODATA_TTL): array
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
function moneta_resolve_installment_banks_from_documents(string $company, array $installments, int $ttl = MONETA_NIGHTLY_ODATA_TTL): array
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

function moneta_is_projectposten_gl_type(string $type): bool
{
    $type = trim($type);
    if ($type === '') {
        return false;
    }

    foreach (MONETA_PROJECTPOSTEN_GL_TYPES as $allowed) {
        if (strcasecmp($type, $allowed) === 0) {
            return true;
        }
    }

    return false;
}

function moneta_projectposten_gl_type_filter(): string
{
    $parts = [];
    foreach (MONETA_PROJECTPOSTEN_GL_TYPES as $type) {
        $parts[] = "Type eq '" . project_escape_odata_string($type) . "'";
    }

    return '(' . implode(' or ', $parts) . ')';
}

/**
 * Tel ProjectPosten (Type=grootboekrekening) per project → proportionele gewichten.
 *
 * @param list<string> $jobNos
 * @return array<string, list<array{account_no: string, hit_count: int, weight: float}>>
 */
function moneta_fetch_job_gl_account_weights(string $company, array $jobNos, int $ttl = MONETA_NIGHTLY_ODATA_TTL): array
{
    $wanted = [];
    foreach ($jobNos as $jobNo) {
        $jobNo = trim((string) $jobNo);
        if ($jobNo !== '') {
            $wanted[$jobNo] = true;
        }
    }
    if ($wanted === []) {
        return [];
    }

    $counts = [];
    $typeFilter = moneta_projectposten_gl_type_filter();

    foreach (array_chunk(array_keys($wanted), 10) as $chunk) {
        $jobFilters = [];
        foreach ($chunk as $jobNo) {
            $jobFilters[] = "Job_No eq '" . project_escape_odata_string($jobNo) . "'";
        }

        $rows = project_try_fetch_rows($company, 'ProjectPosten', [
            '$select' => MONETA_PROJECTPOSTEN_GL_SELECT,
            '$filter' => '(' . implode(' or ', $jobFilters) . ') and ' . $typeFilter,
        ], $ttl);

        // Fallback: zonder Type-filter ophalen en in PHP filteren (sommige tenants filteren anders).
        if ($rows === []) {
            $rows = project_try_fetch_rows($company, 'ProjectPosten', [
                '$select' => MONETA_PROJECTPOSTEN_GL_SELECT,
                '$filter' => '(' . implode(' or ', $jobFilters) . ')',
            ], $ttl);
        }

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $jobNo = trim((string) ($row['Job_No'] ?? ''));
            $type = trim((string) ($row['Type'] ?? ''));
            $accountNo = trim((string) ($row['No'] ?? ''));
            if ($jobNo === '' || $accountNo === '' || !isset($wanted[$jobNo])) {
                continue;
            }
            if (!moneta_is_projectposten_gl_type($type)) {
                continue;
            }
            if (!isset($counts[$jobNo])) {
                $counts[$jobNo] = [];
            }
            $counts[$jobNo][$accountNo] = ($counts[$jobNo][$accountNo] ?? 0) + 1;
        }
    }

    $out = [];
    foreach ($counts as $jobNo => $byAccount) {
        $total = array_sum($byAccount);
        if ($total <= 0) {
            continue;
        }
        $rows = [];
        foreach ($byAccount as $accountNo => $hitCount) {
            $rows[] = [
                'account_no' => (string) $accountNo,
                'hit_count' => (int) $hitCount,
                'weight' => ((int) $hitCount) / $total,
            ];
        }
        usort($rows, static function (array $a, array $b): int {
            if ($a['hit_count'] !== $b['hit_count']) {
                return $b['hit_count'] <=> $a['hit_count'];
            }

            return strnatcasecmp((string) $a['account_no'], (string) $b['account_no']);
        });
        $out[$jobNo] = $rows;
    }

    return $out;
}

/**
 * @param array<string, list<array{account_no: string, hit_count: int, weight: float}>> $weightsByJob
 */
function moneta_store_job_gl_account_weights(string $company, array $weightsByJob): int
{
    $pdo = moneta_pdo();
    $pdo->beginTransaction();
    try {
        $pdo->prepare('DELETE FROM job_gl_account_weights WHERE company = :company')
            ->execute([':company' => $company]);

        $insert = $pdo->prepare(
            'INSERT INTO job_gl_account_weights
                (company, job_no, account_no, hit_count, weight, refreshed_at)
             VALUES
                (:company, :job_no, :account_no, :hit_count, :weight, :refreshed_at)'
        );

        $refreshedAt = gmdate('c');
        $stored = 0;
        foreach ($weightsByJob as $jobNo => $rows) {
            $jobNo = trim((string) $jobNo);
            if ($jobNo === '' || !is_array($rows)) {
                continue;
            }
            foreach ($rows as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $accountNo = trim((string) ($row['account_no'] ?? ''));
                $hitCount = (int) ($row['hit_count'] ?? 0);
                $weight = (float) ($row['weight'] ?? 0);
                if ($accountNo === '' || $hitCount <= 0 || $weight <= 0) {
                    continue;
                }
                $insert->execute([
                    ':company' => $company,
                    ':job_no' => $jobNo,
                    ':account_no' => $accountNo,
                    ':hit_count' => $hitCount,
                    ':weight' => $weight,
                    ':refreshed_at' => $refreshedAt,
                ]);
                $stored++;
            }
        }

        $pdo->commit();

        return $stored;
    } catch (Throwable $error) {
        $pdo->rollBack();
        throw $error;
    }
}

function moneta_snapshot_job_gl_account_weights_for_company(
    string $company,
    ?array $jobNos = null,
    int $ttl = MONETA_NIGHTLY_ODATA_TTL
): array {
    if ($jobNos === null) {
        $jobNos = [];
        foreach (moneta_fetch_open_projects($company, $ttl) as $project) {
            $jobNo = trim((string) ($project['job_no'] ?? ''));
            if ($jobNo !== '') {
                $jobNos[] = $jobNo;
            }
        }
    }

    $weights = moneta_fetch_job_gl_account_weights($company, $jobNos, $ttl);
    $stored = moneta_store_job_gl_account_weights($company, $weights);

    return [
        'company' => $company,
        'jobs' => count($jobNos),
        'jobs_with_weights' => count($weights),
        'stored' => $stored,
    ];
}

/**
 * @param list<string> $jobNos
 * @return array<string, list<array{account_no: string, hit_count: int, weight: float}>>
 */
function moneta_load_job_gl_account_weights(string $company, array $jobNos = []): array
{
    $pdo = moneta_pdo();
    $wanted = [];
    foreach ($jobNos as $jobNo) {
        $jobNo = trim((string) $jobNo);
        if ($jobNo !== '') {
            $wanted[$jobNo] = true;
        }
    }

    if ($wanted === []) {
        $statement = $pdo->prepare(
            'SELECT job_no, account_no, hit_count, weight
             FROM job_gl_account_weights
             WHERE company = :company
             ORDER BY job_no ASC, hit_count DESC, account_no ASC'
        );
        $statement->execute([':company' => $company]);
    } else {
        $jobList = array_keys($wanted);
        $placeholders = implode(',', array_fill(0, count($jobList), '?'));
        $statement = $pdo->prepare(
            'SELECT job_no, account_no, hit_count, weight
             FROM job_gl_account_weights
             WHERE company = ?
               AND job_no IN (' . $placeholders . ')
             ORDER BY job_no ASC, hit_count DESC, account_no ASC'
        );
        $statement->execute(array_merge([$company], $jobList));
    }

    $out = [];
    foreach ($statement->fetchAll() as $row) {
        $jobNo = trim((string) ($row['job_no'] ?? ''));
        $accountNo = trim((string) ($row['account_no'] ?? ''));
        if ($jobNo === '' || $accountNo === '') {
            continue;
        }
        $out[$jobNo][] = [
            'account_no' => $accountNo,
            'hit_count' => (int) ($row['hit_count'] ?? 0),
            'weight' => (float) ($row['weight'] ?? 0),
        ];
    }

    return $out;
}

/**
 * Grootboekrekening → grafiekgroep (eerste groep op sort_order).
 *
 * @return array<string, array{account_no: string, name: string}>
 */
function moneta_gl_account_to_group_map(string $company): array
{
    $map = [];
    foreach (moneta_list_chart_groups($company) as $group) {
        $groupId = (int) ($group['id'] ?? 0);
        if ($groupId <= 0) {
            continue;
        }
        $target = [
            'account_no' => 'group:' . $groupId,
            'name' => (string) ($group['name'] ?? ''),
        ];
        foreach ($group['accounts'] as $account) {
            $accountNo = trim((string) ($account['account_no'] ?? ''));
            if ($accountNo === '' || isset($map[$accountNo])) {
                continue;
            }
            $map[$accountNo] = $target;
        }
    }

    return $map;
}

/**
 * Verdeel een bedrag over groepen o.b.v. ProjectPosten-gewichten.
 *
 * @param array<string, list<array{account_no: string, hit_count: int, weight: float}>> $weightsByJob
 * @param array<string, array{account_no: string, name: string}> $glToGroup
 * @return list<array{account_no: string, name: string, amount: float}>
 */
function moneta_allocate_amount_by_job_gl_weights(
    string $jobNo,
    float $amount,
    array $weightsByJob,
    array $glToGroup
): array {
    if (abs($amount) < 0.000001) {
        return [];
    }

    $weights = $weightsByJob[$jobNo] ?? [];
    if ($weights === []) {
        return [[
            'account_no' => MONETA_UNASSIGNED_ACCOUNT_NO,
            'name' => 'Niet toegewezen',
            'amount' => $amount,
        ]];
    }

    $buckets = [];
    foreach ($weights as $row) {
        $glAccount = trim((string) ($row['account_no'] ?? ''));
        $weight = (float) ($row['weight'] ?? 0);
        if ($glAccount === '' || $weight <= 0) {
            continue;
        }
        $share = $amount * $weight;
        if (abs($share) < 0.0000001) {
            continue;
        }
        if (isset($glToGroup[$glAccount])) {
            $key = $glToGroup[$glAccount]['account_no'];
            $name = $glToGroup[$glAccount]['name'];
        } else {
            $key = MONETA_UNASSIGNED_ACCOUNT_NO;
            $name = 'Niet toegewezen';
        }
        if (!isset($buckets[$key])) {
            $buckets[$key] = [
                'account_no' => $key,
                'name' => $name,
                'amount' => 0.0,
            ];
        }
        $buckets[$key]['amount'] += $share;
    }

    if ($buckets === []) {
        return [[
            'account_no' => MONETA_UNASSIGNED_ACCOUNT_NO,
            'name' => 'Niet toegewezen',
            'amount' => $amount,
        ]];
    }

    return array_values($buckets);
}

function moneta_snapshot_planned_installments_for_company(string $company, string $fromDate = '', int $ttl = MONETA_NIGHTLY_ODATA_TTL): array
{
    $fromDate = moneta_parse_date($fromDate);
    if ($fromDate === '') {
        $fromDate = date('Y-m-d');
    }

    $openProjects = moneta_fetch_open_projects($company, $ttl);
    $openJobs = [];
    foreach ($openProjects as $project) {
        $jobNo = trim((string) ($project['job_no'] ?? ''));
        if ($jobNo !== '') {
            $openJobs[] = $jobNo;
        }
    }

    $weightSnapshot = moneta_snapshot_job_gl_account_weights_for_company($company, $openJobs, $ttl);
    $installments = moneta_fetch_planned_installments_for_jobs($company, $openJobs, $fromDate, $ttl);
    // bank_account_no blijft leeg: toewijzing gebeurt in de prognose via ProjectPosten-gewichten.
    $stored = moneta_store_planned_installments($company, $installments);

    return [
        'company' => $company,
        'open_projects' => count($openJobs),
        'installments' => count($installments),
        'stored' => $stored,
        'projects' => $openProjects,
        'job_gl_weights' => $weightSnapshot,
    ];
}

/**
 * Werkelijk geboekte kosten per project (FinRap Booked_Cost = JobLedgerEntries.Total_Cost_LCY).
 *
 * @param list<string> $jobNos
 * @return array<string, float> job_no => booked_cost_lcy
 */
function moneta_fetch_booked_cost_lcy_by_job(string $company, array $jobNos, int $ttl = MONETA_NIGHTLY_ODATA_TTL): array
{
    $wanted = [];
    foreach ($jobNos as $jobNo) {
        $jobNo = trim((string) $jobNo);
        if ($jobNo !== '') {
            $wanted[$jobNo] = true;
        }
    }
    if ($wanted === []) {
        return [];
    }

    $booked = [];
    foreach (array_chunk(array_keys($wanted), 12) as $chunk) {
        $filters = [];
        foreach ($chunk as $jobNo) {
            $filters[] = "Job_No eq '" . project_escape_odata_string($jobNo) . "'";
        }

        $rows = project_try_fetch_rows($company, 'JobLedgerEntries', [
            '$select' => 'Job_No,Total_Cost_LCY',
            '$filter' => '(' . implode(' or ', $filters) . ') and Total_Cost_LCY ne 0',
        ], $ttl);

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $jobNo = trim((string) ($row['Job_No'] ?? ''));
            if ($jobNo === '' || !isset($wanted[$jobNo])) {
                continue;
            }
            $booked[$jobNo] = ($booked[$jobNo] ?? 0.0) + (float) ($row['Total_Cost_LCY'] ?? 0);
        }
    }

    return $booked;
}

/**
 * Kosten uit JobBaselineLines (voorcalculatie), verminderd met geboekte JobLedgerEntries.
 * Datum: lineair van max(projectstart, vandaag) tot projecteinde.
 * Toewijzing aan groepen gebeurt later via ProjectPosten-gewichten (job_no).
 *
 * @param list<array{job_no: string, starting_date: string, ending_date: string}> $openProjects
 * @return list<array<string, mixed>>
 */
function moneta_fetch_baseline_costs_for_projects(
    string $company,
    array $openProjects,
    string $asOfDate,
    int $ttl = MONETA_NIGHTLY_ODATA_TTL
): array {
    $asOfDate = moneta_parse_date($asOfDate);
    if ($asOfDate === '') {
        $asOfDate = date('Y-m-d');
    }
    if ($openProjects === []) {
        return [];
    }

    $projectMap = [];
    foreach ($openProjects as $project) {
        $jobNo = trim((string) ($project['job_no'] ?? ''));
        if ($jobNo === '') {
            continue;
        }
        $projectMap[$jobNo] = $project;
    }
    if ($projectMap === []) {
        return [];
    }

    // Schedule_Line-filter kan in BC paginatie laten crashen; FinRap gebruikt Baseline_Version_in_Filter.
    $rows = project_try_fetch_rows($company, 'JobBaselineLines', [
        '$select' => MONETA_BASELINE_COST_SELECT,
        '$filter' => 'Baseline_Version_in_Filter eq true and Total_Cost_LCY ne 0',
    ], $ttl);

    $totals = [];
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }

        $jobNo = trim((string) ($row['Job_No'] ?? ''));
        if ($jobNo === '' || !isset($projectMap[$jobNo])) {
            continue;
        }

        // Voorcalculatie = Total_Cost_LCY; Remaining gebruiken we niet (dat regelen we via geboekte kosten).
        $amount = (float) ($row['Total_Cost_LCY'] ?? 0);
        if (abs($amount) < 0.000001) {
            continue;
        }

        $currency = moneta_normalize_currency_code((string) ($row['Currency_Code'] ?? ''));
        $key = $jobNo . "\0" . $currency;
        if (!isset($totals[$key])) {
            $totals[$key] = [
                'job_no' => $jobNo,
                'currency_code' => $currency,
                'amount_lcy' => 0.0,
            ];
        }
        $totals[$key]['amount_lcy'] += $amount;
    }

    $jobBaselineTotals = [];
    foreach ($totals as $row) {
        $jobNo = (string) $row['job_no'];
        $jobBaselineTotals[$jobNo] = ($jobBaselineTotals[$jobNo] ?? 0.0) + (float) $row['amount_lcy'];
    }

    $bookedByJob = moneta_fetch_booked_cost_lcy_by_job($company, array_keys($jobBaselineTotals), $ttl);

    $costs = [];
    foreach ($totals as $totalRow) {
        $jobNo = (string) $totalRow['job_no'];
        $jobBaseline = (float) ($jobBaselineTotals[$jobNo] ?? 0);
        if ($jobBaseline < 0.000001) {
            continue;
        }

        $booked = (float) ($bookedByJob[$jobNo] ?? 0);
        $remainingJob = $jobBaseline - $booked;
        if ($remainingJob <= 0.000001) {
            continue;
        }

        $amount = (float) $totalRow['amount_lcy'] * ($remainingJob / $jobBaseline);
        if (abs($amount) < 0.000001) {
            continue;
        }

        $project = $projectMap[$jobNo];
        $startingDate = moneta_parse_date((string) ($project['starting_date'] ?? ''));
        $endingDate = moneta_parse_date((string) ($project['ending_date'] ?? ''));

        $periodStart = $startingDate !== '' && $startingDate > $asOfDate ? $startingDate : $asOfDate;
        if ($endingDate !== '') {
            $periodEnd = $endingDate;
        } else {
            // Geen einddatum: spreid over het komende jaar i.p.v. alles op één dag.
            $periodEnd = date('Y-m-d', strtotime($periodStart . ' +1 year'));
        }
        if ($periodEnd < $periodStart) {
            $periodEnd = $periodStart;
        }

        $costs[] = [
            'job_no' => $jobNo,
            'currency_code' => (string) $totalRow['currency_code'],
            'amount_lcy' => $amount,
            'period_start' => $periodStart,
            'period_end' => $periodEnd,
            'bank_account_no' => '',
            'baseline_lcy' => $jobBaseline,
            'booked_lcy' => $booked,
        ];
    }

    return $costs;
}

function moneta_store_baseline_costs(string $company, array $costs): int
{
    $pdo = moneta_pdo();
    $pdo->beginTransaction();
    try {
        $delete = $pdo->prepare('DELETE FROM planned_baseline_costs WHERE company = :company');
        $delete->execute([':company' => $company]);

        $insert = $pdo->prepare(
            'INSERT INTO planned_baseline_costs
                (company, job_no, currency_code, amount_lcy, period_start, period_end, bank_account_no, refreshed_at)
             VALUES
                (:company, :job_no, :currency_code, :amount_lcy, :period_start, :period_end, :bank_account_no, :refreshed_at)
             ON CONFLICT(company, job_no, currency_code) DO UPDATE SET
                amount_lcy = excluded.amount_lcy,
                period_start = excluded.period_start,
                period_end = excluded.period_end,
                bank_account_no = excluded.bank_account_no,
                refreshed_at = excluded.refreshed_at'
        );

        $refreshedAt = gmdate('c');
        $stored = 0;
        foreach ($costs as $row) {
            if (!is_array($row)) {
                continue;
            }

            $jobNo = trim((string) ($row['job_no'] ?? ''));
            $periodStart = moneta_parse_date((string) ($row['period_start'] ?? ''));
            $periodEnd = moneta_parse_date((string) ($row['period_end'] ?? ''));
            $amount = (float) ($row['amount_lcy'] ?? 0);
            if ($jobNo === '' || $periodStart === '' || $periodEnd === '' || abs($amount) < 0.000001) {
                continue;
            }

            $insert->execute([
                ':company' => $company,
                ':job_no' => $jobNo,
                ':currency_code' => moneta_normalize_currency_code((string) ($row['currency_code'] ?? '')),
                ':amount_lcy' => $amount,
                ':period_start' => $periodStart,
                ':period_end' => $periodEnd,
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

function moneta_snapshot_baseline_costs_for_company(
    string $company,
    string $asOfDate = '',
    int $ttl = MONETA_NIGHTLY_ODATA_TTL,
    ?array $openProjects = null
): array {
    $asOfDate = moneta_parse_date($asOfDate);
    if ($asOfDate === '') {
        $asOfDate = date('Y-m-d');
    }

    if ($openProjects === null) {
        $openProjects = moneta_fetch_open_projects($company, $ttl);
    }

    $costs = moneta_fetch_baseline_costs_for_projects($company, $openProjects, $asOfDate, $ttl);
    $stored = moneta_store_baseline_costs($company, $costs);

    return [
        'company' => $company,
        'open_projects' => count($openProjects),
        'cost_groups' => count($costs),
        'stored' => $stored,
    ];
}

function moneta_run_nightly_jobs(string $snapshotDate = '', int $ttl = MONETA_NIGHTLY_ODATA_TTL): array
{
    $snapshotDate = moneta_parse_date($snapshotDate);
    if ($snapshotDate === '') {
        $snapshotDate = date('Y-m-d');
    }
    $ttl = max(MONETA_NIGHTLY_ODATA_TTL, (int) $ttl);

    $companies = project_companies_for_page($ttl);
    $glResults = [];
    $installmentResults = [];
    $costResults = [];
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
            continue;
        }

        if (PHP_SAPI === 'cli') {
            echo '[' . date('H:i:s') . "] installments: {$company}\n";
        }

        $installmentSnapshot = null;
        try {
            $installmentSnapshot = moneta_snapshot_planned_installments_for_company($company, $snapshotDate, $ttl);
            $installmentResults[] = [
                'company' => $installmentSnapshot['company'],
                'open_projects' => $installmentSnapshot['open_projects'],
                'installments' => $installmentSnapshot['installments'],
                'stored' => $installmentSnapshot['stored'],
                'job_gl_weights' => $installmentSnapshot['job_gl_weights'] ?? null,
            ];
        } catch (Throwable $error) {
            $errors[] = [
                'company' => $company,
                'step' => 'installments',
                'error' => $error->getMessage(),
            ];
        }

        if (PHP_SAPI === 'cli') {
            echo '[' . date('H:i:s') . "] baseline costs: {$company}\n";
        }

        try {
            $costResults[] = moneta_snapshot_baseline_costs_for_company(
                $company,
                $snapshotDate,
                $ttl,
                is_array($installmentSnapshot['projects'] ?? null) ? $installmentSnapshot['projects'] : null
            );
        } catch (Throwable $error) {
            $errors[] = [
                'company' => $company,
                'step' => 'baseline_costs',
                'error' => $error->getMessage(),
            ];
        }
    }

    return [
        'snapshot_date' => $snapshotDate,
        'gl' => $glResults,
        'bank' => $glResults, // backwards compat for oude callers
        'installments' => $installmentResults,
        'baseline_costs' => $costResults,
        'errors' => $errors,
    ];
}

/**
 * @deprecated Gebruik moneta_run_nightly_jobs
 */
function moneta_run_nightly_bank_snapshots(string $snapshotDate = '', int $ttl = MONETA_NIGHTLY_ODATA_TTL): array
{
    $run = moneta_run_nightly_jobs($snapshotDate, $ttl);

    return [
        'snapshot_date' => $run['snapshot_date'],
        'results' => $run['gl'] ?? $run['bank'] ?? [],
        'errors' => $run['errors'],
    ];
}

/**
 * Bouwt chart-klare series uit SQLite (geen live BC-calls).
 * Ontbrekende dagen krijgen de laatst bekende eerdere saldo-waarde (carry-forward).
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
    // Inclusief eerdere snapshots vóór dateFrom, zodat gaps vanaf de startdatum gevuld kunnen worden.
    $statement = $pdo->prepare(
        'SELECT account_no, account_name, balance_lcy, snapshot_date
         FROM bank_balance_snapshots
         WHERE company = :company
           AND snapshot_date <= :date_to
         ORDER BY snapshot_date ASC, account_name COLLATE NOCASE ASC'
    );
    $statement->execute([
        ':company' => $company,
        ':date_to' => $dateTo,
    ]);
    $rows = $statement->fetchAll();

    if ($rows === []) {
        return ['labels' => [], 'series' => []];
    }

    $accounts = [];
    foreach ($rows as $row) {
        $date = moneta_parse_date((string) ($row['snapshot_date'] ?? ''));
        $accountNo = trim((string) ($row['account_no'] ?? ''));
        if ($date === '' || $accountNo === '') {
            continue;
        }

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

    if ($accounts === []) {
        return ['labels' => [], 'series' => []];
    }

    $labels = [];
    $cursor = new DateTimeImmutable($dateFrom);
    $end = new DateTimeImmutable($dateTo);
    while ($cursor <= $end) {
        $labels[] = $cursor->format('Y-m-d');
        $cursor = $cursor->modify('+1 day');
    }

    uasort($accounts, static function (array $a, array $b): int {
        return strcasecmp((string) $a['name'], (string) $b['name']);
    });

    $series = [];
    foreach ($accounts as $account) {
        $pointDates = array_keys($account['points']);
        sort($pointDates);
        $pointIndex = 0;
        $pointCount = count($pointDates);
        $lastKnown = null;
        $data = [];
        $hasValueInRange = false;

        foreach ($labels as $label) {
            while ($pointIndex < $pointCount && $pointDates[$pointIndex] <= $label) {
                $lastKnown = (float) $account['points'][$pointDates[$pointIndex]];
                $pointIndex++;
            }
            if ($lastKnown === null) {
                $data[] = null;
            } else {
                $data[] = $lastKnown;
                $hasValueInRange = true;
            }
        }

        if (!$hasValueInRange) {
            continue;
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
 * Laatste bekende saldo per rekening op/vóór $asOfDate (sparse snapshots).
 *
 * @return list<array{account_no: string, account_name: string, balance_lcy: float, currency_code: string, snapshot_date?: string}>
 */
function moneta_bank_balances_as_of(string $company, string $asOfDate = '', bool $strictBefore = false): array
{
    $asOfDate = moneta_parse_date($asOfDate);
    if ($asOfDate === '') {
        $asOfDate = date('Y-m-d');
    }

    $pdo = moneta_pdo();
    $operator = $strictBefore ? '<' : '<=';
    $statement = $pdo->prepare(
        'SELECT b.account_no, b.account_name, b.balance_lcy, b.currency_code, b.snapshot_date
         FROM bank_balance_snapshots b
         INNER JOIN (
            SELECT account_no, MAX(snapshot_date) AS snapshot_date
            FROM bank_balance_snapshots
            WHERE company = :company
              AND snapshot_date ' . $operator . ' :as_of
            GROUP BY account_no
         ) latest
           ON latest.account_no = b.account_no
          AND latest.snapshot_date = b.snapshot_date
         WHERE b.company = :company2
         ORDER BY b.account_name COLLATE NOCASE ASC'
    );
    $statement->execute([
        ':company' => $company,
        ':as_of' => $asOfDate,
        ':company2' => $company,
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
            'snapshot_date' => moneta_parse_date((string) ($row['snapshot_date'] ?? '')),
        ];
    }

    return $accounts;
}

/**
 * @return list<array{account_no: string, account_name: string, balance_lcy: float, currency_code: string}>
 */
function moneta_latest_bank_balances(string $company, string $asOfDate = ''): array
{
    return moneta_bank_balances_as_of($company, $asOfDate, false);
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
 * @return list<array<string, mixed>>
 */
function moneta_load_baseline_costs(string $company, string $dateFrom, string $dateTo): array
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
        'SELECT job_no, currency_code, amount_lcy, period_start, period_end, bank_account_no
         FROM planned_baseline_costs
         WHERE company = :company
           AND period_start <= :date_to
           AND period_end >= :date_from
         ORDER BY period_start ASC, job_no ASC'
    );
    $statement->execute([
        ':company' => $company,
        ':date_from' => $dateFrom,
        ':date_to' => $dateTo,
    ]);

    return $statement->fetchAll();
}

function moneta_inclusive_day_count(string $dateFrom, string $dateTo): int
{
    $dateFrom = moneta_parse_date($dateFrom);
    $dateTo = moneta_parse_date($dateTo);
    if ($dateFrom === '' || $dateTo === '' || $dateFrom > $dateTo) {
        return 0;
    }

    $start = new DateTimeImmutable($dateFrom);
    $end = new DateTimeImmutable($dateTo);

    return ((int) $start->diff($end)->days) + 1;
}

/**
 * Verdeel bedrag lineair over dagen in overlap van periode en forecast-range.
 *
 * @return array<string, float> date => amount
 */
function moneta_linear_amount_by_date(
    float $amount,
    string $periodStart,
    string $periodEnd,
    string $rangeFrom,
    string $rangeTo
): array {
    $periodStart = moneta_parse_date($periodStart);
    $periodEnd = moneta_parse_date($periodEnd);
    $rangeFrom = moneta_parse_date($rangeFrom);
    $rangeTo = moneta_parse_date($rangeTo);
    if ($periodStart === '' || $periodEnd === '' || $rangeFrom === '' || $rangeTo === '') {
        return [];
    }

    $fullDays = moneta_inclusive_day_count($periodStart, $periodEnd);
    if ($fullDays <= 0 || abs($amount) < 0.000001) {
        return [];
    }

    $overlapStart = max($periodStart, $rangeFrom);
    $overlapEnd = min($periodEnd, $rangeTo);
    if ($overlapStart > $overlapEnd) {
        return [];
    }

    $daily = $amount / $fullDays;
    $out = [];
    $cursor = new DateTimeImmutable($overlapStart);
    $end = new DateTimeImmutable($overlapEnd);
    while ($cursor <= $end) {
        $key = $cursor->format('Y-m-d');
        $out[$key] = $daily;
        $cursor = $cursor->modify('+1 day');
    }

    return $out;
}

/**
 * Prognose: startsaldi groepen + termijnfacturen (in) − basislijnkosten (uit).
 *
 * Kostenbron: JobBaselineLines.Total_Cost_LCY − JobLedgerEntries.Total_Cost_LCY.
 * Toewijzing: ProjectPosten Type=grootboekrekening → proportioneel over grootboeknummers,
 * daarna gemapt naar grafiekgroepen.
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

    $accounts = moneta_latest_group_balances($company, $dateFrom);
    $installments = moneta_load_planned_installments($company, $dateFrom, $dateTo);
    $baselineCosts = moneta_load_baseline_costs($company, $dateFrom, $dateTo);

    if ($accounts === [] && $installments === [] && $baselineCosts === []) {
        return [
            'labels' => [],
            'series' => [],
            'meta' => [
                'installment_count' => 0,
                'installment_total' => 0.0,
                'cost_count' => 0,
                'cost_total' => 0.0,
                'unassigned_count' => 0,
            ],
        ];
    }

    $jobNos = [];
    foreach ($installments as $row) {
        $jobNo = trim((string) ($row['job_no'] ?? ''));
        if ($jobNo !== '') {
            $jobNos[$jobNo] = true;
        }
    }
    foreach ($baselineCosts as $row) {
        $jobNo = trim((string) ($row['job_no'] ?? ''));
        if ($jobNo !== '') {
            $jobNos[$jobNo] = true;
        }
    }

    $weightsByJob = moneta_load_job_gl_account_weights($company, array_keys($jobNos));
    $glToGroup = moneta_gl_account_to_group_map($company);

    $accountMap = [];
    foreach ($accounts as $account) {
        $accountMap[$account['account_no']] = [
            'account_no' => $account['account_no'],
            'name' => $account['account_name'],
            'start' => (float) $account['balance_lcy'],
            'movements' => [],
        ];
    }

    $unassignedCount = 0;
    $installmentTotal = 0.0;
    $costTotal = 0.0;
    $eventDates = [$dateFrom => true];

    $ensureSeries = static function (string $accountNo, string $name) use (&$accountMap): void {
        if (isset($accountMap[$accountNo])) {
            return;
        }
        $accountMap[$accountNo] = [
            'account_no' => $accountNo,
            'name' => $name,
            'start' => 0.0,
            'movements' => [],
        ];
    };

    $applyAllocations = static function (array $allocations, string $day, float $sign) use (
        &$accountMap,
        &$eventDates,
        $ensureSeries
    ): bool {
        $touchedUnassigned = false;
        foreach ($allocations as $allocation) {
            $accountNo = (string) ($allocation['account_no'] ?? '');
            $name = (string) ($allocation['name'] ?? $accountNo);
            $amount = (float) ($allocation['amount'] ?? 0) * $sign;
            if ($accountNo === '' || abs($amount) < 0.0000001) {
                continue;
            }
            $ensureSeries($accountNo, $name);
            if ($accountNo === MONETA_UNASSIGNED_ACCOUNT_NO) {
                $touchedUnassigned = true;
            }
            if (!isset($accountMap[$accountNo]['movements'][$day])) {
                $accountMap[$accountNo]['movements'][$day] = 0.0;
            }
            $accountMap[$accountNo]['movements'][$day] += $amount;
            $eventDates[$day] = true;
        }

        return $touchedUnassigned;
    };

    foreach ($installments as $row) {
        $planningDate = moneta_parse_date((string) ($row['planning_date'] ?? ''));
        $amount = (float) ($row['amount_lcy'] ?? 0);
        if ($planningDate === '' || abs($amount) < 0.000001) {
            continue;
        }

        $jobNo = trim((string) ($row['job_no'] ?? ''));
        $allocations = moneta_allocate_amount_by_job_gl_weights($jobNo, $amount, $weightsByJob, $glToGroup);
        if ($applyAllocations($allocations, $planningDate, 1.0)) {
            $unassignedCount++;
        }
        $installmentTotal += $amount;
    }

    foreach ($baselineCosts as $row) {
        $amount = (float) ($row['amount_lcy'] ?? 0);
        if (abs($amount) < 0.000001) {
            continue;
        }

        $dailyAmounts = moneta_linear_amount_by_date(
            $amount,
            (string) ($row['period_start'] ?? ''),
            (string) ($row['period_end'] ?? ''),
            $dateFrom,
            $dateTo
        );
        if ($dailyAmounts === []) {
            continue;
        }

        $jobNo = trim((string) ($row['job_no'] ?? ''));
        $rowUnassigned = false;
        foreach ($dailyAmounts as $day => $dailyAmount) {
            $allocations = moneta_allocate_amount_by_job_gl_weights($jobNo, $dailyAmount, $weightsByJob, $glToGroup);
            // Kosten verlagen het saldo.
            if ($applyAllocations($allocations, $day, -1.0)) {
                $rowUnassigned = true;
            }
        }
        if ($rowUnassigned) {
            $unassignedCount++;
        }
        $costTotal += $amount;
    }

    $labels = array_keys($eventDates);
    sort($labels);

    // Bij dichte lineaire kosten: houd labels werkbaar (start + mutaties + maandultimo's + eind).
    if (count($labels) > 120) {
        $keep = [$dateFrom => true, $dateTo => true];
        foreach ($installments as $row) {
            $planningDate = moneta_parse_date((string) ($row['planning_date'] ?? ''));
            if ($planningDate !== '') {
                $keep[$planningDate] = true;
            }
        }
        foreach ($labels as $label) {
            if (substr($label, -2) === '01' || $label === $dateFrom || $label === $dateTo) {
                $keep[$label] = true;
            }
        }
        $index = 0;
        foreach ($labels as $label) {
            if ($index % 7 === 0) {
                $keep[$label] = true;
            }
            $index++;
        }
        $labels = array_keys($keep);
        sort($labels);
    }

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
        if (($account['movements'] ?? []) === []) {
            continue;
        }

        $running = (float) $account['start'];
        $runningByDate = [];
        $movementDates = array_keys($account['movements']);
        sort($movementDates);
        foreach ($movementDates as $day) {
            $running += (float) ($account['movements'][$day] ?? 0);
            $runningByDate[$day] = $running;
        }

        $data = [];
        $lastKnown = (float) $account['start'];
        $moveIndex = 0;
        $moveCount = count($movementDates);
        foreach ($labels as $label) {
            while ($moveIndex < $moveCount && $movementDates[$moveIndex] <= $label) {
                $lastKnown = $runningByDate[$movementDates[$moveIndex]];
                $moveIndex++;
            }
            $data[] = $lastKnown;
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
                'cost_count' => count($baselineCosts),
                'cost_total' => round($costTotal, 2),
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
            'cost_count' => count($baselineCosts),
            'cost_total' => round($costTotal, 2),
            'unassigned_count' => $unassignedCount,
        ],
    ];
}

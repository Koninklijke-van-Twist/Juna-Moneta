<?php

/**
 * Rekeningschema (G_L_Account) snapshots + grafiekgroepen.
 */

const MONETA_GL_SELECT = 'No,Name,Balance_at_Date,Account_Type';
const MONETA_SCHEMA_VERSION = 3;

function moneta_meta_get(PDO $pdo, string $key, string $default = ''): string
{
    $statement = $pdo->prepare('SELECT value FROM app_meta WHERE key = :key LIMIT 1');
    $statement->execute([':key' => $key]);
    $row = $statement->fetch();

    return is_array($row) ? (string) ($row['value'] ?? $default) : $default;
}

function moneta_meta_set(PDO $pdo, string $key, string $value): void
{
    $statement = $pdo->prepare(
        'INSERT INTO app_meta (key, value) VALUES (:key, :value)
         ON CONFLICT(key) DO UPDATE SET value = excluded.value'
    );
    $statement->execute([':key' => $key, ':value' => $value]);
}

function moneta_ensure_gl_schema(PDO $pdo): void
{
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS app_meta (
            key TEXT PRIMARY KEY,
            value TEXT NOT NULL
        )'
    );

    $version = (int) moneta_meta_get($pdo, 'schema_version', '1');
    if ($version < MONETA_SCHEMA_VERSION) {
        // Oude banksaldi weg: bron is nu Rekeningschema.
        $pdo->exec('DROP TABLE IF EXISTS bank_balance_snapshots');
        moneta_meta_set($pdo, 'schema_version', (string) MONETA_SCHEMA_VERSION);
    }

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS gl_accounts (
            company TEXT NOT NULL,
            account_no TEXT NOT NULL,
            account_name TEXT NOT NULL DEFAULT \'\',
            account_type TEXT NOT NULL DEFAULT \'\',
            updated_at TEXT NOT NULL,
            PRIMARY KEY (company, account_no)
        )'
    );

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS gl_balance_snapshots (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            company TEXT NOT NULL,
            account_no TEXT NOT NULL,
            balance REAL NOT NULL,
            snapshot_date TEXT NOT NULL,
            created_at TEXT NOT NULL,
            UNIQUE(company, account_no, snapshot_date)
        )'
    );
    $pdo->exec(
        'CREATE INDEX IF NOT EXISTS idx_gl_balance_company_date
         ON gl_balance_snapshots (company, snapshot_date)'
    );

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS chart_groups (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            company TEXT NOT NULL,
            name TEXT NOT NULL,
            sort_order INTEGER NOT NULL DEFAULT 0,
            created_at TEXT NOT NULL,
            updated_at TEXT NOT NULL
        )'
    );
    $pdo->exec(
        'CREATE INDEX IF NOT EXISTS idx_chart_groups_company
         ON chart_groups (company, sort_order, id)'
    );

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS chart_group_accounts (
            group_id INTEGER NOT NULL,
            account_no TEXT NOT NULL,
            PRIMARY KEY (group_id, account_no),
            FOREIGN KEY (group_id) REFERENCES chart_groups(id) ON DELETE CASCADE
        )'
    );
}

/**
 * @return list<array{account_no: string, account_name: string, account_type: string, balance: float}>
 */
function moneta_fetch_gl_accounts_for_date(string $company, string $asOfDate, int $ttl = MONETA_NIGHTLY_ODATA_TTL): array
{
    $asOfDate = moneta_parse_date($asOfDate);
    if ($asOfDate === '') {
        throw new InvalidArgumentException('Ongeldige datum voor G_L_Account Date_Filter.');
    }

    $rows = project_fetch_rows($company, 'G_L_Account', [
        '$select' => MONETA_GL_SELECT,
        '$filter' => "Date_Filter eq '.." . $asOfDate . "'",
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
            'account_type' => trim((string) ($row['Account_Type'] ?? '')),
            'balance' => (float) ($row['Balance_at_Date'] ?? 0),
        ];
    }

    usort($accounts, static function (array $a, array $b): int {
        return strnatcasecmp((string) $a['account_no'], (string) $b['account_no']);
    });

    return $accounts;
}

/**
 * @return array<string, array{account_no: string, balance: float}>
 */
function moneta_gl_balances_as_of(string $company, string $asOfDate, bool $strictBefore = false): array
{
    $asOfDate = moneta_parse_date($asOfDate);
    if ($asOfDate === '') {
        $asOfDate = date('Y-m-d');
    }

    $pdo = moneta_pdo();
    $operator = $strictBefore ? '<' : '<=';
    $statement = $pdo->prepare(
        'SELECT b.account_no, b.balance, b.snapshot_date
         FROM gl_balance_snapshots b
         INNER JOIN (
            SELECT account_no, MAX(snapshot_date) AS snapshot_date
            FROM gl_balance_snapshots
            WHERE company = :company
              AND snapshot_date ' . $operator . ' :as_of
            GROUP BY account_no
         ) latest
           ON latest.account_no = b.account_no
          AND latest.snapshot_date = b.snapshot_date
         WHERE b.company = :company2'
    );
    $statement->execute([
        ':company' => $company,
        ':as_of' => $asOfDate,
        ':company2' => $company,
    ]);

    $out = [];
    foreach ($statement->fetchAll() as $row) {
        $accountNo = trim((string) ($row['account_no'] ?? ''));
        if ($accountNo === '') {
            continue;
        }
        $out[$accountNo] = [
            'account_no' => $accountNo,
            'balance' => (float) ($row['balance'] ?? 0),
            'snapshot_date' => (string) ($row['snapshot_date'] ?? ''),
        ];
    }

    return $out;
}

function moneta_store_gl_snapshot(string $company, string $snapshotDate, array $accounts): int
{
    $snapshotDate = moneta_parse_date($snapshotDate);
    if ($snapshotDate === '') {
        throw new InvalidArgumentException('Ongeldige snapshot-datum.');
    }

    $pdo = moneta_pdo();
    $previous = moneta_gl_balances_as_of($company, $snapshotDate, true);
    $createdAt = gmdate('c');

    $upsertAccount = $pdo->prepare(
        'INSERT INTO gl_accounts (company, account_no, account_name, account_type, updated_at)
         VALUES (:company, :account_no, :account_name, :account_type, :updated_at)
         ON CONFLICT(company, account_no) DO UPDATE SET
            account_name = excluded.account_name,
            account_type = excluded.account_type,
            updated_at = excluded.updated_at'
    );
    $insertBalance = $pdo->prepare(
        'INSERT INTO gl_balance_snapshots
            (company, account_no, balance, snapshot_date, created_at)
         VALUES
            (:company, :account_no, :balance, :snapshot_date, :created_at)
         ON CONFLICT(company, account_no, snapshot_date) DO UPDATE SET
            balance = excluded.balance,
            created_at = excluded.created_at'
    );
    $deleteUnchanged = $pdo->prepare(
        'DELETE FROM gl_balance_snapshots
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
        $accountName = trim((string) ($account['account_name'] ?? $accountNo));
        $accountType = trim((string) ($account['account_type'] ?? ''));
        $balance = (float) ($account['balance'] ?? 0);

        $upsertAccount->execute([
            ':company' => $company,
            ':account_no' => $accountNo,
            ':account_name' => $accountName,
            ':account_type' => $accountType,
            ':updated_at' => $createdAt,
        ]);

        $prev = $previous[$accountNo] ?? null;
        $unchanged = is_array($prev) && abs((float) ($prev['balance'] ?? 0) - $balance) < 0.00001;
        if ($unchanged) {
            $deleteUnchanged->execute([
                ':company' => $company,
                ':account_no' => $accountNo,
                ':snapshot_date' => $snapshotDate,
            ]);
            continue;
        }

        $insertBalance->execute([
            ':company' => $company,
            ':account_no' => $accountNo,
            ':balance' => $balance,
            ':snapshot_date' => $snapshotDate,
            ':created_at' => $createdAt,
        ]);
        $stored++;
    }

    return $stored;
}

function moneta_snapshot_gl_balances_for_company(string $company, string $snapshotDate = '', int $ttl = MONETA_NIGHTLY_ODATA_TTL): array
{
    $snapshotDate = moneta_parse_date($snapshotDate);
    if ($snapshotDate === '') {
        $snapshotDate = date('Y-m-d');
    }

    $accounts = moneta_fetch_gl_accounts_for_date($company, $snapshotDate, $ttl);
    $stored = moneta_store_gl_snapshot($company, $snapshotDate, $accounts);

    return [
        'company' => $company,
        'snapshot_date' => $snapshotDate,
        'accounts' => count($accounts),
        'stored' => $stored,
    ];
}

function moneta_first_gl_snapshot_date(string $company): string
{
    $pdo = moneta_pdo();
    $statement = $pdo->prepare(
        'SELECT MIN(snapshot_date) AS snapshot_date
         FROM gl_balance_snapshots
         WHERE company = :company'
    );
    $statement->execute([':company' => $company]);

    return moneta_parse_date((string) ($statement->fetch()['snapshot_date'] ?? ''));
}

function moneta_latest_gl_snapshot_date(string $company): string
{
    $pdo = moneta_pdo();
    $statement = $pdo->prepare(
        'SELECT MAX(snapshot_date) AS snapshot_date
         FROM gl_balance_snapshots
         WHERE company = :company'
    );
    $statement->execute([':company' => $company]);

    return moneta_parse_date((string) ($statement->fetch()['snapshot_date'] ?? ''));
}

/**
 * @return list<array{account_no: string, account_name: string, account_type: string}>
 */
function moneta_list_gl_accounts(string $company): array
{
    $pdo = moneta_pdo();
    $statement = $pdo->prepare(
        'SELECT account_no, account_name, account_type
         FROM gl_accounts
         WHERE company = :company
         ORDER BY account_no COLLATE NOCASE ASC'
    );
    $statement->execute([':company' => $company]);

    $rows = [];
    foreach ($statement->fetchAll() as $row) {
        $rows[] = [
            'account_no' => (string) ($row['account_no'] ?? ''),
            'account_name' => (string) ($row['account_name'] ?? ''),
            'account_type' => (string) ($row['account_type'] ?? ''),
        ];
    }

    return $rows;
}

/**
 * @return list<array{id: int, name: string, sort_order: int, accounts: list<array{account_no: string, account_name: string}>}>
 */
function moneta_list_chart_groups(string $company): array
{
    $pdo = moneta_pdo();
    $groupStatement = $pdo->prepare(
        'SELECT id, name, sort_order
         FROM chart_groups
         WHERE company = :company
         ORDER BY sort_order ASC, id ASC'
    );
    $groupStatement->execute([':company' => $company]);
    $groups = $groupStatement->fetchAll();

    $accountStatement = $pdo->prepare(
        'SELECT cga.account_no, COALESCE(ga.account_name, cga.account_no) AS account_name
         FROM chart_group_accounts cga
         LEFT JOIN gl_accounts ga
           ON ga.company = :company
          AND ga.account_no = cga.account_no
         WHERE cga.group_id = :group_id
         ORDER BY cga.account_no COLLATE NOCASE ASC'
    );

    $result = [];
    foreach ($groups as $group) {
        $groupId = (int) ($group['id'] ?? 0);
        $accountStatement->execute([
            ':company' => $company,
            ':group_id' => $groupId,
        ]);
        $accounts = [];
        foreach ($accountStatement->fetchAll() as $account) {
            $accounts[] = [
                'account_no' => (string) ($account['account_no'] ?? ''),
                'account_name' => (string) ($account['account_name'] ?? ''),
            ];
        }
        $result[] = [
            'id' => $groupId,
            'name' => (string) ($group['name'] ?? ''),
            'sort_order' => (int) ($group['sort_order'] ?? 0),
            'accounts' => $accounts,
        ];
    }

    return $result;
}

/**
 * Vervangt alle groepen voor een bedrijf (autosave-payload).
 *
 * @param list<array{id?: int|null, name: string, accounts: list<string>}> $groups
 * @return list<array{id: int, name: string, sort_order: int, accounts: list<array{account_no: string, account_name: string}>}>
 */
function moneta_save_chart_groups(string $company, array $groups): array
{
    $pdo = moneta_pdo();
    $now = gmdate('c');
    $pdo->beginTransaction();
    try {
        $existingIds = [];
        $idStatement = $pdo->prepare('SELECT id FROM chart_groups WHERE company = :company');
        $idStatement->execute([':company' => $company]);
        foreach ($idStatement->fetchAll() as $row) {
            $existingIds[(int) $row['id']] = true;
        }

        $keepIds = [];
        $insertGroup = $pdo->prepare(
            'INSERT INTO chart_groups (company, name, sort_order, created_at, updated_at)
             VALUES (:company, :name, :sort_order, :created_at, :updated_at)'
        );
        $updateGroup = $pdo->prepare(
            'UPDATE chart_groups
             SET name = :name, sort_order = :sort_order, updated_at = :updated_at
             WHERE id = :id AND company = :company'
        );
        $deleteAccounts = $pdo->prepare('DELETE FROM chart_group_accounts WHERE group_id = :group_id');
        $insertAccount = $pdo->prepare(
            'INSERT OR IGNORE INTO chart_group_accounts (group_id, account_no)
             VALUES (:group_id, :account_no)'
        );

        $sortOrder = 0;
        foreach ($groups as $group) {
            if (!is_array($group)) {
                continue;
            }
            $name = trim((string) ($group['name'] ?? ''));
            if ($name === '') {
                $name = 'Groep';
            }
            $groupId = isset($group['id']) ? (int) $group['id'] : 0;
            if ($groupId > 0 && isset($existingIds[$groupId])) {
                $updateGroup->execute([
                    ':name' => $name,
                    ':sort_order' => $sortOrder,
                    ':updated_at' => $now,
                    ':id' => $groupId,
                    ':company' => $company,
                ]);
            } else {
                $insertGroup->execute([
                    ':company' => $company,
                    ':name' => $name,
                    ':sort_order' => $sortOrder,
                    ':created_at' => $now,
                    ':updated_at' => $now,
                ]);
                $groupId = (int) $pdo->lastInsertId();
            }
            $keepIds[$groupId] = true;

            $deleteAccounts->execute([':group_id' => $groupId]);
            $accountNos = $group['accounts'] ?? [];
            if (!is_array($accountNos)) {
                $accountNos = [];
            }
            $seen = [];
            foreach ($accountNos as $accountNo) {
                if (is_array($accountNo)) {
                    $accountNo = (string) ($accountNo['account_no'] ?? '');
                }
                $accountNo = trim((string) $accountNo);
                if ($accountNo === '' || isset($seen[$accountNo])) {
                    continue;
                }
                $seen[$accountNo] = true;
                $insertAccount->execute([
                    ':group_id' => $groupId,
                    ':account_no' => $accountNo,
                ]);
            }
            $sortOrder++;
        }

        foreach (array_keys($existingIds) as $existingId) {
            if (!isset($keepIds[$existingId])) {
                $pdo->prepare('DELETE FROM chart_group_accounts WHERE group_id = :id')
                    ->execute([':id' => $existingId]);
                $pdo->prepare('DELETE FROM chart_groups WHERE id = :id AND company = :company')
                    ->execute([':id' => $existingId, ':company' => $company]);
            }
        }

        $pdo->commit();
    } catch (Throwable $error) {
        $pdo->rollBack();
        throw $error;
    }

    return moneta_list_chart_groups($company);
}

/**
 * Grafiek op groepstotalen (carry-forward per grootboekrekening, gesommeerd per groep).
 *
 * @return array{labels: string[], series: list<array{account_no: string, name: string, data: list<float|null>}>}
 */
function moneta_group_chart_data(string $company, string $dateFrom, string $dateTo): array
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

    $groups = moneta_list_chart_groups($company);
    if ($groups === []) {
        return ['labels' => [], 'series' => []];
    }

    $neededAccounts = [];
    foreach ($groups as $group) {
        foreach ($group['accounts'] as $account) {
            $accountNo = trim((string) ($account['account_no'] ?? ''));
            if ($accountNo !== '') {
                $neededAccounts[$accountNo] = true;
            }
        }
    }
    if ($neededAccounts === []) {
        return ['labels' => [], 'series' => []];
    }

    $pdo = moneta_pdo();
    $statement = $pdo->prepare(
        'SELECT account_no, balance, snapshot_date
         FROM gl_balance_snapshots
         WHERE company = :company
           AND snapshot_date <= :date_to
         ORDER BY snapshot_date ASC'
    );
    $statement->execute([
        ':company' => $company,
        ':date_to' => $dateTo,
    ]);

    $pointsByAccount = [];
    foreach ($statement->fetchAll() as $row) {
        $accountNo = trim((string) ($row['account_no'] ?? ''));
        $date = moneta_parse_date((string) ($row['snapshot_date'] ?? ''));
        if ($accountNo === '' || $date === '' || !isset($neededAccounts[$accountNo])) {
            continue;
        }
        $pointsByAccount[$accountNo][$date] = (float) ($row['balance'] ?? 0);
    }

    $labels = [];
    $cursor = new DateTimeImmutable($dateFrom);
    $end = new DateTimeImmutable($dateTo);
    while ($cursor <= $end) {
        $labels[] = $cursor->format('Y-m-d');
        $cursor = $cursor->modify('+1 day');
    }

    // Carry-forward per rekening over labels.
    $seriesValues = [];
    foreach (array_keys($neededAccounts) as $accountNo) {
        $points = $pointsByAccount[$accountNo] ?? [];
        $pointDates = array_keys($points);
        sort($pointDates);
        $pointIndex = 0;
        $pointCount = count($pointDates);
        $lastKnown = null;
        $data = [];
        foreach ($labels as $label) {
            while ($pointIndex < $pointCount && $pointDates[$pointIndex] <= $label) {
                $lastKnown = (float) $points[$pointDates[$pointIndex]];
                $pointIndex++;
            }
            $data[] = $lastKnown;
        }
        $seriesValues[$accountNo] = $data;
    }

    $series = [];
    foreach ($groups as $group) {
        $accountNos = [];
        foreach ($group['accounts'] as $account) {
            $accountNo = trim((string) ($account['account_no'] ?? ''));
            if ($accountNo !== '') {
                $accountNos[] = $accountNo;
            }
        }
        if ($accountNos === []) {
            continue;
        }

        $data = [];
        $hasValue = false;
        foreach ($labels as $index => $label) {
            $sum = 0.0;
            $known = false;
            foreach ($accountNos as $accountNo) {
                $value = $seriesValues[$accountNo][$index] ?? null;
                if ($value !== null) {
                    $sum += (float) $value;
                    $known = true;
                }
            }
            if ($known) {
                $data[] = $sum;
                $hasValue = true;
            } else {
                $data[] = null;
            }
        }
        if (!$hasValue) {
            continue;
        }

        $series[] = [
            'account_no' => 'group:' . (int) $group['id'],
            'name' => (string) $group['name'],
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
function moneta_latest_group_balances(string $company, string $asOfDate = ''): array
{
    $asOfDate = moneta_parse_date($asOfDate);
    if ($asOfDate === '') {
        $asOfDate = date('Y-m-d');
    }

    $groups = moneta_list_chart_groups($company);
    if ($groups === []) {
        return [];
    }

    $balances = moneta_gl_balances_as_of($company, $asOfDate, false);
    $result = [];
    foreach ($groups as $group) {
        $sum = 0.0;
        $has = false;
        foreach ($group['accounts'] as $account) {
            $accountNo = trim((string) ($account['account_no'] ?? ''));
            if ($accountNo === '' || !isset($balances[$accountNo])) {
                continue;
            }
            $sum += (float) $balances[$accountNo]['balance'];
            $has = true;
        }
        if (!$has) {
            continue;
        }
        $result[] = [
            'account_no' => 'group:' . (int) $group['id'],
            'account_name' => (string) $group['name'],
            'balance_lcy' => $sum,
            'currency_code' => '',
        ];
    }

    return $result;
}

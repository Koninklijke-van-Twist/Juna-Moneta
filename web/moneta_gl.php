<?php

/**
 * Rekeningschema (G_L_Account) snapshots + grafiekgroepen.
 */

const MONETA_GL_SELECT = 'No,Name,Balance_at_Date,Account_Type';
const MONETA_SCHEMA_VERSION = 4;

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
    if ($version < 3) {
        // Oude banksaldi weg: bron is nu Rekeningschema.
        $pdo->exec('DROP TABLE IF EXISTS bank_balance_snapshots');
    }
    if ($version < MONETA_SCHEMA_VERSION) {
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
        'CREATE INDEX IF NOT EXISTS idx_gl_balance_company_account_date
         ON gl_balance_snapshots (company, account_no, snapshot_date)'
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

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS chart_group_balance_snapshots (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            company TEXT NOT NULL,
            group_id INTEGER NOT NULL,
            balance REAL NOT NULL,
            snapshot_date TEXT NOT NULL,
            created_at TEXT NOT NULL,
            UNIQUE(group_id, snapshot_date),
            FOREIGN KEY (group_id) REFERENCES chart_groups(id) ON DELETE CASCADE
        )'
    );
    $pdo->exec(
        'CREATE INDEX IF NOT EXISTS idx_group_balance_company_date
         ON chart_group_balance_snapshots (company, snapshot_date)'
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
    $groupsStored = moneta_cache_group_balances_for_date($company, $snapshotDate);

    return [
        'company' => $company,
        'snapshot_date' => $snapshotDate,
        'accounts' => count($accounts),
        'stored' => $stored,
        'group_balances_stored' => $groupsStored,
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
    $beforeSignature = moneta_groups_membership_signature(moneta_list_chart_groups($company));

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

    $saved = moneta_list_chart_groups($company);
    $afterSignature = moneta_groups_membership_signature($saved);
    if ($beforeSignature !== $afterSignature) {
        moneta_rebuild_group_balance_cache($company);
    }

    return $saved;
}

/**
 * @param list<array{id?: int, accounts?: list<array{account_no?: string}|string>}> $groups
 */
function moneta_groups_membership_signature(array $groups): string
{
    $parts = [];
    foreach ($groups as $group) {
        if (!is_array($group)) {
            continue;
        }
        $groupId = (int) ($group['id'] ?? 0);
        $accounts = [];
        foreach ($group['accounts'] ?? [] as $account) {
            if (is_array($account)) {
                $accountNo = trim((string) ($account['account_no'] ?? ''));
            } else {
                $accountNo = trim((string) $account);
            }
            if ($accountNo !== '') {
                $accounts[] = $accountNo;
            }
        }
        $accounts = array_values(array_unique($accounts));
        sort($accounts, SORT_STRING);
        $parts[] = $groupId . ':' . implode(',', $accounts);
    }
    sort($parts, SORT_STRING);

    return implode('|', $parts);
}

/**
 * Sparse groepssaldi voor één dag (na GL-snapshot / backfill-dag).
 */
function moneta_cache_group_balances_for_date(string $company, string $snapshotDate): int
{
    $snapshotDate = moneta_parse_date($snapshotDate);
    if ($snapshotDate === '') {
        return 0;
    }

    $groups = moneta_list_chart_groups($company);
    if ($groups === []) {
        return 0;
    }

    $balances = moneta_gl_balances_as_of($company, $snapshotDate, false);
    $previous = moneta_group_balances_as_of($company, $snapshotDate, true);
    $pdo = moneta_pdo();
    $createdAt = gmdate('c');

    $insert = $pdo->prepare(
        'INSERT INTO chart_group_balance_snapshots
            (company, group_id, balance, snapshot_date, created_at)
         VALUES
            (:company, :group_id, :balance, :snapshot_date, :created_at)
         ON CONFLICT(group_id, snapshot_date) DO UPDATE SET
            balance = excluded.balance,
            created_at = excluded.created_at'
    );
    $deleteUnchanged = $pdo->prepare(
        'DELETE FROM chart_group_balance_snapshots
         WHERE group_id = :group_id AND snapshot_date = :snapshot_date'
    );

    $stored = 0;
    foreach ($groups as $group) {
        $groupId = (int) ($group['id'] ?? 0);
        if ($groupId <= 0) {
            continue;
        }
        $sum = 0.0;
        $known = false;
        foreach ($group['accounts'] as $account) {
            $accountNo = trim((string) ($account['account_no'] ?? ''));
            if ($accountNo === '' || !isset($balances[$accountNo])) {
                continue;
            }
            $sum += (float) $balances[$accountNo]['balance'];
            $known = true;
        }
        if (!$known) {
            $deleteUnchanged->execute([
                ':group_id' => $groupId,
                ':snapshot_date' => $snapshotDate,
            ]);
            continue;
        }

        $prev = $previous[$groupId] ?? null;
        $unchanged = is_array($prev) && abs((float) ($prev['balance'] ?? 0) - $sum) < 0.00001;
        if ($unchanged) {
            $deleteUnchanged->execute([
                ':group_id' => $groupId,
                ':snapshot_date' => $snapshotDate,
            ]);
            continue;
        }

        $insert->execute([
            ':company' => $company,
            ':group_id' => $groupId,
            ':balance' => $sum,
            ':snapshot_date' => $snapshotDate,
            ':created_at' => $createdAt,
        ]);
        $stored++;
    }

    return $stored;
}

/**
 * Herbouw groepscache uit GL-snapshots (na wijziging van groepsdefinitie).
 *
 * @return array{stored: int, dates: int, groups: int, duration_ms: int}
 */
function moneta_rebuild_group_balance_cache(string $company): array
{
    $startedAt = hrtime(true);
    $pdo = moneta_pdo();
    $groups = moneta_list_chart_groups($company);

    $pdo->prepare('DELETE FROM chart_group_balance_snapshots WHERE company = :company')
        ->execute([':company' => $company]);

    $finish = static function (int $stored, int $dates, int $groupCount) use ($company, $pdo, $groups, $startedAt): array {
        moneta_meta_set($pdo, 'group_balance_cache_v:' . $company, moneta_groups_membership_signature($groups));

        return [
            'stored' => $stored,
            'dates' => $dates,
            'groups' => $groupCount,
            'duration_ms' => (int) round((hrtime(true) - $startedAt) / 1_000_000),
        ];
    };

    if ($groups === []) {
        return $finish(0, 0, 0);
    }

    $groupAccounts = [];
    $neededAccounts = [];
    foreach ($groups as $group) {
        $groupId = (int) ($group['id'] ?? 0);
        if ($groupId <= 0) {
            continue;
        }
        $accountNos = [];
        foreach ($group['accounts'] as $account) {
            $accountNo = trim((string) ($account['account_no'] ?? ''));
            if ($accountNo === '') {
                continue;
            }
            $accountNos[] = $accountNo;
            $neededAccounts[$accountNo] = true;
        }
        if ($accountNos !== []) {
            $groupAccounts[$groupId] = $accountNos;
        }
    }

    if ($neededAccounts === []) {
        return $finish(0, 0, count($groupAccounts));
    }

    $accountList = array_keys($neededAccounts);
    $placeholders = implode(',', array_fill(0, count($accountList), '?'));
    $sql = 'SELECT account_no, balance, snapshot_date
            FROM gl_balance_snapshots
            WHERE company = ?
              AND account_no IN (' . $placeholders . ')
            ORDER BY snapshot_date ASC';
    $statement = $pdo->prepare($sql);
    $statement->execute(array_merge([$company], $accountList));

    $byDate = [];
    foreach ($statement->fetchAll() as $row) {
        $date = moneta_parse_date((string) ($row['snapshot_date'] ?? ''));
        $accountNo = trim((string) ($row['account_no'] ?? ''));
        if ($date === '' || $accountNo === '') {
            continue;
        }
        $byDate[$date][$accountNo] = (float) ($row['balance'] ?? 0);
    }

    $dates = array_keys($byDate);
    sort($dates);

    $running = [];
    $prevGroupBalance = [];
    $createdAt = gmdate('c');
    $insert = $pdo->prepare(
        'INSERT INTO chart_group_balance_snapshots
            (company, group_id, balance, snapshot_date, created_at)
         VALUES
            (:company, :group_id, :balance, :snapshot_date, :created_at)'
    );

    $stored = 0;
    $pdo->beginTransaction();
    try {
        foreach ($dates as $date) {
            foreach ($byDate[$date] as $accountNo => $balance) {
                $running[$accountNo] = $balance;
            }
            foreach ($groupAccounts as $groupId => $accountNos) {
                $sum = 0.0;
                $known = false;
                foreach ($accountNos as $accountNo) {
                    if (!array_key_exists($accountNo, $running)) {
                        continue;
                    }
                    $sum += (float) $running[$accountNo];
                    $known = true;
                }
                if (!$known) {
                    continue;
                }
                if (isset($prevGroupBalance[$groupId])
                    && abs((float) $prevGroupBalance[$groupId] - $sum) < 0.00001
                ) {
                    continue;
                }
                $insert->execute([
                    ':company' => $company,
                    ':group_id' => $groupId,
                    ':balance' => $sum,
                    ':snapshot_date' => $date,
                    ':created_at' => $createdAt,
                ]);
                $prevGroupBalance[$groupId] = $sum;
                $stored++;
            }
        }
        $pdo->commit();
    } catch (Throwable $error) {
        $pdo->rollBack();
        throw $error;
    }

    return $finish($stored, count($dates), count($groupAccounts));
}

/**
 * @return array<int, array{group_id: int, balance: float, snapshot_date: string}>
 */
function moneta_group_balances_as_of(string $company, string $asOfDate, bool $strictBefore = false): array
{
    $asOfDate = moneta_parse_date($asOfDate);
    if ($asOfDate === '') {
        $asOfDate = date('Y-m-d');
    }

    $pdo = moneta_pdo();
    $operator = $strictBefore ? '<' : '<=';
    $statement = $pdo->prepare(
        'SELECT b.group_id, b.balance, b.snapshot_date
         FROM chart_group_balance_snapshots b
         INNER JOIN (
            SELECT group_id, MAX(snapshot_date) AS snapshot_date
            FROM chart_group_balance_snapshots
            WHERE company = :company
              AND snapshot_date ' . $operator . ' :as_of
            GROUP BY group_id
         ) latest
           ON latest.group_id = b.group_id
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
        $groupId = (int) ($row['group_id'] ?? 0);
        if ($groupId <= 0) {
            continue;
        }
        $out[$groupId] = [
            'group_id' => $groupId,
            'balance' => (float) ($row['balance'] ?? 0),
            'snapshot_date' => (string) ($row['snapshot_date'] ?? ''),
        ];
    }

    return $out;
}

/**
 * Grafiek op gecachte groepstotalen (carry-forward over kalenderdagen).
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

    $groupMeta = [];
    foreach ($groups as $group) {
        $groupId = (int) ($group['id'] ?? 0);
        if ($groupId <= 0 || ($group['accounts'] ?? []) === []) {
            continue;
        }
        $groupMeta[$groupId] = (string) ($group['name'] ?? '');
    }
    if ($groupMeta === []) {
        return ['labels' => [], 'series' => []];
    }

    $pdo = moneta_pdo();
    $membership = moneta_groups_membership_signature($groups);
    if (moneta_meta_get($pdo, 'group_balance_cache_v:' . $company) !== $membership) {
        moneta_rebuild_group_balance_cache($company);
    }

    $statement = $pdo->prepare(
        'SELECT group_id, balance, snapshot_date
         FROM chart_group_balance_snapshots
         WHERE company = :company
           AND snapshot_date <= :date_to
         ORDER BY snapshot_date ASC'
    );
    $statement->execute([
        ':company' => $company,
        ':date_to' => $dateTo,
    ]);

    $pointsByGroup = [];
    foreach ($statement->fetchAll() as $row) {
        $groupId = (int) ($row['group_id'] ?? 0);
        $date = moneta_parse_date((string) ($row['snapshot_date'] ?? ''));
        if ($groupId <= 0 || $date === '' || !isset($groupMeta[$groupId])) {
            continue;
        }
        $pointsByGroup[$groupId][$date] = (float) ($row['balance'] ?? 0);
    }

    if ($pointsByGroup === []) {
        return ['labels' => [], 'series' => []];
    }

    $labels = [];
    $cursor = new DateTimeImmutable($dateFrom);
    $end = new DateTimeImmutable($dateTo);
    while ($cursor <= $end) {
        $labels[] = $cursor->format('Y-m-d');
        $cursor = $cursor->modify('+1 day');
    }

    $series = [];
    foreach ($groupMeta as $groupId => $name) {
        $points = $pointsByGroup[$groupId] ?? [];
        if ($points === []) {
            continue;
        }
        $pointDates = array_keys($points);
        sort($pointDates);
        $pointIndex = 0;
        $pointCount = count($pointDates);
        $lastKnown = null;
        $data = [];
        $hasValue = false;
        foreach ($labels as $label) {
            while ($pointIndex < $pointCount && $pointDates[$pointIndex] <= $label) {
                $lastKnown = (float) $points[$pointDates[$pointIndex]];
                $pointIndex++;
            }
            $data[] = $lastKnown;
            if ($lastKnown !== null) {
                $hasValue = true;
            }
        }
        if (!$hasValue) {
            continue;
        }
        $series[] = [
            'account_no' => 'group:' . $groupId,
            'name' => $name,
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

    $balances = moneta_group_balances_as_of($company, $asOfDate, false);
    $result = [];
    foreach ($groups as $group) {
        $groupId = (int) ($group['id'] ?? 0);
        if ($groupId <= 0 || !isset($balances[$groupId])) {
            continue;
        }
        $result[] = [
            'account_no' => 'group:' . $groupId,
            'account_name' => (string) ($group['name'] ?? ''),
            'balance_lcy' => (float) $balances[$groupId]['balance'],
            'currency_code' => '',
        ];
    }

    return $result;
}

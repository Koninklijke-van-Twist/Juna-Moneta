<?php

/**
 * Gebruikersprognose: eenmalige kosten en herhalende regels op Rekeningschema-rekeningen.
 */

const MONETA_FORECAST_UNITS = ['day', 'week', 'month', 'year'];

/**
 * @return list<array{
 *   id: int,
 *   account_no: string,
 *   account_name: string,
 *   name: string,
 *   description: string,
 *   amount: float,
 *   event_date: string
 * }>
 */
function moneta_list_forecast_one_time(string $company): array
{
    $pdo = moneta_pdo();
    $statement = $pdo->prepare(
        'SELECT f.id, f.account_no, f.name, f.description, f.amount, f.event_date,
                COALESCE(ga.account_name, f.account_no) AS account_name
         FROM forecast_one_time f
         LEFT JOIN gl_accounts ga
           ON ga.company = f.company AND ga.account_no = f.account_no
         WHERE f.company = :company
         ORDER BY f.event_date ASC, f.id ASC'
    );
    $statement->execute([':company' => $company]);

    $rows = [];
    foreach ($statement->fetchAll() as $row) {
        $rows[] = [
            'id' => (int) ($row['id'] ?? 0),
            'account_no' => (string) ($row['account_no'] ?? ''),
            'account_name' => (string) ($row['account_name'] ?? ''),
            'name' => (string) ($row['name'] ?? ''),
            'description' => (string) ($row['description'] ?? ''),
            'amount' => (float) ($row['amount'] ?? 0),
            'event_date' => moneta_parse_date((string) ($row['event_date'] ?? '')),
        ];
    }

    return $rows;
}

/**
 * @param list<array{
 *   id?: int|null,
 *   account_no: string,
 *   name?: string,
 *   description?: string,
 *   amount: float|int|string,
 *   event_date: string
 * }> $items
 * @return list<array{id: int, account_no: string, account_name: string, name: string, description: string, amount: float, event_date: string}>
 */
function moneta_save_forecast_one_time(string $company, array $items): array
{
    $pdo = moneta_pdo();
    $now = gmdate('c');
    $pdo->beginTransaction();
    try {
        $existing = [];
        $idStatement = $pdo->prepare('SELECT id FROM forecast_one_time WHERE company = :company');
        $idStatement->execute([':company' => $company]);
        foreach ($idStatement->fetchAll() as $row) {
            $existing[(int) $row['id']] = true;
        }

        $insert = $pdo->prepare(
            'INSERT INTO forecast_one_time
                (company, account_no, name, description, amount, event_date, created_at, updated_at)
             VALUES
                (:company, :account_no, :name, :description, :amount, :event_date, :created_at, :updated_at)'
        );
        $update = $pdo->prepare(
            'UPDATE forecast_one_time
             SET account_no = :account_no, name = :name, description = :description,
                 amount = :amount, event_date = :event_date, updated_at = :updated_at
             WHERE id = :id AND company = :company'
        );

        $keep = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $accountNo = trim((string) ($item['account_no'] ?? ''));
            $eventDate = moneta_parse_date((string) ($item['event_date'] ?? ''));
            if ($accountNo === '' || $eventDate === '') {
                continue;
            }
            $name = trim((string) ($item['name'] ?? ''));
            $description = trim((string) ($item['description'] ?? ''));
            $amount = (float) ($item['amount'] ?? 0);
            $id = isset($item['id']) ? (int) $item['id'] : 0;

            if ($id > 0 && isset($existing[$id])) {
                $update->execute([
                    ':account_no' => $accountNo,
                    ':name' => $name,
                    ':description' => $description,
                    ':amount' => $amount,
                    ':event_date' => $eventDate,
                    ':updated_at' => $now,
                    ':id' => $id,
                    ':company' => $company,
                ]);
            } else {
                $insert->execute([
                    ':company' => $company,
                    ':account_no' => $accountNo,
                    ':name' => $name,
                    ':description' => $description,
                    ':amount' => $amount,
                    ':event_date' => $eventDate,
                    ':created_at' => $now,
                    ':updated_at' => $now,
                ]);
                $id = (int) $pdo->lastInsertId();
            }
            $keep[$id] = true;
        }

        foreach (array_keys($existing) as $existingId) {
            if (!isset($keep[$existingId])) {
                $pdo->prepare('DELETE FROM forecast_one_time WHERE id = :id AND company = :company')
                    ->execute([':id' => $existingId, ':company' => $company]);
            }
        }

        $pdo->commit();
    } catch (Throwable $error) {
        $pdo->rollBack();
        throw $error;
    }

    return moneta_list_forecast_one_time($company);
}

/**
 * @return list<array{
 *   id: int,
 *   account_no: string,
 *   account_name: string,
 *   name: string,
 *   description: string,
 *   amount: float,
 *   start_date: string,
 *   repeat_n: int,
 *   repeat_unit: string,
 *   end_date: string|null
 * }>
 */
function moneta_list_forecast_rules(string $company): array
{
    $pdo = moneta_pdo();
    $statement = $pdo->prepare(
        'SELECT f.id, f.account_no, f.name, f.description, f.amount, f.start_date,
                f.repeat_n, f.repeat_unit, f.end_date,
                COALESCE(ga.account_name, f.account_no) AS account_name
         FROM forecast_rules f
         LEFT JOIN gl_accounts ga
           ON ga.company = f.company AND ga.account_no = f.account_no
         WHERE f.company = :company
         ORDER BY f.start_date ASC, f.id ASC'
    );
    $statement->execute([':company' => $company]);

    $rows = [];
    foreach ($statement->fetchAll() as $row) {
        $endDate = moneta_parse_date((string) ($row['end_date'] ?? ''));
        $rows[] = [
            'id' => (int) ($row['id'] ?? 0),
            'account_no' => (string) ($row['account_no'] ?? ''),
            'account_name' => (string) ($row['account_name'] ?? ''),
            'name' => (string) ($row['name'] ?? ''),
            'description' => (string) ($row['description'] ?? ''),
            'amount' => (float) ($row['amount'] ?? 0),
            'start_date' => moneta_parse_date((string) ($row['start_date'] ?? '')),
            'repeat_n' => max(1, (int) ($row['repeat_n'] ?? 1)),
            'repeat_unit' => moneta_normalize_forecast_unit((string) ($row['repeat_unit'] ?? 'month')),
            'end_date' => $endDate !== '' ? $endDate : null,
        ];
    }

    return $rows;
}

/**
 * @param list<array{
 *   id?: int|null,
 *   account_no: string,
 *   name?: string,
 *   description?: string,
 *   amount: float|int|string,
 *   start_date: string,
 *   repeat_n?: int|string,
 *   repeat_unit?: string,
 *   end_date?: string|null
 * }> $items
 * @return list<array{id: int, account_no: string, account_name: string, name: string, description: string, amount: float, start_date: string, repeat_n: int, repeat_unit: string, end_date: string|null}>
 */
function moneta_save_forecast_rules(string $company, array $items): array
{
    $pdo = moneta_pdo();
    $now = gmdate('c');
    $pdo->beginTransaction();
    try {
        $existing = [];
        $idStatement = $pdo->prepare('SELECT id FROM forecast_rules WHERE company = :company');
        $idStatement->execute([':company' => $company]);
        foreach ($idStatement->fetchAll() as $row) {
            $existing[(int) $row['id']] = true;
        }

        $insert = $pdo->prepare(
            'INSERT INTO forecast_rules
                (company, account_no, name, description, amount, start_date, repeat_n, repeat_unit, end_date, created_at, updated_at)
             VALUES
                (:company, :account_no, :name, :description, :amount, :start_date, :repeat_n, :repeat_unit, :end_date, :created_at, :updated_at)'
        );
        $update = $pdo->prepare(
            'UPDATE forecast_rules
             SET account_no = :account_no, name = :name, description = :description, amount = :amount,
                 start_date = :start_date, repeat_n = :repeat_n, repeat_unit = :repeat_unit,
                 end_date = :end_date, updated_at = :updated_at
             WHERE id = :id AND company = :company'
        );

        $keep = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $accountNo = trim((string) ($item['account_no'] ?? ''));
            $startDate = moneta_parse_date((string) ($item['start_date'] ?? ''));
            if ($accountNo === '' || $startDate === '') {
                continue;
            }
            $endRaw = $item['end_date'] ?? null;
            $endDate = is_string($endRaw) ? moneta_parse_date($endRaw) : '';
            if ($endDate !== '' && $endDate < $startDate) {
                throw new InvalidArgumentException('Einddatum moet op of na de startdatum liggen.');
            }
            $repeatN = max(1, (int) ($item['repeat_n'] ?? 1));
            $repeatUnit = moneta_normalize_forecast_unit((string) ($item['repeat_unit'] ?? 'month'));
            $name = trim((string) ($item['name'] ?? ''));
            $description = trim((string) ($item['description'] ?? ''));
            $amount = (float) ($item['amount'] ?? 0);
            $id = isset($item['id']) ? (int) $item['id'] : 0;

            $params = [
                ':account_no' => $accountNo,
                ':name' => $name,
                ':description' => $description,
                ':amount' => $amount,
                ':start_date' => $startDate,
                ':repeat_n' => $repeatN,
                ':repeat_unit' => $repeatUnit,
                ':end_date' => $endDate !== '' ? $endDate : null,
                ':updated_at' => $now,
            ];

            if ($id > 0 && isset($existing[$id])) {
                $update->execute($params + [
                    ':id' => $id,
                    ':company' => $company,
                ]);
            } else {
                $insert->execute($params + [
                    ':company' => $company,
                    ':created_at' => $now,
                ]);
                $id = (int) $pdo->lastInsertId();
            }
            $keep[$id] = true;
        }

        foreach (array_keys($existing) as $existingId) {
            if (!isset($keep[$existingId])) {
                $pdo->prepare('DELETE FROM forecast_rules WHERE id = :id AND company = :company')
                    ->execute([':id' => $existingId, ':company' => $company]);
            }
        }

        $pdo->commit();
    } catch (Throwable $error) {
        $pdo->rollBack();
        throw $error;
    }

    return moneta_list_forecast_rules($company);
}

function moneta_normalize_forecast_unit(string $unit): string
{
    $unit = strtolower(trim($unit));
    if (!in_array($unit, MONETA_FORECAST_UNITS, true)) {
        return 'month';
    }

    return $unit;
}

/**
 * Verwijder eenmalige regels vóór vandaag en herhalende regels met verstreken einddatum.
 *
 * @return array{one_time: int, rules: int}
 */
function moneta_purge_expired_forecasts(string $company, string $asOfDate = ''): array
{
    $asOfDate = moneta_parse_date($asOfDate);
    if ($asOfDate === '') {
        $asOfDate = date('Y-m-d');
    }

    $pdo = moneta_pdo();
    $one = $pdo->prepare(
        'DELETE FROM forecast_one_time
         WHERE company = :company AND event_date < :as_of'
    );
    $one->execute([
        ':company' => $company,
        ':as_of' => $asOfDate,
    ]);

    $rules = $pdo->prepare(
        'DELETE FROM forecast_rules
         WHERE company = :company
           AND end_date IS NOT NULL
           AND TRIM(end_date) <> \'\'
           AND end_date < :as_of'
    );
    $rules->execute([
        ':company' => $company,
        ':as_of' => $asOfDate,
    ]);

    return [
        'one_time' => $one->rowCount(),
        'rules' => $rules->rowCount(),
    ];
}

/**
 * @param array{
 *   id?: int|null,
 *   account_no: string,
 *   name?: string,
 *   description?: string,
 *   amount?: float|int|string,
 *   event_date: string
 * } $item
 * @return array{id: int, account_no: string, account_name: string, name: string, description: string, amount: float, event_date: string}
 */
function moneta_upsert_forecast_one_time(string $company, array $item): array
{
    $accountNo = trim((string) ($item['account_no'] ?? ''));
    $eventDate = moneta_parse_date((string) ($item['event_date'] ?? ''));
    if ($accountNo === '' || $eventDate === '') {
        throw new InvalidArgumentException('Rekening en datum zijn verplicht.');
    }

    $pdo = moneta_pdo();
    $now = gmdate('c');
    $id = isset($item['id']) ? (int) $item['id'] : 0;
    $name = trim((string) ($item['name'] ?? ''));
    $description = trim((string) ($item['description'] ?? ''));
    $amount = (float) ($item['amount'] ?? 0);

    if ($id > 0) {
        $pdo->prepare(
            'UPDATE forecast_one_time
             SET account_no = :account_no, name = :name, description = :description,
                 amount = :amount, event_date = :event_date, updated_at = :updated_at
             WHERE id = :id AND company = :company'
        )->execute([
            ':account_no' => $accountNo,
            ':name' => $name,
            ':description' => $description,
            ':amount' => $amount,
            ':event_date' => $eventDate,
            ':updated_at' => $now,
            ':id' => $id,
            ':company' => $company,
        ]);
    } else {
        $pdo->prepare(
            'INSERT INTO forecast_one_time
                (company, account_no, name, description, amount, event_date, created_at, updated_at)
             VALUES
                (:company, :account_no, :name, :description, :amount, :event_date, :created_at, :updated_at)'
        )->execute([
            ':company' => $company,
            ':account_no' => $accountNo,
            ':name' => $name,
            ':description' => $description,
            ':amount' => $amount,
            ':event_date' => $eventDate,
            ':created_at' => $now,
            ':updated_at' => $now,
        ]);
        $id = (int) $pdo->lastInsertId();
    }

    foreach (moneta_list_forecast_one_time($company) as $row) {
        if ((int) $row['id'] === $id) {
            return $row;
        }
    }

    throw new RuntimeException('Prognoseregel kon niet worden opgeslagen.');
}

function moneta_delete_forecast_one_time(string $company, int $id): void
{
    if ($id <= 0) {
        throw new InvalidArgumentException('Ongeldig id.');
    }
    moneta_pdo()->prepare(
        'DELETE FROM forecast_one_time WHERE id = :id AND company = :company'
    )->execute([':id' => $id, ':company' => $company]);
}

/**
 * @param array{
 *   id?: int|null,
 *   account_no: string,
 *   name?: string,
 *   description?: string,
 *   amount?: float|int|string,
 *   start_date: string,
 *   repeat_n?: int|string,
 *   repeat_unit?: string,
 *   end_date?: string|null
 * } $item
 * @return array{id: int, account_no: string, account_name: string, name: string, description: string, amount: float, start_date: string, repeat_n: int, repeat_unit: string, end_date: string|null}
 */
function moneta_upsert_forecast_rule(string $company, array $item): array
{
    $accountNo = trim((string) ($item['account_no'] ?? ''));
    $startDate = moneta_parse_date((string) ($item['start_date'] ?? ''));
    if ($accountNo === '' || $startDate === '') {
        throw new InvalidArgumentException('Rekening en startdatum zijn verplicht.');
    }
    $endRaw = $item['end_date'] ?? null;
    $endDate = is_string($endRaw) ? moneta_parse_date($endRaw) : '';
    if ($endDate !== '' && $endDate < $startDate) {
        throw new InvalidArgumentException('Einddatum moet op of na de startdatum liggen.');
    }

    $pdo = moneta_pdo();
    $now = gmdate('c');
    $id = isset($item['id']) ? (int) $item['id'] : 0;
    $params = [
        ':account_no' => $accountNo,
        ':name' => trim((string) ($item['name'] ?? '')),
        ':description' => trim((string) ($item['description'] ?? '')),
        ':amount' => (float) ($item['amount'] ?? 0),
        ':start_date' => $startDate,
        ':repeat_n' => max(1, (int) ($item['repeat_n'] ?? 1)),
        ':repeat_unit' => moneta_normalize_forecast_unit((string) ($item['repeat_unit'] ?? 'month')),
        ':end_date' => $endDate !== '' ? $endDate : null,
        ':updated_at' => $now,
    ];

    if ($id > 0) {
        $pdo->prepare(
            'UPDATE forecast_rules
             SET account_no = :account_no, name = :name, description = :description, amount = :amount,
                 start_date = :start_date, repeat_n = :repeat_n, repeat_unit = :repeat_unit,
                 end_date = :end_date, updated_at = :updated_at
             WHERE id = :id AND company = :company'
        )->execute($params + [':id' => $id, ':company' => $company]);
    } else {
        $pdo->prepare(
            'INSERT INTO forecast_rules
                (company, account_no, name, description, amount, start_date, repeat_n, repeat_unit, end_date, created_at, updated_at)
             VALUES
                (:company, :account_no, :name, :description, :amount, :start_date, :repeat_n, :repeat_unit, :end_date, :created_at, :updated_at)'
        )->execute($params + [':company' => $company, ':created_at' => $now]);
        $id = (int) $pdo->lastInsertId();
    }

    foreach (moneta_list_forecast_rules($company) as $row) {
        if ((int) $row['id'] === $id) {
            return $row;
        }
    }

    throw new RuntimeException('Prognoseregel kon niet worden opgeslagen.');
}

function moneta_delete_forecast_rule(string $company, int $id): void
{
    if ($id <= 0) {
        throw new InvalidArgumentException('Ongeldig id.');
    }
    moneta_pdo()->prepare(
        'DELETE FROM forecast_rules WHERE id = :id AND company = :company'
    )->execute([':id' => $id, ':company' => $company]);
}

/**
 * Som van prognosebedragen per rekening per dag in [dateFrom, dateTo].
 *
 * @return array<string, array<string, float>> account_no => [Y-m-d => amount]
 */
function moneta_forecast_account_deltas(string $company, string $dateFrom, string $dateTo): array
{
    static $purgedCompanies = [];
    if (!isset($purgedCompanies[$company])) {
        moneta_purge_expired_forecasts($company);
        $purgedCompanies[$company] = true;
    }

    $dateFrom = moneta_parse_date($dateFrom);
    $dateTo = moneta_parse_date($dateTo);
    if ($dateFrom === '' || $dateTo === '' || $dateFrom > $dateTo) {
        return [];
    }

    $deltas = [];

    foreach (moneta_list_forecast_one_time($company) as $item) {
        $date = $item['event_date'];
        $accountNo = $item['account_no'];
        if ($date === '' || $accountNo === '' || $date < $dateFrom || $date > $dateTo) {
            continue;
        }
        if (!isset($deltas[$accountNo][$date])) {
            $deltas[$accountNo][$date] = 0.0;
        }
        $deltas[$accountNo][$date] += (float) $item['amount'];
    }

    foreach (moneta_list_forecast_rules($company) as $rule) {
        $accountNo = $rule['account_no'];
        if ($accountNo === '') {
            continue;
        }
        foreach (moneta_forecast_rule_occurrences($rule, $dateFrom, $dateTo) as $date) {
            if (!isset($deltas[$accountNo][$date])) {
                $deltas[$accountNo][$date] = 0.0;
            }
            $deltas[$accountNo][$date] += (float) $rule['amount'];
        }
    }

    return $deltas;
}

/**
 * @param array{start_date: string, repeat_n: int, repeat_unit: string, end_date?: string|null} $rule
 * @return list<string>
 */
function moneta_forecast_rule_occurrences(array $rule, string $dateFrom, string $dateTo): array
{
    $start = moneta_parse_date((string) ($rule['start_date'] ?? ''));
    if ($start === '') {
        return [];
    }
    $n = max(1, (int) ($rule['repeat_n'] ?? 1));
    $unit = moneta_normalize_forecast_unit((string) ($rule['repeat_unit'] ?? 'month'));
    $endLimit = moneta_parse_date((string) ($rule['end_date'] ?? ''));
    if ($endLimit === '') {
        $endLimit = $dateTo;
    } else {
        $endLimit = min($endLimit, $dateTo);
    }
    if ($start > $endLimit) {
        return [];
    }

    $modifier = '+' . $n . ' ' . $unit;
    $cursor = new DateTimeImmutable($start);
    $end = new DateTimeImmutable($endLimit);
    $from = new DateTimeImmutable($dateFrom);
    $out = [];
    $guard = 0;
    while ($cursor <= $end && $guard < 100000) {
        if ($cursor >= $from) {
            $out[] = $cursor->format('Y-m-d');
        }
        $cursor = $cursor->modify($modifier);
        $guard++;
    }

    return $out;
}

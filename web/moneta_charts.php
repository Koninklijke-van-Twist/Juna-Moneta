<?php

/**
 * Multi-grafieken: balance + derived charts, groepen met negate, forecast-overlay.
 */

/**
 * Zorg dat er minstens één balance-chart is voor het bedrijf.
 */
function moneta_ensure_default_balance_chart(string $company): int
{
    $pdo = moneta_pdo();
    $statement = $pdo->prepare(
        'SELECT id FROM charts
         WHERE company = :company AND chart_type = :type
         ORDER BY sort_order ASC, id ASC
         LIMIT 1'
    );
    $statement->execute([
        ':company' => $company,
        ':type' => MONETA_CHART_TYPE_BALANCE,
    ]);
    $id = (int) ($statement->fetch()['id'] ?? 0);
    if ($id > 0) {
        return $id;
    }

    $now = gmdate('c');
    $insert = $pdo->prepare(
        'INSERT INTO charts (company, name, chart_type, sort_order, created_at, updated_at)
         VALUES (:company, :name, :type, 0, :created_at, :updated_at)'
    );
    $insert->execute([
        ':company' => $company,
        ':name' => 'Saldi',
        ':type' => MONETA_CHART_TYPE_BALANCE,
        ':created_at' => $now,
        ':updated_at' => $now,
    ]);

    return (int) $pdo->lastInsertId();
}

/**
 * @return list<array{
 *   id: int,
 *   name: string,
 *   chart_type: string,
 *   sort_order: int,
 *   groups?: list<array>,
 *   derived_series?: list<array>
 * }>
 */
function moneta_list_charts(string $company, bool $includeDetails = true): array
{
    moneta_ensure_default_balance_chart($company);
    $pdo = moneta_pdo();
    $statement = $pdo->prepare(
        'SELECT id, name, chart_type, sort_order
         FROM charts
         WHERE company = :company
         ORDER BY sort_order ASC, id ASC'
    );
    $statement->execute([':company' => $company]);

    $charts = [];
    foreach ($statement->fetchAll() as $row) {
        $chartId = (int) ($row['id'] ?? 0);
        $chartType = (string) ($row['chart_type'] ?? MONETA_CHART_TYPE_BALANCE);
        $item = [
            'id' => $chartId,
            'name' => (string) ($row['name'] ?? ''),
            'chart_type' => $chartType,
            'sort_order' => (int) ($row['sort_order'] ?? 0),
        ];
        if ($includeDetails) {
            if ($chartType === MONETA_CHART_TYPE_DERIVED) {
                $item['derived_series'] = moneta_list_derived_series($chartId);
                $item['groups'] = [];
            } else {
                $item['groups'] = moneta_list_chart_groups($company, $chartId);
                $item['derived_series'] = [];
            }
        }
        $charts[] = $item;
    }

    return $charts;
}

/**
 * @return array{id: int, name: string, chart_type: string, sort_order: int}|null
 */
function moneta_get_chart(string $company, int $chartId): ?array
{
    if ($chartId <= 0) {
        return null;
    }
    $pdo = moneta_pdo();
    $statement = $pdo->prepare(
        'SELECT id, name, chart_type, sort_order
         FROM charts
         WHERE id = :id AND company = :company'
    );
    $statement->execute([
        ':id' => $chartId,
        ':company' => $company,
    ]);
    $row = $statement->fetch();
    if (!$row) {
        return null;
    }

    return [
        'id' => (int) $row['id'],
        'name' => (string) ($row['name'] ?? ''),
        'chart_type' => (string) ($row['chart_type'] ?? MONETA_CHART_TYPE_BALANCE),
        'sort_order' => (int) ($row['sort_order'] ?? 0),
    ];
}

/**
 * @param array{name?: string, chart_type?: string, sort_order?: int} $payload
 * @return array{id: int, name: string, chart_type: string, sort_order: int}
 */
function moneta_create_chart(string $company, array $payload): array
{
    $name = trim((string) ($payload['name'] ?? ''));
    $chartType = trim((string) ($payload['chart_type'] ?? MONETA_CHART_TYPE_BALANCE));
    if ($chartType !== MONETA_CHART_TYPE_DERIVED) {
        $chartType = MONETA_CHART_TYPE_BALANCE;
    }
    if ($name === '') {
        $name = $chartType === MONETA_CHART_TYPE_DERIVED ? 'Combinatiegrafiek' : 'Saldi';
    }

    $pdo = moneta_pdo();
    $maxOrder = $pdo->prepare('SELECT COALESCE(MAX(sort_order), -1) AS m FROM charts WHERE company = :company');
    $maxOrder->execute([':company' => $company]);
    $sortOrder = (int) ($maxOrder->fetch()['m'] ?? -1) + 1;
    if (isset($payload['sort_order'])) {
        $sortOrder = (int) $payload['sort_order'];
    }

    $now = gmdate('c');
    $insert = $pdo->prepare(
        'INSERT INTO charts (company, name, chart_type, sort_order, created_at, updated_at)
         VALUES (:company, :name, :type, :sort_order, :created_at, :updated_at)'
    );
    $insert->execute([
        ':company' => $company,
        ':name' => $name,
        ':type' => $chartType,
        ':sort_order' => $sortOrder,
        ':created_at' => $now,
        ':updated_at' => $now,
    ]);

    $id = (int) $pdo->lastInsertId();
    $chart = moneta_get_chart($company, $id);
    if ($chart === null) {
        throw new RuntimeException('Grafiek kon niet worden aangemaakt.');
    }

    return $chart;
}

/**
 * @param array{name?: string, sort_order?: int} $payload
 * @return array{id: int, name: string, chart_type: string, sort_order: int}
 */
function moneta_update_chart(string $company, int $chartId, array $payload): array
{
    $chart = moneta_get_chart($company, $chartId);
    if ($chart === null) {
        throw new InvalidArgumentException('Grafiek niet gevonden.');
    }

    $name = array_key_exists('name', $payload)
        ? trim((string) $payload['name'])
        : $chart['name'];
    if ($name === '') {
        $name = $chart['name'] !== '' ? $chart['name'] : 'Grafiek';
    }
    $sortOrder = array_key_exists('sort_order', $payload)
        ? (int) $payload['sort_order']
        : $chart['sort_order'];

    $pdo = moneta_pdo();
    $pdo->prepare(
        'UPDATE charts
         SET name = :name, sort_order = :sort_order, updated_at = :updated_at
         WHERE id = :id AND company = :company'
    )->execute([
        ':name' => $name,
        ':sort_order' => $sortOrder,
        ':updated_at' => gmdate('c'),
        ':id' => $chartId,
        ':company' => $company,
    ]);

    $updated = moneta_get_chart($company, $chartId);
    if ($updated === null) {
        throw new RuntimeException('Grafiek kon niet worden bijgewerkt.');
    }

    return $updated;
}

function moneta_delete_chart(string $company, int $chartId): void
{
    $chart = moneta_get_chart($company, $chartId);
    if ($chart === null) {
        throw new InvalidArgumentException('Grafiek niet gevonden.');
    }

    $pdo = moneta_pdo();
    if ($chart['chart_type'] === MONETA_CHART_TYPE_BALANCE) {
        $count = $pdo->prepare(
            'SELECT COUNT(*) AS c FROM charts
             WHERE company = :company AND chart_type = :type'
        );
        $count->execute([
            ':company' => $company,
            ':type' => MONETA_CHART_TYPE_BALANCE,
        ]);
        if ((int) ($count->fetch()['c'] ?? 0) <= 1) {
            throw new InvalidArgumentException('De laatste saldigrafiek kan niet worden verwijderd.');
        }
    }

    $pdo->beginTransaction();
    try {
        if ($chart['chart_type'] === MONETA_CHART_TYPE_BALANCE) {
            $groupIds = [];
            $gs = $pdo->prepare('SELECT id FROM chart_groups WHERE chart_id = :chart_id AND company = :company');
            $gs->execute([':chart_id' => $chartId, ':company' => $company]);
            foreach ($gs->fetchAll() as $row) {
                $groupIds[] = (int) $row['id'];
            }
            foreach ($groupIds as $groupId) {
                $pdo->prepare('DELETE FROM chart_group_accounts WHERE group_id = :id')
                    ->execute([':id' => $groupId]);
                $pdo->prepare('DELETE FROM chart_group_balance_snapshots WHERE group_id = :id')
                    ->execute([':id' => $groupId]);
                // Derived series die naar deze groepen verwijzen
                $pdo->prepare(
                    'DELETE FROM derived_chart_series
                     WHERE left_group_id = :id OR right_group_id = :id'
                )->execute([':id' => $groupId]);
            }
            $pdo->prepare('DELETE FROM chart_groups WHERE chart_id = :chart_id AND company = :company')
                ->execute([':chart_id' => $chartId, ':company' => $company]);
        } else {
            $pdo->prepare('DELETE FROM derived_chart_series WHERE chart_id = :chart_id')
                ->execute([':chart_id' => $chartId]);
        }
        $pdo->prepare('DELETE FROM charts WHERE id = :id AND company = :company')
            ->execute([':id' => $chartId, ':company' => $company]);
        $pdo->commit();
    } catch (Throwable $error) {
        $pdo->rollBack();
        throw $error;
    }
}

/**
 * @return list<array{
 *   id: int,
 *   name: string,
 *   left_group_id: int,
 *   operator: string,
 *   right_group_id: int,
 *   sort_order: int,
 *   left_label?: string,
 *   right_label?: string
 * }>
 */
function moneta_list_derived_series(int $chartId): array
{
    $pdo = moneta_pdo();
    $statement = $pdo->prepare(
        'SELECT d.id, d.name, d.left_group_id, d.operator, d.right_group_id, d.sort_order,
                lg.name AS left_name, lc.name AS left_chart_name,
                rg.name AS right_name, rc.name AS right_chart_name
         FROM derived_chart_series d
         LEFT JOIN chart_groups lg ON lg.id = d.left_group_id
         LEFT JOIN charts lc ON lc.id = lg.chart_id
         LEFT JOIN chart_groups rg ON rg.id = d.right_group_id
         LEFT JOIN charts rc ON rc.id = rg.chart_id
         WHERE d.chart_id = :chart_id
         ORDER BY d.sort_order ASC, d.id ASC'
    );
    $statement->execute([':chart_id' => $chartId]);

    $rows = [];
    foreach ($statement->fetchAll() as $row) {
        $leftName = (string) ($row['left_name'] ?? '');
        $leftChart = (string) ($row['left_chart_name'] ?? '');
        $rightName = (string) ($row['right_name'] ?? '');
        $rightChart = (string) ($row['right_chart_name'] ?? '');
        $rows[] = [
            'id' => (int) ($row['id'] ?? 0),
            'name' => (string) ($row['name'] ?? ''),
            'left_group_id' => (int) ($row['left_group_id'] ?? 0),
            'operator' => moneta_normalize_derived_operator((string) ($row['operator'] ?? '+')),
            'right_group_id' => (int) ($row['right_group_id'] ?? 0),
            'sort_order' => (int) ($row['sort_order'] ?? 0),
            'left_label' => ($leftChart !== '' && $leftName !== '') ? ($leftChart . ' › ' . $leftName) : $leftName,
            'right_label' => ($rightChart !== '' && $rightName !== '') ? ($rightChart . ' › ' . $rightName) : $rightName,
        ];
    }

    return $rows;
}

function moneta_normalize_derived_operator(string $operator): string
{
    $operator = trim($operator);
    if ($operator === 'x' || $operator === 'X' || $operator === '×') {
        return '*';
    }
    if ($operator === '÷') {
        return '/';
    }
    if (!in_array($operator, ['+', '-', '*', '/'], true)) {
        return '+';
    }

    return $operator;
}

/**
 * @param list<array{
 *   id?: int|null,
 *   name: string,
 *   left_group_id: int,
 *   operator: string,
 *   right_group_id: int
 * }> $series
 * @return list<array>
 */
function moneta_save_derived_series(string $company, int $chartId, array $series): array
{
    $chart = moneta_get_chart($company, $chartId);
    if ($chart === null || $chart['chart_type'] !== MONETA_CHART_TYPE_DERIVED) {
        throw new InvalidArgumentException('Combinatiegrafiek niet gevonden.');
    }

    $validGroups = [];
    foreach (moneta_list_balance_group_options($company) as $opt) {
        $validGroups[(int) $opt['id']] = true;
    }

    $pdo = moneta_pdo();
    $now = gmdate('c');
    $pdo->beginTransaction();
    try {
        $existing = [];
        $idStatement = $pdo->prepare('SELECT id FROM derived_chart_series WHERE chart_id = :chart_id');
        $idStatement->execute([':chart_id' => $chartId]);
        foreach ($idStatement->fetchAll() as $row) {
            $existing[(int) $row['id']] = true;
        }

        $insert = $pdo->prepare(
            'INSERT INTO derived_chart_series
                (chart_id, name, left_group_id, operator, right_group_id, sort_order, created_at, updated_at)
             VALUES
                (:chart_id, :name, :left_group_id, :operator, :right_group_id, :sort_order, :created_at, :updated_at)'
        );
        $update = $pdo->prepare(
            'UPDATE derived_chart_series
             SET name = :name, left_group_id = :left_group_id, operator = :operator,
                 right_group_id = :right_group_id, sort_order = :sort_order, updated_at = :updated_at
             WHERE id = :id AND chart_id = :chart_id'
        );

        $keep = [];
        $sortOrder = 0;
        foreach ($series as $item) {
            if (!is_array($item)) {
                continue;
            }
            $leftId = (int) ($item['left_group_id'] ?? 0);
            $rightId = (int) ($item['right_group_id'] ?? 0);
            if ($leftId <= 0 || $rightId <= 0 || !isset($validGroups[$leftId], $validGroups[$rightId])) {
                continue;
            }
            $operator = moneta_normalize_derived_operator((string) ($item['operator'] ?? '+'));
            $name = trim((string) ($item['name'] ?? ''));
            if ($name === '') {
                $name = 'Serie ' . ($sortOrder + 1);
            }
            $id = isset($item['id']) ? (int) $item['id'] : 0;

            if ($id > 0 && isset($existing[$id])) {
                $update->execute([
                    ':name' => $name,
                    ':left_group_id' => $leftId,
                    ':operator' => $operator,
                    ':right_group_id' => $rightId,
                    ':sort_order' => $sortOrder,
                    ':updated_at' => $now,
                    ':id' => $id,
                    ':chart_id' => $chartId,
                ]);
            } else {
                $insert->execute([
                    ':chart_id' => $chartId,
                    ':name' => $name,
                    ':left_group_id' => $leftId,
                    ':operator' => $operator,
                    ':right_group_id' => $rightId,
                    ':sort_order' => $sortOrder,
                    ':created_at' => $now,
                    ':updated_at' => $now,
                ]);
                $id = (int) $pdo->lastInsertId();
            }
            $keep[$id] = true;
            $sortOrder++;
        }

        foreach (array_keys($existing) as $existingId) {
            if (!isset($keep[$existingId])) {
                $pdo->prepare('DELETE FROM derived_chart_series WHERE id = :id AND chart_id = :chart_id')
                    ->execute([':id' => $existingId, ':chart_id' => $chartId]);
            }
        }

        $pdo->commit();
    } catch (Throwable $error) {
        $pdo->rollBack();
        throw $error;
    }

    return moneta_list_derived_series($chartId);
}

/**
 * Alle balance-groepen als keuzelijst voor combinatiegrafieken.
 *
 * @return list<array{id: int, name: string, chart_id: int, chart_name: string, label: string}>
 */
function moneta_list_balance_group_options(string $company): array
{
    $pdo = moneta_pdo();
    $statement = $pdo->prepare(
        'SELECT g.id, g.name, g.chart_id, c.name AS chart_name
         FROM chart_groups g
         INNER JOIN charts c ON c.id = g.chart_id
         WHERE g.company = :company
           AND c.company = :company2
           AND c.chart_type = :type
         ORDER BY c.sort_order ASC, c.id ASC, g.sort_order ASC, g.id ASC'
    );
    $statement->execute([
        ':company' => $company,
        ':company2' => $company,
        ':type' => MONETA_CHART_TYPE_BALANCE,
    ]);

    $options = [];
    foreach ($statement->fetchAll() as $row) {
        $id = (int) ($row['id'] ?? 0);
        $name = (string) ($row['name'] ?? '');
        $chartName = (string) ($row['chart_name'] ?? '');
        if ($id <= 0) {
            continue;
        }
        $options[] = [
            'id' => $id,
            'name' => $name,
            'chart_id' => (int) ($row['chart_id'] ?? 0),
            'chart_name' => $chartName,
            'label' => $chartName . ' › ' . $name,
        ];
    }

    return $options;
}

/**
 * Chart data voor één grafiek (balance of derived) met gebruikersprognose vanaf morgen.
 *
 * @return array{
 *   labels: string[],
 *   series: list<array{account_no: string, name: string, data: list<float|null>}>,
 *   today: string,
 *   chart: array
 * }
 */
function moneta_chart_data_for_id(string $company, int $chartId, string $dateFrom, string $dateTo): array
{
    $dateFrom = moneta_parse_date($dateFrom);
    $dateTo = moneta_parse_date($dateTo);
    $today = date('Y-m-d');
    $empty = [
        'labels' => [],
        'series' => [],
        'today' => $today,
        'chart' => null,
    ];
    if ($dateFrom === '' || $dateTo === '') {
        return $empty;
    }
    if ($dateFrom > $dateTo) {
        $tmp = $dateFrom;
        $dateFrom = $dateTo;
        $dateTo = $tmp;
    }

    $chart = moneta_get_chart($company, $chartId);
    if ($chart === null) {
        return $empty;
    }

    if ($chart['chart_type'] === MONETA_CHART_TYPE_DERIVED) {
        $data = moneta_derived_chart_data($company, $chartId, $dateFrom, $dateTo, $today);
    } else {
        $data = moneta_balance_chart_data($company, $chartId, $dateFrom, $dateTo, $today);
    }

    $data['today'] = $today;
    $data['chart'] = $chart;

    return $data;
}

/**
 * @return array{labels: string[], series: list<array{account_no: string, name: string, data: list<float|null>}>}
 */
function moneta_balance_chart_data(
    string $company,
    int $chartId,
    string $dateFrom,
    string $dateTo,
    string $today
): array {
    $groups = moneta_list_chart_groups($company, $chartId);
    if ($groups === []) {
        return ['labels' => [], 'series' => []];
    }

    $groupMeta = [];
    $accountSignByGroup = [];
    foreach ($groups as $group) {
        $groupId = (int) ($group['id'] ?? 0);
        if ($groupId <= 0 || ($group['accounts'] ?? []) === []) {
            continue;
        }
        $groupMeta[$groupId] = (string) ($group['name'] ?? '');
        $signs = [];
        foreach ($group['accounts'] as $account) {
            $accountNo = trim((string) ($account['account_no'] ?? ''));
            if ($accountNo === '') {
                continue;
            }
            $negate = !empty($account['negate']);
            $signs[$accountNo] = $negate ? -1.0 : 1.0;
        }
        $accountSignByGroup[$groupId] = $signs;
    }
    if ($groupMeta === []) {
        return ['labels' => [], 'series' => []];
    }

    $pdo = moneta_pdo();
    // Membership signature company-wide (cache is company-scoped).
    $allGroups = moneta_list_chart_groups($company, null);
    $membership = moneta_groups_membership_signature($allGroups);
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
        ':date_to' => min($dateTo, $today),
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

    $labels = moneta_date_labels($dateFrom, $dateTo);
    $forecastFrom = (new DateTimeImmutable($today))->modify('+1 day')->format('Y-m-d');
    $forecastDeltas = [];
    if ($dateTo >= $forecastFrom) {
        $forecastDeltas = moneta_forecast_account_deltas(
            $company,
            max($forecastFrom, $dateFrom),
            $dateTo
        );
    }

    $series = [];
    foreach ($groupMeta as $groupId => $name) {
        $points = $pointsByGroup[$groupId] ?? [];
        $signs = $accountSignByGroup[$groupId] ?? [];
        $data = moneta_build_balance_series_data(
            $labels,
            $today,
            $points,
            $signs,
            $forecastDeltas
        );
        $hasValue = false;
        foreach ($data as $v) {
            if ($v !== null) {
                $hasValue = true;
                break;
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
 * History uit gecachte groepssom (incl. negate); forecast vanaf morgen
 * met ongesigneerde account-deltas (negate geldt alleen voor historie).
 *
 * @param list<string> $labels
 * @param array<string, float> $points date => signed balance
 * @param array<string, float> $signs account_no => ±1 (alleen membership voor forecast)
 * @param array<string, array<string, float>> $forecastDeltas
 * @return list<float|null>
 */
function moneta_build_balance_series_data(
    array $labels,
    string $today,
    array $points,
    array $signs,
    array $forecastDeltas
): array {
    $pointDates = array_keys($points);
    sort($pointDates);
    $pointIndex = 0;
    $pointCount = count($pointDates);
    $lastKnown = null;
    $data = [];

    foreach ($labels as $label) {
        if ($label <= $today) {
            while ($pointIndex < $pointCount && $pointDates[$pointIndex] <= $label) {
                $lastKnown = (float) $points[$pointDates[$pointIndex]];
                $pointIndex++;
            }
            $data[] = $lastKnown;
            continue;
        }

        if ($lastKnown === null) {
            $data[] = null;
            continue;
        }

        $dayDelta = 0.0;
        foreach ($signs as $accountNo => $_sign) {
            if (isset($forecastDeltas[$accountNo][$label])) {
                // Negate beïnvloedt alleen de historische groepssom, niet de prognose.
                $dayDelta += (float) $forecastDeltas[$accountNo][$label];
            }
        }
        $lastKnown += $dayDelta;
        $data[] = $lastKnown;
    }

    return $data;
}

/**
 * @return array{labels: string[], series: list<array{account_no: string, name: string, data: list<float|null>}>}
 */
function moneta_derived_chart_data(
    string $company,
    int $chartId,
    string $dateFrom,
    string $dateTo,
    string $today
): array {
    $derived = moneta_list_derived_series($chartId);
    if ($derived === []) {
        return ['labels' => [], 'series' => []];
    }

    $neededGroupIds = [];
    foreach ($derived as $row) {
        $neededGroupIds[(int) $row['left_group_id']] = true;
        $neededGroupIds[(int) $row['right_group_id']] = true;
    }

    // Laad balance-series per groep via hun chart.
    $pdo = moneta_pdo();
    $groupChart = $pdo->prepare(
        'SELECT id, chart_id FROM chart_groups WHERE id = :id AND company = :company'
    );
    $seriesByGroupId = [];
    $labels = null;

    foreach (array_keys($neededGroupIds) as $groupId) {
        if ($groupId <= 0) {
            continue;
        }
        $groupChart->execute([':id' => $groupId, ':company' => $company]);
        $gRow = $groupChart->fetch();
        if (!$gRow) {
            continue;
        }
        $balanceChartId = (int) $gRow['chart_id'];
        $balanceData = moneta_balance_chart_data($company, $balanceChartId, $dateFrom, $dateTo, $today);
        if ($labels === null) {
            $labels = $balanceData['labels'];
        }
        foreach ($balanceData['series'] as $s) {
            $key = (string) ($s['account_no'] ?? '');
            if ($key === 'group:' . $groupId) {
                $seriesByGroupId[$groupId] = $s['data'];
            }
        }
    }

    if ($labels === null) {
        $labels = moneta_date_labels($dateFrom, $dateTo);
    }

    $outSeries = [];
    foreach ($derived as $row) {
        $leftId = (int) $row['left_group_id'];
        $rightId = (int) $row['right_group_id'];
        $left = $seriesByGroupId[$leftId] ?? null;
        $right = $seriesByGroupId[$rightId] ?? null;
        if ($left === null || $right === null) {
            continue;
        }
        $op = moneta_normalize_derived_operator((string) $row['operator']);
        $data = [];
        $count = count($labels);
        for ($i = 0; $i < $count; $i++) {
            $a = $left[$i] ?? null;
            $b = $right[$i] ?? null;
            if ($a === null || $b === null) {
                $data[] = null;
                continue;
            }
            $a = (float) $a;
            $b = (float) $b;
            if ($op === '+') {
                $data[] = $a + $b;
            } elseif ($op === '-') {
                $data[] = $a - $b;
            } elseif ($op === '*') {
                $data[] = $a * $b;
            } elseif ($op === '/') {
                $data[] = abs($b) < 0.0000001 ? null : ($a / $b);
            } else {
                $data[] = null;
            }
        }
        $outSeries[] = [
            'account_no' => 'derived:' . (int) $row['id'],
            'name' => (string) $row['name'],
            'data' => $data,
        ];
    }

    return [
        'labels' => $labels,
        'series' => $outSeries,
    ];
}

/**
 * @return list<string>
 */
function moneta_date_labels(string $dateFrom, string $dateTo): array
{
    $labels = [];
    $cursor = new DateTimeImmutable($dateFrom);
    $end = new DateTimeImmutable($dateTo);
    while ($cursor <= $end) {
        $labels[] = $cursor->format('Y-m-d');
        $cursor = $cursor->modify('+1 day');
    }

    return $labels;
}

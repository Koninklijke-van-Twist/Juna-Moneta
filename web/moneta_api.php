<?php

/**
 * JSON API voor grafieken, groepen, combinatieseries en gebruikersprognose.
 */

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/logincheck.php';
require_once __DIR__ . '/odata.php';
require_once __DIR__ . '/auth_helper.php';
require_once __DIR__ . '/moneta_data.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

/**
 * @return never
 */
function moneta_api_json(array $payload, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/**
 * @return never
 */
function moneta_api_error(string $message, int $status = 400): void
{
    moneta_api_json(['ok' => false, 'error' => $message], $status);
}

/**
 * @return array<string, mixed>
 */
function moneta_api_json_body(): array
{
    $raw = file_get_contents('php://input');
    $body = is_string($raw) ? json_decode($raw, true) : null;
    if (!is_array($body)) {
        moneta_api_error('JSON body verwacht.');
    }

    return $body;
}

$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$action = trim((string) ($_GET['action'] ?? $_POST['action'] ?? ''));
$company = trim((string) ($_GET['company'] ?? $_POST['company'] ?? ''));

$companies = project_companies_for_page();
if ($company === '' || !in_array($company, $companies, true)) {
    moneta_api_error('Ongeldig of ontbrekend bedrijf.', 400);
}

auth_set_current_company_context($company);

try {
    if ($action === 'charts' && $method === 'GET') {
        moneta_api_json([
            'ok' => true,
            'company' => $company,
            'charts' => moneta_list_charts($company, true),
            'group_options' => moneta_list_balance_group_options($company),
        ]);
    }

    if ($action === 'save_chart' && $method === 'POST') {
        $body = moneta_api_json_body();
        $chartId = (int) ($body['id'] ?? 0);
        if ($chartId > 0) {
            $chart = moneta_update_chart($company, $chartId, $body);
        } else {
            $chart = moneta_create_chart($company, $body);
        }
        moneta_api_json([
            'ok' => true,
            'company' => $company,
            'chart' => $chart,
            'charts' => moneta_list_charts($company, true),
            'saved_at' => gmdate('c'),
        ]);
    }

    if ($action === 'delete_chart' && $method === 'POST') {
        $body = moneta_api_json_body();
        $chartId = (int) ($body['id'] ?? $_GET['id'] ?? 0);
        if ($chartId <= 0) {
            moneta_api_error('Grafiek-id is verplicht.');
        }
        moneta_delete_chart($company, $chartId);
        moneta_api_json([
            'ok' => true,
            'company' => $company,
            'charts' => moneta_list_charts($company, true),
        ]);
    }

    if ($action === 'groups' && $method === 'GET') {
        $chartId = (int) ($_GET['chart_id'] ?? 0);
        if ($chartId <= 0) {
            $chartId = moneta_ensure_default_balance_chart($company);
        }
        moneta_api_json([
            'ok' => true,
            'company' => $company,
            'chart_id' => $chartId,
            'groups' => moneta_list_chart_groups($company, $chartId),
        ]);
    }

    if ($action === 'gl_accounts' && $method === 'GET') {
        moneta_api_json([
            'ok' => true,
            'company' => $company,
            'accounts' => moneta_list_gl_accounts($company),
        ]);
    }

    if ($action === 'save_groups' && $method === 'POST') {
        $body = moneta_api_json_body();
        $groups = $body['groups'] ?? null;
        if (!is_array($groups)) {
            moneta_api_error('Veld groups (array) is verplicht.');
        }
        $chartId = (int) ($body['chart_id'] ?? 0);
        if ($chartId <= 0) {
            $chartId = moneta_ensure_default_balance_chart($company);
        }
        $saved = moneta_save_chart_groups($company, $groups, $chartId);
        moneta_api_json([
            'ok' => true,
            'company' => $company,
            'chart_id' => $chartId,
            'groups' => $saved,
            'group_options' => moneta_list_balance_group_options($company),
            'saved_at' => gmdate('c'),
        ]);
    }

    if ($action === 'save_derived' && $method === 'POST') {
        $body = moneta_api_json_body();
        $chartId = (int) ($body['chart_id'] ?? 0);
        $series = $body['series'] ?? null;
        if ($chartId <= 0) {
            moneta_api_error('chart_id is verplicht.');
        }
        if (!is_array($series)) {
            moneta_api_error('Veld series (array) is verplicht.');
        }
        $saved = moneta_save_derived_series($company, $chartId, $series);
        moneta_api_json([
            'ok' => true,
            'company' => $company,
            'chart_id' => $chartId,
            'derived_series' => $saved,
            'saved_at' => gmdate('c'),
        ]);
    }

    if ($action === 'chart_data' && $method === 'GET') {
        $dateFrom = moneta_parse_date((string) ($_GET['date_from'] ?? ''));
        $dateTo = moneta_parse_date((string) ($_GET['date_to'] ?? ''));
        if ($dateFrom === '') {
            $dateFrom = moneta_default_date_from();
        }
        if ($dateTo === '') {
            $dateTo = moneta_default_date_to();
        }
        $chartId = (int) ($_GET['chart_id'] ?? 0);
        if ($chartId <= 0) {
            $chartId = moneta_ensure_default_balance_chart($company);
        }
        $chart = moneta_chart_data_for_id($company, $chartId, $dateFrom, $dateTo);
        moneta_api_json([
            'ok' => true,
            'company' => $company,
            'chart_id' => $chartId,
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
            'chart' => $chart,
            'groups' => moneta_list_chart_groups($company, $chartId),
        ]);
    }

    if ($action === 'forecast' && $method === 'GET') {
        $purged = moneta_purge_expired_forecasts($company);
        moneta_api_json([
            'ok' => true,
            'company' => $company,
            'one_time' => moneta_list_forecast_one_time($company),
            'rules' => moneta_list_forecast_rules($company),
            'purged' => $purged,
        ]);
    }

    if ($action === 'forecast_one_time' && $method === 'GET') {
        moneta_purge_expired_forecasts($company);
        moneta_api_json([
            'ok' => true,
            'company' => $company,
            'items' => moneta_list_forecast_one_time($company),
        ]);
    }

    if ($action === 'upsert_forecast_one_time' && $method === 'POST') {
        $body = moneta_api_json_body();
        $item = $body['item'] ?? $body;
        if (!is_array($item)) {
            moneta_api_error('Veld item is verplicht.');
        }
        $saved = moneta_upsert_forecast_one_time($company, $item);
        moneta_api_json([
            'ok' => true,
            'company' => $company,
            'item' => $saved,
            'saved_at' => gmdate('c'),
        ]);
    }

    if ($action === 'delete_forecast_one_time' && $method === 'POST') {
        $body = moneta_api_json_body();
        $id = (int) ($body['id'] ?? 0);
        moneta_delete_forecast_one_time($company, $id);
        moneta_api_json(['ok' => true, 'company' => $company, 'id' => $id]);
    }

    if ($action === 'save_forecast_one_time' && $method === 'POST') {
        $body = moneta_api_json_body();
        $items = $body['items'] ?? null;
        if (!is_array($items)) {
            moneta_api_error('Veld items (array) is verplicht.');
        }
        $saved = moneta_save_forecast_one_time($company, $items);
        moneta_api_json([
            'ok' => true,
            'company' => $company,
            'items' => $saved,
            'saved_at' => gmdate('c'),
        ]);
    }

    if ($action === 'forecast_rules' && $method === 'GET') {
        moneta_purge_expired_forecasts($company);
        moneta_api_json([
            'ok' => true,
            'company' => $company,
            'items' => moneta_list_forecast_rules($company),
        ]);
    }

    if ($action === 'upsert_forecast_rule' && $method === 'POST') {
        $body = moneta_api_json_body();
        $item = $body['item'] ?? $body;
        if (!is_array($item)) {
            moneta_api_error('Veld item is verplicht.');
        }
        $saved = moneta_upsert_forecast_rule($company, $item);
        moneta_api_json([
            'ok' => true,
            'company' => $company,
            'item' => $saved,
            'saved_at' => gmdate('c'),
        ]);
    }

    if ($action === 'delete_forecast_rule' && $method === 'POST') {
        $body = moneta_api_json_body();
        $id = (int) ($body['id'] ?? 0);
        moneta_delete_forecast_rule($company, $id);
        moneta_api_json(['ok' => true, 'company' => $company, 'id' => $id]);
    }

    if ($action === 'save_forecast_rules' && $method === 'POST') {
        $body = moneta_api_json_body();
        $items = $body['items'] ?? null;
        if (!is_array($items)) {
            moneta_api_error('Veld items (array) is verplicht.');
        }
        $saved = moneta_save_forecast_rules($company, $items);
        moneta_api_json([
            'ok' => true,
            'company' => $company,
            'items' => $saved,
            'saved_at' => gmdate('c'),
        ]);
    }

    moneta_api_error('Onbekende action of methode.', 404);
} catch (Throwable $error) {
    moneta_api_error($error->getMessage(), 500);
}

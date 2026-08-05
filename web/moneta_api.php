<?php

/**
 * JSON API voor grafiekgroepen (autosave) en Rekeningschema-picker.
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

$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$action = trim((string) ($_GET['action'] ?? $_POST['action'] ?? ''));
$company = trim((string) ($_GET['company'] ?? $_POST['company'] ?? ''));

$companies = project_companies_for_page();
if ($company === '' || !in_array($company, $companies, true)) {
    moneta_api_error('Ongeldig of ontbrekend bedrijf.', 400);
}

auth_set_current_company_context($company);

try {
    if ($action === 'groups' && $method === 'GET') {
        moneta_api_json([
            'ok' => true,
            'company' => $company,
            'groups' => moneta_list_chart_groups($company),
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
        $raw = file_get_contents('php://input');
        $body = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($body)) {
            moneta_api_error('JSON body verwacht.');
        }
        $groups = $body['groups'] ?? null;
        if (!is_array($groups)) {
            moneta_api_error('Veld groups (array) is verplicht.');
        }
        $saved = moneta_save_chart_groups($company, $groups);
        moneta_api_json([
            'ok' => true,
            'company' => $company,
            'groups' => $saved,
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
        $chart = moneta_group_chart_data($company, $dateFrom, $dateTo);
        moneta_api_json([
            'ok' => true,
            'company' => $company,
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
            'chart' => $chart,
            'groups' => moneta_list_chart_groups($company),
        ]);
    }

    moneta_api_error('Onbekende action of methode.', 404);
} catch (Throwable $error) {
    moneta_api_error($error->getMessage(), 500);
}

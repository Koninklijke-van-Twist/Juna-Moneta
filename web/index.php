<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/logincheck.php';
require_once __DIR__ . '/localization.php';
require_once __DIR__ . '/odata.php';
require_once __DIR__ . '/auth_helper.php';
require_once __DIR__ . '/moneta_data.php';

function moneta_h(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

$companies = project_companies_for_page();
$prefEmail = strtolower(trim((string) ($_SESSION['user']['email'] ?? '')));
$savedCompany = '';
if ($prefEmail !== '') {
    $savedCompany = trim((string) (loadUserPrefs($prefEmail)['company'] ?? ''));
}

$requestedCompany = trim((string) ($_GET['company'] ?? ''));
if ($requestedCompany !== '' && in_array($requestedCompany, $companies, true)) {
    $company = $requestedCompany;
    if ($prefEmail !== '' && $requestedCompany !== $savedCompany) {
        saveUserPref($prefEmail, 'company', $requestedCompany);
    }
} elseif ($savedCompany !== '' && in_array($savedCompany, $companies, true)) {
    $company = $savedCompany;
} else {
    $company = (string) ($companies[0] ?? '');
}

$dateFrom = moneta_parse_date((string) ($_GET['date_from'] ?? ''));
$dateTo = moneta_parse_date((string) ($_GET['date_to'] ?? ''));
if ($dateFrom === '') {
    $dateFrom = moneta_default_date_from();
}
if ($dateTo === '') {
    $dateTo = moneta_default_date_to();
}
if ($dateFrom > $dateTo) {
    $tmp = $dateFrom;
    $dateFrom = $dateTo;
    $dateTo = $tmp;
}

auth_set_current_company_context($company);

$errorKey = '';
$charts = [];
$groupOptions = [];
$initialChartPayloads = [];

try {
    $charts = moneta_list_charts($company, true);
    $groupOptions = moneta_list_balance_group_options($company);
    foreach ($charts as $chart) {
        $chartId = (int) ($chart['id'] ?? 0);
        if ($chartId <= 0) {
            continue;
        }
        $initialChartPayloads[$chartId] = moneta_chart_data_for_id($company, $chartId, $dateFrom, $dateTo);
    }
} catch (Throwable $loadError) {
    $errorKey = 'moneta.error.load_failed';
}

$chartsJson = json_encode($charts, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
$payloadsJson = json_encode($initialChartPayloads, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
$groupOptionsJson = json_encode($groupOptions, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
if (!is_string($chartsJson)) {
    $chartsJson = '[]';
}
if (!is_string($payloadsJson)) {
    $payloadsJson = '{}';
}
if (!is_string($groupOptionsJson)) {
    $groupOptionsJson = '[]';
}

$today = date('Y-m-d');

?><!DOCTYPE html>
<html lang="<?= moneta_h(getHtmlLang()) ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= moneta_h(LOC('app.title')) ?></title>
    <link rel="stylesheet" href="brand.css">
    <link rel="manifest" href="site.webmanifest">
    <link rel="icon" href="doc.svg" type="image/svg+xml">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.7/dist/chart.umd.min.js"></script>
    <?php renderLanguageSwitcherStyles(); ?>
    <style>
        .moneta-page { max-width: 1200px; margin: 0 auto; padding: 16px; }
        .moneta-header { display: flex; flex-wrap: wrap; gap: 12px; align-items: center; justify-content: space-between; margin-bottom: 20px; }
        .moneta-header img { max-height: 42px; width: auto; }
        .moneta-header-actions { display: flex; flex-wrap: wrap; gap: 12px; align-items: center; margin-left: auto; }
        .moneta-card { background: var(--kvt-panel-bg); border: 1px solid var(--kvt-line); border-radius: 12px; padding: 16px; margin-bottom: 16px; }
        .moneta-card h1, .moneta-card h2 { margin: 0 0 8px; color: var(--kvt-text); }
        .moneta-subtitle { color: var(--kvt-muted); margin: 0 0 16px; }
        .moneta-form { display: grid; gap: 12px; }
        .moneta-form-grid { display: grid; gap: 12px; }
        .moneta-form label { display: grid; gap: 6px; font-weight: 700; color: var(--kvt-muted); }
        .moneta-form input, .moneta-form select, .moneta-btn, .moneta-inline-input {
            font: inherit; border-radius: 10px; border: 1px solid var(--kvt-line); padding: 12px 14px;
        }
        .moneta-form input, .moneta-form select, .moneta-inline-input { width: 100%; box-sizing: border-box; }
        .moneta-btn {
            background: var(--kvt-main-blue); color: #fff; border-color: var(--kvt-main-blue);
            cursor: pointer; text-decoration: none; display: inline-block; text-align: center;
        }
        .moneta-btn-secondary {
            background: #fff; color: var(--kvt-main-blue); border: 1px solid var(--kvt-main-blue);
            cursor: pointer; border-radius: 10px; padding: 10px 14px; font: inherit;
        }
        .moneta-btn-icon {
            width: 32px; height: 32px; border-radius: 8px; border: 1px solid var(--kvt-line);
            background: #fff; color: var(--kvt-text); cursor: pointer; font: inherit; line-height: 1;
            flex: 0 0 auto;
        }
        .moneta-btn-danger { color: var(--kvt-danger); border-color: #fecaca; }
        .moneta-toolbar { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; }
        .moneta-alert {
            border: 1px solid #fecaca; background: #fef2f2; color: var(--kvt-danger);
            border-radius: 10px; padding: 12px 14px; margin-bottom: 16px;
        }
        .moneta-empty {
            border: 1px dashed var(--kvt-line); border-radius: 10px; padding: 24px 16px;
            color: var(--kvt-muted); text-align: center;
        }
        .moneta-empty .moneta-btn, .moneta-empty .moneta-btn-secondary { margin-top: 12px; }
        .moneta-chart-wrap { position: relative; height: 320px; width: 100%; }
        .moneta-section-title { font-size: 0.82rem; text-transform: uppercase; letter-spacing: 0.04em; color: var(--kvt-muted); margin: 4px 0 8px; }
        .moneta-chart-head { display: flex; flex-wrap: wrap; gap: 12px; align-items: flex-start; justify-content: space-between; margin-bottom: 8px; }
        .moneta-chart-head > div { flex: 1 1 240px; }
        .moneta-title-input {
            font: inherit; font-size: 1.25rem; font-weight: 700; color: var(--kvt-text);
            border: 1px solid transparent; border-radius: 8px; padding: 4px 8px; width: 100%;
            box-sizing: border-box; background: transparent;
        }
        .moneta-title-input:hover, .moneta-title-input:focus {
            border-color: var(--kvt-line); background: #fff; outline: none;
        }
        .moneta-save-state { font-size: 0.85rem; color: var(--kvt-muted); min-height: 1.2em; }
        .moneta-modal-backdrop {
            position: fixed; inset: 0; z-index: 13000; display: none; align-items: flex-start; justify-content: center;
            padding: 16px; background: rgba(15, 23, 42, 0.45); overflow: auto;
        }
        .moneta-modal-backdrop.is-open { display: flex; }
        .moneta-modal {
            background: #fff; border-radius: 14px; border: 1px solid var(--kvt-line);
            width: min(860px, 100%); max-height: none; margin: 24px auto;
            padding: 16px; box-shadow: 0 18px 40px rgba(15, 23, 42, 0.18);
        }
        .moneta-modal h3 { margin: 0 0 8px; }
        .moneta-modal-actions { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 12px; }
        .moneta-group {
            border: 1px solid var(--kvt-line); border-radius: 10px; padding: 12px; margin-bottom: 10px;
        }
        .moneta-group-head { display: flex; gap: 8px; align-items: center; margin-bottom: 8px; }
        .moneta-group-head input[type="text"] {
            flex: 1; font: inherit; border-radius: 8px; border: 1px solid var(--kvt-line); padding: 8px 10px;
        }
        .moneta-account-list { display: grid; gap: 6px; margin: 0; padding: 0; list-style: none; }
        .moneta-account-row {
            display: flex; flex-wrap: wrap; gap: 8px; align-items: center; justify-content: space-between;
            padding: 6px 8px; border-radius: 8px; background: #f8fafc;
        }
        .moneta-account-row span { color: var(--kvt-text); font-size: 0.92rem; flex: 1 1 160px; }
        .moneta-negate {
            display: inline-flex; align-items: center; gap: 6px; font-size: 0.85rem; color: var(--kvt-muted);
            white-space: nowrap;
        }
        .moneta-picker-search, .moneta-row-grid input, .moneta-row-grid select {
            width: 100%; box-sizing: border-box; font: inherit;
            border-radius: 8px; border: 1px solid var(--kvt-line); padding: 10px 12px;
        }
        .moneta-picker-list { max-height: 360px; overflow: auto; display: grid; gap: 4px; }
        .moneta-picker-item {
            display: flex; justify-content: space-between; gap: 8px; width: 100%; text-align: left;
            border: 1px solid var(--kvt-line); background: #fff; border-radius: 8px; padding: 8px 10px;
            cursor: pointer; font: inherit;
        }
        .moneta-picker-item:hover { border-color: var(--kvt-main-blue); }
        .moneta-picker-item small { color: var(--kvt-muted); }
        .moneta-row {
            border: 1px solid var(--kvt-line); border-radius: 10px; padding: 12px; margin-bottom: 10px;
            display: grid; gap: 10px;
        }
        .moneta-row-grid { display: grid; gap: 8px; }
        .moneta-row-grid label { display: grid; gap: 4px; font-size: 0.82rem; color: var(--kvt-muted); font-weight: 700; }
        .moneta-ops { display: flex; flex-wrap: wrap; gap: 6px; }
        .moneta-ops button {
            min-width: 40px; padding: 8px 12px; border-radius: 8px; border: 1px solid var(--kvt-line);
            background: #fff; cursor: pointer; font: inherit;
        }
        .moneta-ops button.is-active {
            background: var(--kvt-main-blue); color: #fff; border-color: var(--kvt-main-blue);
        }
        .moneta-charts-actions { display: flex; flex-wrap: wrap; gap: 8px; margin-bottom: 16px; }
        .moneta-forecast-filters {
            display: grid; gap: 8px; margin-bottom: 12px;
        }
        .moneta-forecast-list {
            max-height: min(42vh, 420px); overflow: auto; border: 1px solid var(--kvt-line);
            border-radius: 10px; contain: content;
        }
        .moneta-forecast-row {
            display: flex; gap: 8px; align-items: center; justify-content: space-between;
            padding: 8px 10px; border-bottom: 1px solid var(--kvt-line); background: #fff;
        }
        .moneta-forecast-row:last-child { border-bottom: 0; }
        .moneta-forecast-row-main { min-width: 0; flex: 1; }
        .moneta-forecast-row-title {
            font-weight: 700; color: var(--kvt-text); white-space: nowrap;
            overflow: hidden; text-overflow: ellipsis;
        }
        .moneta-forecast-row-meta {
            font-size: 0.82rem; color: var(--kvt-muted); white-space: nowrap;
            overflow: hidden; text-overflow: ellipsis;
        }
        .moneta-forecast-row-actions { display: flex; gap: 4px; flex: 0 0 auto; }
        .moneta-forecast-edit { display: none; }
        .moneta-forecast-edit.is-open { display: block; }
        .moneta-forecast-browse.is-hidden { display: none; }
        .moneta-section-count { font-weight: 400; text-transform: none; letter-spacing: 0; }
        @media (min-width: 640px) {
            .moneta-form-grid { grid-template-columns: 1.4fr 1fr 1fr auto; align-items: end; }
            .moneta-chart-wrap { height: 400px; }
            .moneta-row-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
            .moneta-row-grid.moneta-row-grid-3 { grid-template-columns: repeat(3, minmax(0, 1fr)); }
            .moneta-row-grid.moneta-row-grid-4 { grid-template-columns: repeat(4, minmax(0, 1fr)); }
            .moneta-forecast-filters { grid-template-columns: 1.4fr 1fr; }
        }
        .moneta-loader {
            position: fixed; inset: 0; z-index: 12000; display: flex; align-items: center;
            justify-content: center; padding: 24px; background: rgba(255, 255, 255, 0.72);
            backdrop-filter: blur(2px); opacity: 0; pointer-events: none; transition: opacity 0.15s ease;
        }
        .moneta-loader.is-visible { opacity: 1; pointer-events: auto; }
        .moneta-loader-card {
            background: #fff; border: 1px solid var(--kvt-line); border-radius: 14px;
            padding: 18px 20px; box-shadow: 0 12px 30px rgba(15, 23, 42, 0.12); text-align: center;
            min-width: min(280px, 100%);
        }
        .moneta-loader-spinner {
            width: 34px; height: 34px; margin: 0 auto 12px; border-radius: 50%;
            border: 3px solid #dbeafe; border-top-color: var(--kvt-main-blue); animation: moneta-spin 0.8s linear infinite;
        }
        @keyframes moneta-spin { to { transform: rotate(360deg); } }
    </style>
</head>
<body>
<div class="moneta-page">
    <header class="moneta-header">
        <img src="logo-website.png" alt="KVT">
        <div class="moneta-header-actions">
            <?php renderLanguageSwitcher(); ?>
        </div>
    </header>

    <section class="moneta-card">
        <h1><?= moneta_h(LOC('moneta.hero.title')) ?></h1>
        <p class="moneta-subtitle"><?= moneta_h(LOC('moneta.hero.subtitle')) ?></p>

        <form class="moneta-form" method="get" action="index.php">
            <div class="moneta-form-grid">
                <label>
                    <?= moneta_h(LOC('moneta.label.company')) ?>
                    <select name="company">
                        <?php foreach ($companies as $companyOption): ?>
                            <option value="<?= moneta_h($companyOption) ?>"<?= $companyOption === $company ? ' selected' : '' ?>>
                                <?= moneta_h($companyOption) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label>
                    <?= moneta_h(LOC('moneta.label.date_from')) ?>
                    <input type="date" name="date_from" value="<?= moneta_h($dateFrom) ?>" required>
                </label>
                <label>
                    <?= moneta_h(LOC('moneta.label.date_to')) ?>
                    <input type="date" name="date_to" value="<?= moneta_h($dateTo) ?>" required>
                </label>
                <div class="moneta-toolbar">
                    <button class="moneta-btn contract-nav" type="submit"><?= moneta_h(LOC('moneta.btn.apply')) ?></button>
                    <button type="button" class="moneta-btn-secondary" id="moneta-open-forecast"><?= moneta_h(LOC('moneta.btn.forecast')) ?></button>
                </div>
            </div>
        </form>
    </section>

    <?php if ($errorKey !== ''): ?>
        <div class="moneta-alert"><?= moneta_h(LOC($errorKey)) ?></div>
    <?php endif; ?>

    <div class="moneta-charts-actions">
        <button type="button" class="moneta-btn" id="moneta-add-balance-chart"><?= moneta_h(LOC('moneta.btn.add_balance_chart')) ?></button>
        <button type="button" class="moneta-btn-secondary" id="moneta-add-derived-chart"><?= moneta_h(LOC('moneta.btn.add_derived_chart')) ?></button>
    </div>

    <div id="moneta-charts-root"></div>
</div>

<div class="moneta-modal-backdrop" id="moneta-groups-modal" aria-hidden="true">
    <div class="moneta-modal" role="dialog" aria-modal="true" aria-labelledby="moneta-groups-title">
        <h3 id="moneta-groups-title"><?= moneta_h(LOC('moneta.groups.title')) ?></h3>
        <p class="moneta-subtitle"><?= moneta_h(LOC('moneta.groups.subtitle')) ?></p>
        <p class="moneta-save-state" id="moneta-groups-save-state"></p>
        <div id="moneta-groups-list"></div>
        <div class="moneta-modal-actions">
            <button type="button" class="moneta-btn" id="moneta-add-group"><?= moneta_h(LOC('moneta.groups.add')) ?></button>
            <button type="button" class="moneta-btn-secondary" id="moneta-close-groups"><?= moneta_h(LOC('moneta.groups.close')) ?></button>
        </div>
    </div>
</div>

<div class="moneta-modal-backdrop" id="moneta-derived-modal" aria-hidden="true">
    <div class="moneta-modal" role="dialog" aria-modal="true" aria-labelledby="moneta-derived-title">
        <h3 id="moneta-derived-title"><?= moneta_h(LOC('moneta.derived.title')) ?></h3>
        <p class="moneta-subtitle"><?= moneta_h(LOC('moneta.derived.subtitle')) ?></p>
        <p class="moneta-save-state" id="moneta-derived-save-state"></p>
        <div id="moneta-derived-list"></div>
        <div class="moneta-modal-actions">
            <button type="button" class="moneta-btn" id="moneta-add-derived-series"><?= moneta_h(LOC('moneta.derived.add')) ?></button>
            <button type="button" class="moneta-btn-secondary" id="moneta-close-derived"><?= moneta_h(LOC('moneta.groups.close')) ?></button>
        </div>
    </div>
</div>

<div class="moneta-modal-backdrop" id="moneta-forecast-modal" aria-hidden="true">
    <div class="moneta-modal" role="dialog" aria-modal="true" aria-labelledby="moneta-forecast-title">
        <h3 id="moneta-forecast-title"><?= moneta_h(LOC('moneta.forecast.title')) ?></h3>
        <p class="moneta-subtitle"><?= moneta_h(LOC('moneta.forecast.subtitle')) ?></p>
        <p class="moneta-save-state" id="moneta-forecast-save-state"></p>

        <div id="moneta-forecast-browse">
            <div class="moneta-forecast-filters">
                <input type="search" class="moneta-picker-search" id="moneta-forecast-search"
                       placeholder="<?= moneta_h(LOC('moneta.forecast.search')) ?>" style="margin:0">
                <select id="moneta-forecast-account-filter" aria-label="<?= moneta_h(LOC('moneta.forecast.filter_account')) ?>">
                    <option value=""><?= moneta_h(LOC('moneta.forecast.all_accounts')) ?></option>
                </select>
            </div>

            <p class="moneta-section-title">
                <?= moneta_h(LOC('moneta.forecast.one_time')) ?>
                <span class="moneta-section-count" id="moneta-one-time-count"></span>
            </p>
            <div class="moneta-forecast-list" id="moneta-forecast-one-time-list"></div>
            <div class="moneta-modal-actions">
                <button type="button" class="moneta-btn" id="moneta-add-one-time"><?= moneta_h(LOC('moneta.forecast.add_one_time')) ?></button>
            </div>

            <p class="moneta-section-title" style="margin-top:16px">
                <?= moneta_h(LOC('moneta.forecast.rules')) ?>
                <span class="moneta-section-count" id="moneta-rules-count"></span>
            </p>
            <div class="moneta-forecast-list" id="moneta-forecast-rules-list"></div>
            <div class="moneta-modal-actions">
                <button type="button" class="moneta-btn" id="moneta-add-rule"><?= moneta_h(LOC('moneta.forecast.add_rule')) ?></button>
                <button type="button" class="moneta-btn-secondary" id="moneta-close-forecast"><?= moneta_h(LOC('moneta.groups.close')) ?></button>
            </div>
        </div>

        <div class="moneta-forecast-edit" id="moneta-forecast-edit">
            <p class="moneta-section-title" id="moneta-forecast-edit-heading"><?= moneta_h(LOC('moneta.forecast.edit_title')) ?></p>
            <div id="moneta-forecast-edit-form"></div>
            <div class="moneta-modal-actions">
                <button type="button" class="moneta-btn" id="moneta-forecast-save-item"><?= moneta_h(LOC('moneta.forecast.save_item')) ?></button>
                <button type="button" class="moneta-btn-secondary" id="moneta-forecast-cancel-edit"><?= moneta_h(LOC('moneta.forecast.cancel_edit')) ?></button>
                <button type="button" class="moneta-btn-icon moneta-btn-danger" id="moneta-forecast-delete-item" title="<?= moneta_h(LOC('moneta.forecast.remove')) ?>">×</button>
            </div>
        </div>
    </div>
</div>

<div class="moneta-modal-backdrop" id="moneta-picker-modal" aria-hidden="true">
    <div class="moneta-modal" role="dialog" aria-modal="true" aria-labelledby="moneta-picker-title">
        <h3 id="moneta-picker-title"><?= moneta_h(LOC('moneta.picker.title')) ?></h3>
        <p class="moneta-subtitle"><?= moneta_h(LOC('moneta.picker.subtitle')) ?></p>
        <input type="search" class="moneta-picker-search" id="moneta-picker-search" placeholder="<?= moneta_h(LOC('moneta.picker.search')) ?>">
        <div class="moneta-picker-list" id="moneta-picker-list"></div>
        <div class="moneta-modal-actions">
            <button type="button" class="moneta-btn-secondary" id="moneta-close-picker"><?= moneta_h(LOC('moneta.groups.close')) ?></button>
        </div>
    </div>
</div>

<div class="moneta-loader" id="moneta-loader" aria-hidden="true">
    <div class="moneta-loader-card">
        <div class="moneta-loader-spinner" aria-hidden="true"></div>
        <div><?= moneta_h(LOC('moneta.loader.wait')) ?></div>
    </div>
</div>

<?= injectTimerHtml([
    'label' => 'Cache',
    'title' => 'Cachebestanden',
]) ?>

<script>
(function () {
    const loader = document.getElementById('moneta-loader');
    let loaderTimer = null;

    function showLoader() {
        if (!loader) return;
        clearTimeout(loaderTimer);
        loaderTimer = setTimeout(function () {
            loader.classList.add('is-visible');
            loader.setAttribute('aria-hidden', 'false');
        }, 500);
    }

    document.querySelectorAll('a.contract-nav, button.contract-nav, form .contract-nav').forEach(function (el) {
        const form = el.closest('form');
        if (form) form.addEventListener('submit', showLoader);
        else el.addEventListener('click', showLoader);
    });

    const palette = [
        '#00529B', '#0099cc', '#15803d', '#b45309', '#be123c',
        '#0f766e', '#7c3aed', '#0369a1', '#4d7c0f', '#c2410c'
    ];

    const company = <?= json_encode($company, JSON_UNESCAPED_UNICODE) ?>;
    const dateFrom = <?= json_encode($dateFrom, JSON_UNESCAPED_UNICODE) ?>;
    const dateTo = <?= json_encode($dateTo, JSON_UNESCAPED_UNICODE) ?>;
    const today = <?= json_encode($today, JSON_UNESCAPED_UNICODE) ?>;

    const i18n = {
        saving: <?= json_encode(LOC('moneta.groups.saving'), JSON_UNESCAPED_UNICODE) ?>,
        saved: <?= json_encode(LOC('moneta.groups.saved'), JSON_UNESCAPED_UNICODE) ?>,
        saveFailed: <?= json_encode(LOC('moneta.groups.save_failed'), JSON_UNESCAPED_UNICODE) ?>,
        groupDefault: <?= json_encode(LOC('moneta.groups.default_name'), JSON_UNESCAPED_UNICODE) ?>,
        removeGroup: <?= json_encode(LOC('moneta.groups.remove'), JSON_UNESCAPED_UNICODE) ?>,
        addAccount: <?= json_encode(LOC('moneta.groups.add_account'), JSON_UNESCAPED_UNICODE) ?>,
        removeAccount: <?= json_encode(LOC('moneta.groups.remove_account'), JSON_UNESCAPED_UNICODE) ?>,
        negate: <?= json_encode(LOC('moneta.groups.negate'), JSON_UNESCAPED_UNICODE) ?>,
        negateHint: <?= json_encode(LOC('moneta.groups.negate_hint'), JSON_UNESCAPED_UNICODE) ?>,
        noAccounts: <?= json_encode(LOC('moneta.picker.empty'), JSON_UNESCAPED_UNICODE) ?>,
        emptyGroups: <?= json_encode(LOC('moneta.empty.groups'), JSON_UNESCAPED_UNICODE) ?>,
        emptyGl: <?= json_encode(LOC('moneta.empty.gl'), JSON_UNESCAPED_UNICODE) ?>,
        emptyDerived: <?= json_encode(LOC('moneta.empty.derived'), JSON_UNESCAPED_UNICODE) ?>,
        emptyCharts: <?= json_encode(LOC('moneta.empty.charts'), JSON_UNESCAPED_UNICODE) ?>,
        editGroups: <?= json_encode(LOC('moneta.btn.edit_groups'), JSON_UNESCAPED_UNICODE) ?>,
        editDerived: <?= json_encode(LOC('moneta.btn.edit_derived'), JSON_UNESCAPED_UNICODE) ?>,
        deleteChart: <?= json_encode(LOC('moneta.btn.delete_chart'), JSON_UNESCAPED_UNICODE) ?>,
        balanceSubtitle: <?= json_encode(LOC('moneta.chart.balance_subtitle'), JSON_UNESCAPED_UNICODE) ?>,
        derivedSubtitle: <?= json_encode(LOC('moneta.chart.derived_subtitle'), JSON_UNESCAPED_UNICODE) ?>,
        todayLabel: <?= json_encode(LOC('moneta.chart.today'), JSON_UNESCAPED_UNICODE) ?>,
        refreshing: <?= json_encode(LOC('moneta.groups.refreshing'), JSON_UNESCAPED_UNICODE) ?>,
        seriesDefault: <?= json_encode(LOC('moneta.derived.default_name'), JSON_UNESCAPED_UNICODE) ?>,
        leftGroup: <?= json_encode(LOC('moneta.derived.left'), JSON_UNESCAPED_UNICODE) ?>,
        rightGroup: <?= json_encode(LOC('moneta.derived.right'), JSON_UNESCAPED_UNICODE) ?>,
        operator: <?= json_encode(LOC('moneta.derived.operator'), JSON_UNESCAPED_UNICODE) ?>,
        seriesName: <?= json_encode(LOC('moneta.derived.name'), JSON_UNESCAPED_UNICODE) ?>,
        removeSeries: <?= json_encode(LOC('moneta.derived.remove'), JSON_UNESCAPED_UNICODE) ?>,
        account: <?= json_encode(LOC('moneta.forecast.account'), JSON_UNESCAPED_UNICODE) ?>,
        amount: <?= json_encode(LOC('moneta.forecast.amount'), JSON_UNESCAPED_UNICODE) ?>,
        name: <?= json_encode(LOC('moneta.forecast.name'), JSON_UNESCAPED_UNICODE) ?>,
        description: <?= json_encode(LOC('moneta.forecast.description'), JSON_UNESCAPED_UNICODE) ?>,
        eventDate: <?= json_encode(LOC('moneta.forecast.event_date'), JSON_UNESCAPED_UNICODE) ?>,
        startDate: <?= json_encode(LOC('moneta.forecast.start_date'), JSON_UNESCAPED_UNICODE) ?>,
        endDate: <?= json_encode(LOC('moneta.forecast.end_date'), JSON_UNESCAPED_UNICODE) ?>,
        repeatN: <?= json_encode(LOC('moneta.forecast.repeat_n'), JSON_UNESCAPED_UNICODE) ?>,
        repeatUnit: <?= json_encode(LOC('moneta.forecast.repeat_unit'), JSON_UNESCAPED_UNICODE) ?>,
        removeItem: <?= json_encode(LOC('moneta.forecast.remove'), JSON_UNESCAPED_UNICODE) ?>,
        chooseAccount: <?= json_encode(LOC('moneta.forecast.choose_account'), JSON_UNESCAPED_UNICODE) ?>,
        unitDay: <?= json_encode(LOC('moneta.forecast.unit.day'), JSON_UNESCAPED_UNICODE) ?>,
        unitWeek: <?= json_encode(LOC('moneta.forecast.unit.week'), JSON_UNESCAPED_UNICODE) ?>,
        unitMonth: <?= json_encode(LOC('moneta.forecast.unit.month'), JSON_UNESCAPED_UNICODE) ?>,
        unitYear: <?= json_encode(LOC('moneta.forecast.unit.year'), JSON_UNESCAPED_UNICODE) ?>,
        required: <?= json_encode(LOC('moneta.forecast.validation.required'), JSON_UNESCAPED_UNICODE) ?>,
        endBeforeStart: <?= json_encode(LOC('moneta.forecast.validation.end_before_start'), JSON_UNESCAPED_UNICODE) ?>,
        edit: <?= json_encode(LOC('moneta.forecast.edit'), JSON_UNESCAPED_UNICODE) ?>,
        untitled: <?= json_encode(LOC('moneta.forecast.untitled'), JSON_UNESCAPED_UNICODE) ?>,
        emptyFiltered: <?= json_encode(LOC('moneta.forecast.empty_filtered'), JSON_UNESCAPED_UNICODE) ?>,
        emptyList: <?= json_encode(LOC('moneta.forecast.empty_list'), JSON_UNESCAPED_UNICODE) ?>,
        allAccounts: <?= json_encode(LOC('moneta.forecast.all_accounts'), JSON_UNESCAPED_UNICODE) ?>,
        countLabel: <?= json_encode(LOC('moneta.forecast.count'), JSON_UNESCAPED_UNICODE) ?>,
        confirmDeleteChart: <?= json_encode(LOC('moneta.confirm.delete_chart'), JSON_UNESCAPED_UNICODE) ?>
    };

    let charts = <?= $chartsJson ?>;
    let chartPayloads = <?= $payloadsJson ?>;
    let groupOptions = <?= $groupOptionsJson ?>;
    let chartInstances = {};
    let glAccounts = null;
    let tempId = -1;

    let activeChartId = null;
    let groups = [];
    let groupsDirty = false;
    let groupsSaveTimer = null;
    let groupsSavePromise = null;
    let groupsSaveGen = 0;

    let derivedSeries = [];
    let derivedDirty = false;
    let derivedSaveTimer = null;
    let derivedSavePromise = null;
    let derivedSaveGen = 0;
    let derivedChartId = null;

    let oneTimeItems = [];
    let ruleItems = [];
    let forecastDirty = false;
    let forecastFilterAccount = '';
    let forecastSearch = '';
    let forecastEdit = null; // { kind: 'one_time'|'rule', item: object }
    let pickerMode = null; // { type: 'group'|'edit_account', index? }

    const chartsRoot = document.getElementById('moneta-charts-root');
    const groupsModal = document.getElementById('moneta-groups-modal');
    const derivedModal = document.getElementById('moneta-derived-modal');
    const forecastModal = document.getElementById('moneta-forecast-modal');
    const pickerModal = document.getElementById('moneta-picker-modal');
    const groupsList = document.getElementById('moneta-groups-list');
    const groupsSaveState = document.getElementById('moneta-groups-save-state');
    const derivedList = document.getElementById('moneta-derived-list');
    const derivedSaveState = document.getElementById('moneta-derived-save-state');
    const forecastSaveState = document.getElementById('moneta-forecast-save-state');
    const oneTimeList = document.getElementById('moneta-forecast-one-time-list');
    const rulesList = document.getElementById('moneta-forecast-rules-list');
    const forecastBrowse = document.getElementById('moneta-forecast-browse');
    const forecastEditPanel = document.getElementById('moneta-forecast-edit');
    const forecastEditForm = document.getElementById('moneta-forecast-edit-form');
    const forecastSearchInput = document.getElementById('moneta-forecast-search');
    const forecastAccountFilter = document.getElementById('moneta-forecast-account-filter');
    const oneTimeCountEl = document.getElementById('moneta-one-time-count');
    const rulesCountEl = document.getElementById('moneta-rules-count');
    const pickerList = document.getElementById('moneta-picker-list');
    const pickerSearch = document.getElementById('moneta-picker-search');

    function openModal(el) {
        el.classList.add('is-open');
        el.setAttribute('aria-hidden', 'false');
    }
    function closeModal(el) {
        el.classList.remove('is-open');
        el.setAttribute('aria-hidden', 'true');
    }

    async function apiGet(action, params) {
        const qs = new URLSearchParams(Object.assign({ company: company, action: action }, params || {}));
        const response = await fetch('moneta_api.php?' + qs.toString(), {
            headers: { 'Accept': 'application/json' },
            credentials: 'same-origin'
        });
        const data = await response.json();
        if (!response.ok || !data.ok) throw new Error((data && data.error) || ('HTTP ' + response.status));
        return data;
    }

    async function apiPost(action, body) {
        const response = await fetch('moneta_api.php?action=' + encodeURIComponent(action) + '&company=' + encodeURIComponent(company), {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
            credentials: 'same-origin',
            body: JSON.stringify(body || {})
        });
        const data = await response.json();
        if (!response.ok || !data.ok) throw new Error((data && data.error) || ('HTTP ' + response.status));
        return data;
    }

    function formatDateLabel(value) {
        const match = String(value || '').match(/^(\d{4})-(\d{2})-(\d{2})$/);
        if (!match) return value;
        return match[3] + '-' + match[2] + '-' + match[1];
    }

    function formatEuro(value) {
        const number = Number(value);
        if (!Number.isFinite(number)) return '';
        return new Intl.NumberFormat('nl-NL', {
            style: 'currency', currency: 'EUR', maximumFractionDigits: 0
        }).format(number);
    }

    const todayLinePlugin = {
        id: 'monetaTodayLine',
        afterDraw: function (chart) {
            const idx = chart.$todayIndex;
            if (idx == null || idx < 0) return;
            const meta = chart.getDatasetMeta(0);
            const xScale = chart.scales.x;
            if (!xScale) return;
            const x = xScale.getPixelForValue(idx);
            const { top, bottom } = chart.chartArea;
            const ctx = chart.ctx;
            ctx.save();
            ctx.beginPath();
            ctx.strokeStyle = '#dc2626';
            ctx.lineWidth = 2;
            ctx.moveTo(x, top);
            ctx.lineTo(x, bottom);
            ctx.stroke();
            ctx.fillStyle = '#dc2626';
            ctx.font = '12px sans-serif';
            ctx.fillText(i18n.todayLabel, x + 4, top + 12);
            ctx.restore();
        }
    };

    function todayIndex(labels) {
        if (!Array.isArray(labels)) return -1;
        return labels.indexOf(today);
    }

    function chartDatasetOptions(serie, index, todayIdx) {
        const color = palette[index % palette.length];
        return {
            label: serie.name || serie.account_no || ('Serie ' + (index + 1)),
            data: serie.data || [],
            borderColor: color,
            backgroundColor: color,
            tension: 0.25,
            pointRadius: 0,
            pointHoverRadius: 4,
            borderWidth: 2,
            spanGaps: true,
            segment: {
                borderDash: function (ctx) {
                    if (todayIdx < 0) return undefined;
                    return ctx.p0DataIndex >= todayIdx ? [6, 4] : undefined;
                }
            }
        };
    }

    function lineChartOptions() {
        return {
            responsive: true,
            maintainAspectRatio: false,
            interaction: { mode: 'nearest', intersect: false },
            plugins: {
                legend: { position: 'bottom', labels: { boxWidth: 12, usePointStyle: true } },
                tooltip: {
                    callbacks: {
                        label: function (context) {
                            return (context.dataset.label || '') + ': ' + formatEuro(context.parsed.y);
                        }
                    }
                }
            },
            scales: {
                x: { ticks: { maxRotation: 0, autoSkip: true, maxTicksLimit: 8 }, grid: { display: false } },
                y: { ticks: { callback: function (value) { return formatEuro(value); } } }
            }
        };
    }

    function createLineChart(canvas, chartPayload) {
        if (!canvas || typeof Chart === 'undefined' || !chartPayload || !Array.isArray(chartPayload.labels) || chartPayload.labels.length === 0) {
            return null;
        }
        const tIdx = todayIndex(chartPayload.labels);
        const chart = new Chart(canvas, {
            type: 'line',
            data: {
                labels: chartPayload.labels.map(formatDateLabel),
                datasets: (chartPayload.series || []).map(function (s, i) { return chartDatasetOptions(s, i, tIdx); })
            },
            options: lineChartOptions(),
            plugins: [todayLinePlugin]
        });
        chart.$todayIndex = tIdx;
        return chart;
    }

    function destroyChartInstance(chartId) {
        if (chartInstances[chartId]) {
            chartInstances[chartId].destroy();
            delete chartInstances[chartId];
        }
    }

    function updateChartInPlace(chartId, chartPayload) {
        const chart = chartInstances[chartId];
        if (!chart || !chartPayload || !Array.isArray(chartPayload.labels) || chartPayload.labels.length === 0) {
            return false;
        }
        const series = chartPayload.series || [];
        if (!series.length) {
            return false;
        }
        const tIdx = todayIndex(chartPayload.labels);
        chart.data.labels = chartPayload.labels.map(formatDateLabel);
        chart.data.datasets = series.map(function (s, i) { return chartDatasetOptions(s, i, tIdx); });
        chart.$todayIndex = tIdx;
        chart.update('none');
        return true;
    }

    function applyChartPayload(chartId, chartPayload) {
        chartPayloads[chartId] = chartPayload || { labels: [], series: [] };
        if (updateChartInPlace(chartId, chartPayloads[chartId])) {
            return;
        }
        renderCharts();
    }

    function renderCharts() {
        Object.keys(chartInstances).forEach(function (id) { destroyChartInstance(id); });
        chartsRoot.innerHTML = '';

        if (!charts.length) {
            const empty = document.createElement('div');
            empty.className = 'moneta-card moneta-empty';
            empty.textContent = i18n.emptyCharts;
            chartsRoot.appendChild(empty);
            return;
        }

        charts.forEach(function (chart) {
            const section = document.createElement('section');
            section.className = 'moneta-card';
            section.dataset.chartId = String(chart.id);

            const head = document.createElement('div');
            head.className = 'moneta-chart-head';

            const titleWrap = document.createElement('div');
            const titleInput = document.createElement('input');
            titleInput.type = 'text';
            titleInput.className = 'moneta-title-input';
            titleInput.value = chart.name || '';
            titleInput.addEventListener('change', function () {
                saveChartTitle(chart.id, titleInput.value);
            });
            titleInput.addEventListener('keydown', function (e) {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    titleInput.blur();
                }
            });
            const subtitle = document.createElement('p');
            subtitle.className = 'moneta-subtitle';
            subtitle.textContent = chart.chart_type === 'derived' ? i18n.derivedSubtitle : i18n.balanceSubtitle;
            const saveHint = document.createElement('p');
            saveHint.className = 'moneta-save-state';
            saveHint.dataset.role = 'title-save';
            titleWrap.appendChild(titleInput);
            titleWrap.appendChild(subtitle);
            titleWrap.appendChild(saveHint);

            const actions = document.createElement('div');
            actions.className = 'moneta-toolbar';
            if (chart.chart_type === 'derived') {
                const editBtn = document.createElement('button');
                editBtn.type = 'button';
                editBtn.className = 'moneta-btn-secondary';
                editBtn.textContent = i18n.editDerived;
                editBtn.addEventListener('click', function () { openDerivedModal(chart.id); });
                actions.appendChild(editBtn);
            } else {
                const editBtn = document.createElement('button');
                editBtn.type = 'button';
                editBtn.className = 'moneta-btn-secondary';
                editBtn.textContent = i18n.editGroups;
                editBtn.addEventListener('click', function () { openGroupsModal(chart.id); });
                actions.appendChild(editBtn);
            }
            const delBtn = document.createElement('button');
            delBtn.type = 'button';
            delBtn.className = 'moneta-btn-icon moneta-btn-danger';
            delBtn.title = i18n.deleteChart;
            delBtn.textContent = '×';
            delBtn.addEventListener('click', function () { deleteChart(chart.id); });
            actions.appendChild(delBtn);

            head.appendChild(titleWrap);
            head.appendChild(actions);
            section.appendChild(head);

            const host = document.createElement('div');
            host.dataset.role = 'chart-host';
            const payload = chartPayloads[chart.id] || { labels: [], series: [] };
            const hasSeries = Array.isArray(payload.labels) && payload.labels.length > 0
                && Array.isArray(payload.series) && payload.series.length > 0;
            if (hasSeries) {
                const wrap = document.createElement('div');
                wrap.className = 'moneta-chart-wrap';
                const canvas = document.createElement('canvas');
                wrap.appendChild(canvas);
                host.appendChild(wrap);
                section.appendChild(host);
                chartsRoot.appendChild(section);
                chartInstances[chart.id] = createLineChart(canvas, payload);
            } else {
                const empty = document.createElement('div');
                empty.className = 'moneta-empty';
                if (chart.chart_type === 'derived') {
                    empty.textContent = i18n.emptyDerived;
                    const cta = document.createElement('button');
                    cta.type = 'button';
                    cta.className = 'moneta-btn';
                    cta.textContent = i18n.editDerived;
                    cta.addEventListener('click', function () { openDerivedModal(chart.id); });
                    empty.appendChild(document.createElement('br'));
                    empty.appendChild(cta);
                } else {
                    const groupsCount = (chart.groups || []).length;
                    empty.textContent = groupsCount === 0 ? i18n.emptyGroups : i18n.emptyGl;
                    const cta = document.createElement('button');
                    cta.type = 'button';
                    cta.className = 'moneta-btn';
                    cta.textContent = i18n.editGroups;
                    cta.addEventListener('click', function () { openGroupsModal(chart.id); });
                    empty.appendChild(document.createElement('br'));
                    empty.appendChild(cta);
                }
                host.appendChild(empty);
                section.appendChild(host);
                chartsRoot.appendChild(section);
            }
        });
    }

    async function saveChartTitle(chartId, name) {
        const section = chartsRoot.querySelector('[data-chart-id="' + chartId + '"]');
        const hint = section ? section.querySelector('[data-role="title-save"]') : null;
        if (hint) hint.textContent = i18n.saving;
        try {
            const data = await apiPost('save_chart', { id: chartId, name: name });
            if (Array.isArray(data.charts)) charts = data.charts;
            const chart = charts.find(function (c) { return c.id === chartId; });
            if (chart) chart.name = name;
            if (hint) hint.textContent = i18n.saved;
        } catch (error) {
            if (hint) hint.textContent = i18n.saveFailed + ': ' + (error.message || error);
        }
    }

    async function addChart(chartType) {
        try {
            showLoader();
            const data = await apiPost('save_chart', {
                chart_type: chartType,
                name: chartType === 'derived' ? 'Combinatiegrafiek' : 'Saldi'
            });
            charts = data.charts || charts;
            if (data.chart && data.chart.id) {
                chartPayloads[data.chart.id] = { labels: [], series: [], today: today, chart: data.chart };
            }
            renderCharts();
            if (data.chart && data.chart.chart_type === 'derived') {
                openDerivedModal(data.chart.id);
            } else if (data.chart) {
                openGroupsModal(data.chart.id);
            }
        } catch (error) {
            alert(i18n.saveFailed + ': ' + (error.message || error));
        } finally {
            clearTimeout(loaderTimer);
            if (loader) {
                loader.classList.remove('is-visible');
                loader.setAttribute('aria-hidden', 'true');
            }
        }
    }

    async function deleteChart(chartId) {
        if (!window.confirm(i18n.confirmDeleteChart)) return;
        try {
            const data = await apiPost('delete_chart', { id: chartId });
            charts = data.charts || [];
            delete chartPayloads[chartId];
            destroyChartInstance(chartId);
            renderCharts();
        } catch (error) {
            alert(i18n.saveFailed + ': ' + (error.message || error));
        }
    }

    async function refreshChart(chartId) {
        const data = await apiGet('chart_data', {
            chart_id: chartId,
            date_from: dateFrom,
            date_to: dateTo
        });
        const chartMeta = charts.find(function (c) { return c.id === chartId; });
        if (chartMeta && Array.isArray(data.groups)) {
            chartMeta.groups = data.groups;
        }
        applyChartPayload(chartId, data.chart || { labels: [], series: [] });
    }

    async function refreshAllCharts() {
        const results = await Promise.all(charts.map(function (chart) {
            return apiGet('chart_data', {
                chart_id: chart.id,
                date_from: dateFrom,
                date_to: dateTo
            }).then(function (data) {
                return { id: chart.id, data: data };
            });
        }));
        let needsFullRender = false;
        results.forEach(function (result) {
            const payload = (result.data && result.data.chart) || { labels: [], series: [] };
            chartPayloads[result.id] = payload;
            const chartMeta = charts.find(function (c) { return c.id === result.id; });
            if (chartMeta && result.data && Array.isArray(result.data.groups)) {
                chartMeta.groups = result.data.groups;
            }
            if (!updateChartInPlace(result.id, payload)) {
                needsFullRender = true;
            }
        });
        if (needsFullRender) {
            renderCharts();
        }
    }

    // —— Groups modal ——
    function normalizeGroupsForSave() {
        return groups.map(function (group) {
            return {
                id: group.id > 0 ? group.id : null,
                name: group.name || i18n.groupDefault,
                accounts: (group.accounts || []).map(function (account) {
                    return {
                        account_no: account.account_no,
                        negate: !!account.negate
                    };
                })
            };
        });
    }

    function scheduleGroupsSave() {
        groupsDirty = true;
        clearTimeout(groupsSaveTimer);
        groupsSaveState.textContent = i18n.saving;
        groupsSaveTimer = setTimeout(function () {
            groupsSavePromise = persistGroups();
        }, 450);
    }

    async function flushGroupsSave() {
        clearTimeout(groupsSaveTimer);
        if (groupsSavePromise) await groupsSavePromise;
        else if (groupsDirty) {
            groupsSavePromise = persistGroups();
            await groupsSavePromise;
        }
    }

    /** Server-ids terugzetten zonder de modal te hertekenen (voorkomt focus/tekst-reset). */
    function mergeSavedIds(localItems, savedItems) {
        if (!Array.isArray(localItems) || !Array.isArray(savedItems)) {
            return;
        }
        const n = Math.min(localItems.length, savedItems.length);
        for (let i = 0; i < n; i++) {
            if (savedItems[i] && savedItems[i].id != null) {
                localItems[i].id = savedItems[i].id;
            }
        }
    }

    async function persistGroups() {
        const gen = ++groupsSaveGen;
        const payload = {
            chart_id: activeChartId,
            groups: normalizeGroupsForSave()
        };
        try {
            const data = await apiPost('save_groups', payload);
            if (gen !== groupsSaveGen) {
                return true;
            }
            mergeSavedIds(groups, data.groups || []);
            if (Array.isArray(data.group_options)) {
                groupOptions = data.group_options;
            }
            groupsSaveState.textContent = i18n.saved;
            groupsDirty = true;
            return true;
        } catch (error) {
            if (gen === groupsSaveGen) {
                groupsSaveState.textContent = i18n.saveFailed + ': ' + (error.message || error);
            }
            return false;
        } finally {
            if (groupsSavePromise) {
                groupsSavePromise = null;
            }
        }
    }

    function renderGroups() {
        groupsList.innerHTML = '';
        if (!groups.length) {
            const empty = document.createElement('div');
            empty.className = 'moneta-empty';
            empty.textContent = i18n.emptyGroups;
            groupsList.appendChild(empty);
            return;
        }
        groups.forEach(function (group, groupIndex) {
            const box = document.createElement('div');
            box.className = 'moneta-group';
            const head = document.createElement('div');
            head.className = 'moneta-group-head';
            const nameInput = document.createElement('input');
            nameInput.type = 'text';
            nameInput.value = group.name || '';
            nameInput.addEventListener('input', function () {
                groups[groupIndex].name = nameInput.value;
            });
            nameInput.addEventListener('change', scheduleGroupsSave);
            const addBtn = document.createElement('button');
            addBtn.type = 'button';
            addBtn.className = 'moneta-btn-icon';
            addBtn.title = i18n.addAccount;
            addBtn.textContent = '+';
            addBtn.addEventListener('click', function () {
                pickerMode = { type: 'group', index: groupIndex };
                openPicker();
            });
            const removeBtn = document.createElement('button');
            removeBtn.type = 'button';
            removeBtn.className = 'moneta-btn-icon moneta-btn-danger';
            removeBtn.title = i18n.removeGroup;
            removeBtn.textContent = '×';
            removeBtn.addEventListener('click', function () {
                groups.splice(groupIndex, 1);
                renderGroups();
                scheduleGroupsSave();
            });
            head.appendChild(nameInput);
            head.appendChild(addBtn);
            head.appendChild(removeBtn);
            box.appendChild(head);

            const list = document.createElement('ul');
            list.className = 'moneta-account-list';
            (group.accounts || []).forEach(function (account, accountIndex) {
                const row = document.createElement('li');
                row.className = 'moneta-account-row';
                const label = document.createElement('span');
                label.textContent = (account.account_no || '') + ' — ' + (account.account_name || '');
                const negateLabel = document.createElement('label');
                negateLabel.className = 'moneta-negate';
                negateLabel.title = i18n.negateHint;
                const negate = document.createElement('input');
                negate.type = 'checkbox';
                negate.checked = !!account.negate;
                negate.addEventListener('change', function () {
                    groups[groupIndex].accounts[accountIndex].negate = negate.checked;
                    scheduleGroupsSave();
                });
                negateLabel.appendChild(negate);
                negateLabel.appendChild(document.createTextNode(i18n.negate));
                const del = document.createElement('button');
                del.type = 'button';
                del.className = 'moneta-btn-icon moneta-btn-danger';
                del.title = i18n.removeAccount;
                del.textContent = '×';
                del.addEventListener('click', function () {
                    groups[groupIndex].accounts.splice(accountIndex, 1);
                    renderGroups();
                    scheduleGroupsSave();
                });
                row.appendChild(label);
                row.appendChild(negateLabel);
                row.appendChild(del);
                list.appendChild(row);
            });
            box.appendChild(list);
            groupsList.appendChild(box);
        });
    }

    async function openGroupsModal(chartId) {
        activeChartId = chartId;
        groupsDirty = false;
        groupsSaveState.textContent = '';
        try {
            const data = await apiGet('groups', { chart_id: chartId });
            groups = data.groups || [];
            renderGroups();
            openModal(groupsModal);
        } catch (error) {
            alert(error.message || String(error));
        }
    }

    async function closeGroupsModalAndRefresh() {
        closeModal(groupsModal);
        try {
            await flushGroupsSave();
            if (groupsDirty && activeChartId) {
                groupsSaveState.textContent = i18n.refreshing;
                await refreshChart(activeChartId);
                groupsDirty = false;
            }
        } catch (error) {
            groupsSaveState.textContent = i18n.saveFailed + ': ' + (error.message || error);
        }
    }

    // —— Derived modal ——
    function scheduleDerivedSave() {
        derivedDirty = true;
        clearTimeout(derivedSaveTimer);
        derivedSaveState.textContent = i18n.saving;
        derivedSaveTimer = setTimeout(function () {
            derivedSavePromise = persistDerived();
        }, 450);
    }

    async function flushDerivedSave() {
        clearTimeout(derivedSaveTimer);
        if (derivedSavePromise) await derivedSavePromise;
        else if (derivedDirty) {
            derivedSavePromise = persistDerived();
            await derivedSavePromise;
        }
    }

    async function persistDerived() {
        const gen = ++derivedSaveGen;
        const payload = derivedSeries.map(function (row) {
            return {
                id: row.id > 0 ? row.id : null,
                name: row.name || i18n.seriesDefault,
                left_group_id: Number(row.left_group_id) || 0,
                operator: row.operator || '+',
                right_group_id: Number(row.right_group_id) || 0
            };
        });
        try {
            const data = await apiPost('save_derived', { chart_id: derivedChartId, series: payload });
            if (gen !== derivedSaveGen) {
                return true;
            }
            mergeSavedIds(derivedSeries, data.derived_series || []);
            derivedSaveState.textContent = i18n.saved;
            derivedDirty = true;
            return true;
        } catch (error) {
            if (gen === derivedSaveGen) {
                derivedSaveState.textContent = i18n.saveFailed + ': ' + (error.message || error);
            }
            return false;
        } finally {
            derivedSavePromise = null;
        }
    }

    function renderDerived() {
        derivedList.innerHTML = '';
        if (!groupOptions.length) {
            const empty = document.createElement('div');
            empty.className = 'moneta-empty';
            empty.textContent = i18n.emptyGroups;
            derivedList.appendChild(empty);
            return;
        }
        if (!derivedSeries.length) {
            const empty = document.createElement('div');
            empty.className = 'moneta-empty';
            empty.textContent = i18n.emptyDerived;
            derivedList.appendChild(empty);
            return;
        }
        derivedSeries.forEach(function (row, index) {
            const box = document.createElement('div');
            box.className = 'moneta-row';
            const grid = document.createElement('div');
            grid.className = 'moneta-row-grid moneta-row-grid-3';

            function field(labelText, el) {
                const lab = document.createElement('label');
                lab.textContent = labelText;
                lab.appendChild(el);
                return lab;
            }

            const nameInput = document.createElement('input');
            nameInput.type = 'text';
            nameInput.value = row.name || '';
            nameInput.addEventListener('input', function () {
                derivedSeries[index].name = nameInput.value;
            });
            nameInput.addEventListener('change', scheduleDerivedSave);

            const leftSelect = document.createElement('select');
            groupOptions.forEach(function (opt) {
                const o = document.createElement('option');
                o.value = String(opt.id);
                o.textContent = opt.label;
                if (Number(opt.id) === Number(row.left_group_id)) o.selected = true;
                leftSelect.appendChild(o);
            });
            leftSelect.addEventListener('change', function () {
                derivedSeries[index].left_group_id = Number(leftSelect.value);
                scheduleDerivedSave();
            });

            const rightSelect = document.createElement('select');
            groupOptions.forEach(function (opt) {
                const o = document.createElement('option');
                o.value = String(opt.id);
                o.textContent = opt.label;
                if (Number(opt.id) === Number(row.right_group_id)) o.selected = true;
                rightSelect.appendChild(o);
            });
            rightSelect.addEventListener('change', function () {
                derivedSeries[index].right_group_id = Number(rightSelect.value);
                scheduleDerivedSave();
            });

            grid.appendChild(field(i18n.seriesName, nameInput));
            grid.appendChild(field(i18n.leftGroup, leftSelect));
            grid.appendChild(field(i18n.rightGroup, rightSelect));
            box.appendChild(grid);

            const opWrap = document.createElement('div');
            const opLabel = document.createElement('div');
            opLabel.className = 'moneta-section-title';
            opLabel.textContent = i18n.operator;
            const ops = document.createElement('div');
            ops.className = 'moneta-ops';
            ['+', '-', '×', '÷'].forEach(function (sym) {
                const map = { '+': '+', '-': '-', '×': '*', '÷': '/' };
                const btn = document.createElement('button');
                btn.type = 'button';
                btn.textContent = sym;
                if ((row.operator || '+') === map[sym]) btn.classList.add('is-active');
                btn.addEventListener('click', function () {
                    derivedSeries[index].operator = map[sym];
                    ops.querySelectorAll('button').forEach(function (b) {
                        b.classList.toggle('is-active', b === btn);
                    });
                    scheduleDerivedSave();
                });
                ops.appendChild(btn);
            });
            opWrap.appendChild(opLabel);
            opWrap.appendChild(ops);
            box.appendChild(opWrap);

            const removeBtn = document.createElement('button');
            removeBtn.type = 'button';
            removeBtn.className = 'moneta-btn-icon moneta-btn-danger';
            removeBtn.title = i18n.removeSeries;
            removeBtn.textContent = '×';
            removeBtn.addEventListener('click', function () {
                derivedSeries.splice(index, 1);
                renderDerived();
                scheduleDerivedSave();
            });
            box.appendChild(removeBtn);
            derivedList.appendChild(box);
        });
    }

    async function openDerivedModal(chartId) {
        derivedChartId = chartId;
        derivedDirty = false;
        derivedSaveState.textContent = '';
        try {
            const data = await apiGet('charts');
            charts = data.charts || charts;
            groupOptions = data.group_options || groupOptions;
            const chart = charts.find(function (c) { return c.id === chartId; });
            derivedSeries = (chart && chart.derived_series) ? chart.derived_series.slice() : [];
            renderDerived();
            openModal(derivedModal);
        } catch (error) {
            alert(error.message || String(error));
        }
    }

    async function closeDerivedModalAndRefresh() {
        closeModal(derivedModal);
        try {
            await flushDerivedSave();
            if (derivedDirty && derivedChartId) {
                await refreshChart(derivedChartId);
                derivedDirty = false;
            }
        } catch (error) {
            derivedSaveState.textContent = i18n.saveFailed + ': ' + (error.message || error);
        }
    }

    // —— Forecast modal ——
    function forecastMatches(item) {
        if (forecastFilterAccount && item.account_no !== forecastFilterAccount) {
            return false;
        }
        const needle = String(forecastSearch || '').trim().toLowerCase();
        if (!needle) {
            return true;
        }
        const hay = ((item.name || '') + ' ' + (item.description || '')).toLowerCase();
        return hay.indexOf(needle) !== -1;
    }

    function forecastDisplayName(item) {
        return (item.name && String(item.name).trim()) ? item.name : i18n.untitled;
    }

    function forecastAccountLabel(item) {
        if (!item.account_no) {
            return i18n.chooseAccount;
        }
        return (item.account_no || '') + ' — ' + (item.account_name || item.account_no);
    }

    function formatCount(n) {
        return String(i18n.countLabel || '%d').replace('%d', String(n));
    }

    function rebuildForecastAccountFilter() {
        const selected = forecastAccountFilter.value;
        const map = {};
        oneTimeItems.concat(ruleItems).forEach(function (item) {
            if (!item.account_no) return;
            map[item.account_no] = item.account_name || item.account_no;
        });
        const accounts = Object.keys(map).sort(function (a, b) {
            return a.localeCompare(b, undefined, { numeric: true });
        });
        forecastAccountFilter.innerHTML = '';
        const all = document.createElement('option');
        all.value = '';
        all.textContent = i18n.allAccounts;
        forecastAccountFilter.appendChild(all);
        accounts.forEach(function (no) {
            const o = document.createElement('option');
            o.value = no;
            o.textContent = no + ' — ' + map[no];
            forecastAccountFilter.appendChild(o);
        });
        forecastAccountFilter.value = selected && map[selected] ? selected : '';
        forecastFilterAccount = forecastAccountFilter.value;
    }

    function appendForecastRow(listEl, item, kind) {
        const row = document.createElement('div');
        row.className = 'moneta-forecast-row';
        const main = document.createElement('div');
        main.className = 'moneta-forecast-row-main';
        const title = document.createElement('div');
        title.className = 'moneta-forecast-row-title';
        title.textContent = forecastDisplayName(item);
        const meta = document.createElement('div');
        meta.className = 'moneta-forecast-row-meta';
        if (kind === 'one_time') {
            meta.textContent = forecastAccountLabel(item) + ' · ' + formatEuro(item.amount) + ' · ' + formatDateLabel(item.event_date || '');
        } else {
            const unitMap = { day: i18n.unitDay, week: i18n.unitWeek, month: i18n.unitMonth, year: i18n.unitYear };
            const unit = unitMap[item.repeat_unit] || item.repeat_unit;
            meta.textContent = forecastAccountLabel(item) + ' · ' + formatEuro(item.amount)
                + ' · ' + formatDateLabel(item.start_date || '') + ' / ' + (item.repeat_n || 1) + ' ' + unit
                + (item.end_date ? (' → ' + formatDateLabel(item.end_date)) : '');
        }
        main.appendChild(title);
        main.appendChild(meta);
        const actions = document.createElement('div');
        actions.className = 'moneta-forecast-row-actions';
        const editBtn = document.createElement('button');
        editBtn.type = 'button';
        editBtn.className = 'moneta-btn-icon';
        editBtn.title = i18n.edit;
        editBtn.textContent = '✎';
        editBtn.addEventListener('click', function () {
            openForecastEdit(kind, item);
        });
        actions.appendChild(editBtn);
        row.appendChild(main);
        row.appendChild(actions);
        listEl.appendChild(row);
    }

    function renderForecastLists() {
        const ones = oneTimeItems.filter(forecastMatches);
        const rules = ruleItems.filter(forecastMatches);
        oneTimeCountEl.textContent = '(' + formatCount(ones.length) + ')';
        rulesCountEl.textContent = '(' + formatCount(rules.length) + ')';

        oneTimeList.innerHTML = '';
        if (!oneTimeItems.length) {
            const empty = document.createElement('div');
            empty.className = 'moneta-empty';
            empty.style.border = '0';
            empty.textContent = i18n.emptyList;
            oneTimeList.appendChild(empty);
        } else if (!ones.length) {
            const empty = document.createElement('div');
            empty.className = 'moneta-empty';
            empty.style.border = '0';
            empty.textContent = i18n.emptyFiltered;
            oneTimeList.appendChild(empty);
        } else {
            ones.forEach(function (item) { appendForecastRow(oneTimeList, item, 'one_time'); });
        }

        rulesList.innerHTML = '';
        if (!ruleItems.length) {
            const empty = document.createElement('div');
            empty.className = 'moneta-empty';
            empty.style.border = '0';
            empty.textContent = i18n.emptyList;
            rulesList.appendChild(empty);
        } else if (!rules.length) {
            const empty = document.createElement('div');
            empty.className = 'moneta-empty';
            empty.style.border = '0';
            empty.textContent = i18n.emptyFiltered;
            rulesList.appendChild(empty);
        } else {
            rules.forEach(function (item) { appendForecastRow(rulesList, item, 'rule'); });
        }
    }

    function showForecastBrowse() {
        forecastEdit = null;
        forecastBrowse.classList.remove('is-hidden');
        forecastEditPanel.classList.remove('is-open');
        renderForecastLists();
    }

    function openForecastEdit(kind, item) {
        forecastEdit = {
            kind: kind,
            item: Object.assign({}, item)
        };
        forecastBrowse.classList.add('is-hidden');
        forecastEditPanel.classList.add('is-open');
        renderForecastEditForm();
    }

    function accountButton(item, onPick) {
        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'moneta-btn-secondary';
        btn.style.width = '100%';
        btn.textContent = forecastAccountLabel(item);
        btn.addEventListener('click', onPick);
        return btn;
    }

    function renderForecastEditForm() {
        forecastEditForm.innerHTML = '';
        if (!forecastEdit) return;
        const item = forecastEdit.item;
        const kind = forecastEdit.kind;
        const grid = document.createElement('div');
        grid.className = 'moneta-row-grid ' + (kind === 'rule' ? 'moneta-row-grid-4' : 'moneta-row-grid-3');

        function addField(label, el) {
            const lab = document.createElement('label');
            lab.appendChild(document.createTextNode(label));
            lab.appendChild(el);
            grid.appendChild(lab);
        }

        addField(i18n.account, accountButton(item, function () {
            pickerMode = { type: 'edit_account' };
            openPicker();
        }));

        const nameInput = document.createElement('input');
        nameInput.type = 'text';
        nameInput.value = item.name || '';
        nameInput.addEventListener('input', function () { item.name = nameInput.value; });
        addField(i18n.name, nameInput);

        const descInput = document.createElement('input');
        descInput.type = 'text';
        descInput.value = item.description || '';
        descInput.addEventListener('input', function () { item.description = descInput.value; });
        addField(i18n.description, descInput);

        const amountInput = document.createElement('input');
        amountInput.type = 'number';
        amountInput.step = '0.01';
        amountInput.value = item.amount != null ? item.amount : 0;
        amountInput.addEventListener('change', function () { item.amount = amountInput.value; });
        addField(i18n.amount, amountInput);

        if (kind === 'one_time') {
            const dateInput = document.createElement('input');
            dateInput.type = 'date';
            dateInput.value = item.event_date || '';
            dateInput.addEventListener('change', function () { item.event_date = dateInput.value; });
            addField(i18n.eventDate, dateInput);
        } else {
            const startInput = document.createElement('input');
            startInput.type = 'date';
            startInput.value = item.start_date || '';
            startInput.addEventListener('change', function () { item.start_date = startInput.value; });
            addField(i18n.startDate, startInput);
            const nInput = document.createElement('input');
            nInput.type = 'number';
            nInput.min = '1';
            nInput.value = item.repeat_n || 1;
            nInput.addEventListener('change', function () {
                item.repeat_n = Math.max(1, Number(nInput.value) || 1);
            });
            addField(i18n.repeatN, nInput);
            const unitSelect = document.createElement('select');
            [
                ['day', i18n.unitDay],
                ['week', i18n.unitWeek],
                ['month', i18n.unitMonth],
                ['year', i18n.unitYear]
            ].forEach(function (pair) {
                const o = document.createElement('option');
                o.value = pair[0];
                o.textContent = pair[1];
                if ((item.repeat_unit || 'month') === pair[0]) o.selected = true;
                unitSelect.appendChild(o);
            });
            unitSelect.addEventListener('change', function () { item.repeat_unit = unitSelect.value; });
            addField(i18n.repeatUnit, unitSelect);
            const endInput = document.createElement('input');
            endInput.type = 'date';
            endInput.value = item.end_date || '';
            endInput.addEventListener('change', function () { item.end_date = endInput.value || null; });
            addField(i18n.endDate, endInput);
        }

        forecastEditForm.appendChild(grid);
    }

    function validateEditItem() {
        if (!forecastEdit) return i18n.required;
        const item = forecastEdit.item;
        if (!item.account_no) return i18n.required;
        if (forecastEdit.kind === 'one_time') {
            if (!item.event_date) return i18n.required;
        } else {
            if (!item.start_date || !(Number(item.repeat_n) >= 1)) return i18n.required;
            if (item.end_date && item.end_date < item.start_date) return i18n.endBeforeStart;
        }
        return '';
    }

    async function saveForecastEdit() {
        const validation = validateEditItem();
        if (validation) {
            forecastSaveState.textContent = validation;
            return false;
        }
        forecastSaveState.textContent = i18n.saving;
        try {
            if (forecastEdit.kind === 'one_time') {
                const data = await apiPost('upsert_forecast_one_time', {
                    item: {
                        id: forecastEdit.item.id > 0 ? forecastEdit.item.id : null,
                        account_no: forecastEdit.item.account_no,
                        name: forecastEdit.item.name || '',
                        description: forecastEdit.item.description || '',
                        amount: Number(forecastEdit.item.amount) || 0,
                        event_date: forecastEdit.item.event_date
                    }
                });
                const saved = data.item;
                const idx = oneTimeItems.findIndex(function (row) { return row.id === forecastEdit.item.id; });
                if (idx >= 0) oneTimeItems[idx] = saved;
                else oneTimeItems.push(saved);
            } else {
                const data = await apiPost('upsert_forecast_rule', {
                    item: {
                        id: forecastEdit.item.id > 0 ? forecastEdit.item.id : null,
                        account_no: forecastEdit.item.account_no,
                        name: forecastEdit.item.name || '',
                        description: forecastEdit.item.description || '',
                        amount: Number(forecastEdit.item.amount) || 0,
                        start_date: forecastEdit.item.start_date,
                        repeat_n: Math.max(1, Number(forecastEdit.item.repeat_n) || 1),
                        repeat_unit: forecastEdit.item.repeat_unit || 'month',
                        end_date: forecastEdit.item.end_date || null
                    }
                });
                const saved = data.item;
                const idx = ruleItems.findIndex(function (row) { return row.id === forecastEdit.item.id; });
                if (idx >= 0) ruleItems[idx] = saved;
                else ruleItems.push(saved);
            }
            forecastDirty = true;
            forecastSaveState.textContent = i18n.saved;
            rebuildForecastAccountFilter();
            showForecastBrowse();
            return true;
        } catch (error) {
            forecastSaveState.textContent = i18n.saveFailed + ': ' + (error.message || error);
            return false;
        }
    }

    async function deleteForecastEdit() {
        if (!forecastEdit) return;
        forecastSaveState.textContent = i18n.saving;
        try {
            const id = forecastEdit.item.id;
            if (id > 0) {
                if (forecastEdit.kind === 'one_time') {
                    await apiPost('delete_forecast_one_time', { id: id });
                    oneTimeItems = oneTimeItems.filter(function (row) { return row.id !== id; });
                } else {
                    await apiPost('delete_forecast_rule', { id: id });
                    ruleItems = ruleItems.filter(function (row) { return row.id !== id; });
                }
            } else if (forecastEdit.kind === 'one_time') {
                oneTimeItems = oneTimeItems.filter(function (row) { return row !== forecastEdit.item && row.id !== id; });
            } else {
                ruleItems = ruleItems.filter(function (row) { return row !== forecastEdit.item && row.id !== id; });
            }
            forecastDirty = true;
            forecastSaveState.textContent = i18n.saved;
            rebuildForecastAccountFilter();
            showForecastBrowse();
        } catch (error) {
            forecastSaveState.textContent = i18n.saveFailed + ': ' + (error.message || error);
        }
    }

    async function openForecastModal() {
        forecastDirty = false;
        forecastSaveState.textContent = '';
        forecastSearch = '';
        forecastFilterAccount = '';
        forecastSearchInput.value = '';
        showForecastBrowse();
        openModal(forecastModal);
        oneTimeList.innerHTML = '<div class="moneta-empty" style="border:0">…</div>';
        rulesList.innerHTML = '';
        try {
            const data = await apiGet('forecast');
            oneTimeItems = data.one_time || [];
            ruleItems = data.rules || [];
            rebuildForecastAccountFilter();
            renderForecastLists();
        } catch (error) {
            oneTimeList.innerHTML = '';
            const empty = document.createElement('div');
            empty.className = 'moneta-empty';
            empty.textContent = error.message || String(error);
            oneTimeList.appendChild(empty);
        }
    }

    async function closeForecastModalAndRefresh() {
        if (forecastEdit) {
            showForecastBrowse();
            return;
        }
        closeModal(forecastModal);
        try {
            if (forecastDirty) {
                await refreshAllCharts();
                forecastDirty = false;
            }
        } catch (error) {
            forecastSaveState.textContent = i18n.saveFailed + ': ' + (error.message || error);
        }
    }

    // —— Account picker ——
    async function ensureGlAccounts() {
        if (glAccounts) return glAccounts;
        const data = await apiGet('gl_accounts');
        glAccounts = data.accounts || [];
        return glAccounts;
    }

    function renderPicker(filter) {
        const needle = String(filter || '').trim().toLowerCase();
        pickerList.innerHTML = '';
        const used = {};
        if (pickerMode && pickerMode.type === 'group' && groups[pickerMode.index]) {
            (groups[pickerMode.index].accounts || []).forEach(function (account) {
                used[account.account_no] = true;
            });
        }
        const matches = (glAccounts || []).filter(function (account) {
            if (used[account.account_no]) return false;
            if (!needle) return true;
            const hay = ((account.account_no || '') + ' ' + (account.account_name || '') + ' ' + (account.account_type || '')).toLowerCase();
            return hay.indexOf(needle) !== -1;
        });
        if (!matches.length) {
            const empty = document.createElement('div');
            empty.className = 'moneta-empty';
            empty.textContent = i18n.noAccounts;
            pickerList.appendChild(empty);
            return;
        }
        matches.slice(0, 200).forEach(function (account) {
            const btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'moneta-picker-item';
            const label = document.createElement('span');
            label.textContent = (account.account_no || '') + ' — ' + (account.account_name || '');
            const type = document.createElement('small');
            type.textContent = account.account_type || '';
            btn.appendChild(label);
            btn.appendChild(type);
            btn.addEventListener('click', function () {
                if (!pickerMode) return;
                if (pickerMode.type === 'group' && groups[pickerMode.index]) {
                    groups[pickerMode.index].accounts = groups[pickerMode.index].accounts || [];
                    groups[pickerMode.index].accounts.push({
                        account_no: account.account_no,
                        account_name: account.account_name,
                        negate: false
                    });
                    closeModal(pickerModal);
                    renderGroups();
                    scheduleGroupsSave();
                } else if (pickerMode.type === 'edit_account' && forecastEdit) {
                    forecastEdit.item.account_no = account.account_no;
                    forecastEdit.item.account_name = account.account_name;
                    closeModal(pickerModal);
                    renderForecastEditForm();
                }
            });
            pickerList.appendChild(btn);
        });
    }

    async function openPicker() {
        pickerSearch.value = '';
        openModal(pickerModal);
        pickerList.innerHTML = '<div class="moneta-empty">…</div>';
        try {
            await ensureGlAccounts();
            renderPicker('');
        } catch (error) {
            pickerList.innerHTML = '';
            const empty = document.createElement('div');
            empty.className = 'moneta-empty';
            empty.textContent = error.message || String(error);
            pickerList.appendChild(empty);
        }
    }

    // —— Events ——
    document.getElementById('moneta-add-balance-chart').addEventListener('click', function () {
        addChart('balance');
    });
    document.getElementById('moneta-add-derived-chart').addEventListener('click', function () {
        addChart('derived');
    });
    document.getElementById('moneta-open-forecast').addEventListener('click', openForecastModal);
    document.getElementById('moneta-close-groups').addEventListener('click', closeGroupsModalAndRefresh);
    document.getElementById('moneta-close-derived').addEventListener('click', closeDerivedModalAndRefresh);
    document.getElementById('moneta-close-forecast').addEventListener('click', closeForecastModalAndRefresh);
    document.getElementById('moneta-close-picker').addEventListener('click', function () {
        closeModal(pickerModal);
    });
    document.getElementById('moneta-add-group').addEventListener('click', function () {
        groups.push({ id: tempId--, name: i18n.groupDefault, sort_order: groups.length, accounts: [] });
        renderGroups();
        scheduleGroupsSave();
    });
    document.getElementById('moneta-add-derived-series').addEventListener('click', function () {
        const first = groupOptions[0] ? groupOptions[0].id : 0;
        const second = groupOptions[1] ? groupOptions[1].id : first;
        derivedSeries.push({
            id: tempId--,
            name: i18n.seriesDefault,
            left_group_id: first,
            operator: '+',
            right_group_id: second
        });
        renderDerived();
        scheduleDerivedSave();
    });
    document.getElementById('moneta-add-one-time').addEventListener('click', function () {
        openForecastEdit('one_time', {
            id: tempId--,
            account_no: '',
            account_name: '',
            name: '',
            description: '',
            amount: 0,
            event_date: today
        });
    });
    document.getElementById('moneta-add-rule').addEventListener('click', function () {
        openForecastEdit('rule', {
            id: tempId--,
            account_no: '',
            account_name: '',
            name: '',
            description: '',
            amount: 0,
            start_date: today,
            repeat_n: 1,
            repeat_unit: 'month',
            end_date: null
        });
    });
    document.getElementById('moneta-forecast-save-item').addEventListener('click', function () {
        saveForecastEdit();
    });
    document.getElementById('moneta-forecast-cancel-edit').addEventListener('click', function () {
        showForecastBrowse();
        forecastSaveState.textContent = '';
    });
    document.getElementById('moneta-forecast-delete-item').addEventListener('click', function () {
        deleteForecastEdit();
    });
    forecastSearchInput.addEventListener('input', function () {
        forecastSearch = forecastSearchInput.value;
        renderForecastLists();
    });
    forecastAccountFilter.addEventListener('change', function () {
        forecastFilterAccount = forecastAccountFilter.value;
        renderForecastLists();
    });
    pickerSearch.addEventListener('input', function () {
        renderPicker(pickerSearch.value);
    });
    groupsModal.addEventListener('click', function (e) {
        if (e.target === groupsModal) closeGroupsModalAndRefresh();
    });
    derivedModal.addEventListener('click', function (e) {
        if (e.target === derivedModal) closeDerivedModalAndRefresh();
    });
    forecastModal.addEventListener('click', function (e) {
        if (e.target === forecastModal) closeForecastModalAndRefresh();
    });
    pickerModal.addEventListener('click', function (e) {
        if (e.target === pickerModal) closeModal(pickerModal);
    });

    renderCharts();
})();
</script>
</body>
</html>

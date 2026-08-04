<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

/**
 * Includes/requires
 */
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/logincheck.php';
require_once __DIR__ . '/localization.php';
require_once __DIR__ . '/odata.php';
require_once __DIR__ . '/auth_helper.php';
require_once __DIR__ . '/moneta_data.php';

/**
 * Functies
 */

function moneta_h(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * Page load
 */

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

$forecastFrom = moneta_clamp_forecast_from((string) ($_GET['forecast_from'] ?? ''));
$forecastTo = moneta_parse_date((string) ($_GET['forecast_to'] ?? ''));
if ($forecastTo === '') {
    $forecastTo = moneta_default_forecast_to();
}
if ($forecastFrom > $forecastTo) {
    $forecastTo = $forecastFrom;
}

auth_set_current_company_context($company);

$errorKey = '';
$chartData = ['labels' => [], 'series' => []];
$forecastData = ['labels' => [], 'series' => [], 'meta' => []];
$chartGroups = [];

try {
    $chartGroups = moneta_list_chart_groups($company);
    $chartData = moneta_group_chart_data($company, $dateFrom, $dateTo);
    $forecastData = moneta_forecast_chart_data($company, $forecastFrom, $forecastTo);
} catch (Throwable $loadError) {
    $errorKey = 'moneta.error.load_failed';
}

$hasGroups = $chartGroups !== [];
$hasChartData = ($chartData['labels'] ?? []) !== [] && ($chartData['series'] ?? []) !== [];
$hasForecastData = ($forecastData['labels'] ?? []) !== [] && ($forecastData['series'] ?? []) !== [];
$chartJson = json_encode($chartData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
$forecastJson = json_encode($forecastData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
$groupsJson = json_encode($chartGroups, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
if (!is_string($chartJson)) {
    $chartJson = '{"labels":[],"series":[]}';
}
if (!is_string($forecastJson)) {
    $forecastJson = '{"labels":[],"series":[],"meta":{}}';
}
if (!is_string($groupsJson)) {
    $groupsJson = '[]';
}

$forecastMeta = is_array($forecastData['meta'] ?? null) ? $forecastData['meta'] : [];
$installmentCount = (int) ($forecastMeta['installment_count'] ?? 0);
$costCount = (int) ($forecastMeta['cost_count'] ?? 0);
$unassignedCount = (int) ($forecastMeta['unassigned_count'] ?? 0);

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
        .moneta-form-grid,
        .moneta-form-forecast { display: grid; gap: 12px; }
        .moneta-form label { display: grid; gap: 6px; font-weight: 700; color: var(--kvt-muted); }
        .moneta-form input, .moneta-form select, .moneta-btn {
            font: inherit; border-radius: 10px; border: 1px solid var(--kvt-line); padding: 12px 14px;
        }
        .moneta-form input, .moneta-form select { width: 100%; box-sizing: border-box; }
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
        }
        .moneta-btn-danger { color: var(--kvt-danger); border-color: #fecaca; }
        .moneta-alert {
            border: 1px solid #fecaca; background: #fef2f2; color: var(--kvt-danger);
            border-radius: 10px; padding: 12px 14px; margin-bottom: 16px;
        }
        .moneta-empty {
            border: 1px dashed var(--kvt-line); border-radius: 10px; padding: 24px 16px;
            color: var(--kvt-muted); text-align: center;
        }
        .moneta-chart-wrap { position: relative; height: 320px; width: 100%; }
        .moneta-section-title { font-size: 0.82rem; text-transform: uppercase; letter-spacing: 0.04em; color: var(--kvt-muted); margin: 4px 0 0; }
        .moneta-chart-head { display: flex; flex-wrap: wrap; gap: 12px; align-items: flex-start; justify-content: space-between; margin-bottom: 8px; }
        .moneta-chart-head > div { flex: 1 1 240px; }
        .moneta-save-state { font-size: 0.85rem; color: var(--kvt-muted); min-height: 1.2em; }
        .moneta-modal-backdrop {
            position: fixed; inset: 0; z-index: 13000; display: none; align-items: center; justify-content: center;
            padding: 16px; background: rgba(15, 23, 42, 0.45);
        }
        .moneta-modal-backdrop.is-open { display: flex; }
        .moneta-modal {
            background: #fff; border-radius: 14px; border: 1px solid var(--kvt-line);
            width: min(720px, 100%); max-height: min(86vh, 900px); overflow: auto;
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
            display: flex; gap: 8px; align-items: center; justify-content: space-between;
            padding: 6px 8px; border-radius: 8px; background: #f8fafc;
        }
        .moneta-account-row span { color: var(--kvt-text); font-size: 0.92rem; }
        .moneta-picker-search { width: 100%; box-sizing: border-box; margin-bottom: 10px; font: inherit;
            border-radius: 8px; border: 1px solid var(--kvt-line); padding: 10px 12px; }
        .moneta-picker-list { max-height: 360px; overflow: auto; display: grid; gap: 4px; }
        .moneta-picker-item {
            display: flex; justify-content: space-between; gap: 8px; width: 100%; text-align: left;
            border: 1px solid var(--kvt-line); background: #fff; border-radius: 8px; padding: 8px 10px;
            cursor: pointer; font: inherit;
        }
        .moneta-picker-item:hover { border-color: var(--kvt-main-blue); }
        .moneta-picker-item small { color: var(--kvt-muted); }
        @media (min-width: 640px) {
            .moneta-form-grid { grid-template-columns: 1.4fr 1fr 1fr; align-items: end; }
            .moneta-form-forecast { grid-template-columns: 1fr 1fr auto; align-items: end; }
            .moneta-form-forecast .moneta-btn { width: auto; min-width: 120px; }
            .moneta-chart-wrap { height: 400px; }
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
            <p class="moneta-section-title"><?= moneta_h(LOC('moneta.section.history')) ?></p>
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
            </div>

            <p class="moneta-section-title"><?= moneta_h(LOC('moneta.section.forecast')) ?></p>
            <div class="moneta-form-forecast">
                <label>
                    <?= moneta_h(LOC('moneta.label.forecast_from')) ?>
                    <input type="date" name="forecast_from" value="<?= moneta_h($forecastFrom) ?>" min="<?= moneta_h(date('Y-m-d')) ?>" required>
                </label>
                <label>
                    <?= moneta_h(LOC('moneta.label.forecast_to')) ?>
                    <input type="date" name="forecast_to" value="<?= moneta_h($forecastTo) ?>" min="<?= moneta_h(date('Y-m-d')) ?>" required>
                </label>
                <button class="moneta-btn contract-nav" type="submit"><?= moneta_h(LOC('moneta.btn.apply')) ?></button>
            </div>
        </form>
    </section>

    <?php if ($errorKey !== ''): ?>
        <div class="moneta-alert"><?= moneta_h(LOC($errorKey)) ?></div>
    <?php endif; ?>

    <section class="moneta-card">
        <div class="moneta-chart-head">
            <div>
                <h2><?= moneta_h(LOC('moneta.chart.gl_title')) ?></h2>
                <p class="moneta-subtitle"><?= moneta_h(LOC('moneta.chart.gl_subtitle')) ?></p>
            </div>
            <button type="button" class="moneta-btn-secondary" id="moneta-open-groups">
                <?= moneta_h(LOC('moneta.btn.edit_groups')) ?>
            </button>
        </div>

        <?php if ($hasChartData): ?>
            <div class="moneta-chart-wrap">
                <canvas id="moneta-gl-chart" aria-label="<?= moneta_h(LOC('moneta.chart.gl_title')) ?>"></canvas>
            </div>
        <?php elseif (!$hasGroups): ?>
            <div class="moneta-empty"><?= moneta_h(LOC('moneta.empty.groups')) ?></div>
        <?php else: ?>
            <div class="moneta-empty"><?= moneta_h(LOC('moneta.empty.gl')) ?></div>
        <?php endif; ?>
    </section>

    <section class="moneta-card">
        <h2><?= moneta_h(LOC('moneta.chart.forecast_title')) ?></h2>
        <p class="moneta-subtitle"><?= moneta_h(LOC('moneta.chart.forecast_subtitle')) ?></p>
        <?php if ($hasForecastData && ($installmentCount > 0 || $costCount > 0)): ?>
            <p class="moneta-subtitle">
                <?= moneta_h(sprintf(LOC('moneta.chart.forecast_meta'), $installmentCount, $costCount, $unassignedCount)) ?>
            </p>
        <?php endif; ?>

        <?php if ($hasForecastData): ?>
            <div class="moneta-chart-wrap">
                <canvas id="moneta-forecast-chart" aria-label="<?= moneta_h(LOC('moneta.chart.forecast_title')) ?>"></canvas>
            </div>
        <?php else: ?>
            <div class="moneta-empty"><?= moneta_h(LOC('moneta.empty.forecast')) ?></div>
        <?php endif; ?>
    </section>
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
        if (!loader) {
            return;
        }
        clearTimeout(loaderTimer);
        loaderTimer = setTimeout(function () {
            loader.classList.add('is-visible');
            loader.setAttribute('aria-hidden', 'false');
        }, 500);
    }

    document.querySelectorAll('a.contract-nav, button.contract-nav, form .contract-nav').forEach(function (el) {
        const form = el.closest('form');
        if (form) {
            form.addEventListener('submit', showLoader);
        } else {
            el.addEventListener('click', showLoader);
        }
    });

    const palette = [
        '#00529B', '#0099cc', '#15803d', '#b45309', '#be123c',
        '#0f766e', '#7c3aed', '#0369a1', '#4d7c0f', '#c2410c'
    ];

    function formatDateLabel(value) {
        const match = String(value || '').match(/^(\d{4})-(\d{2})-(\d{2})$/);
        if (!match) {
            return value;
        }
        return match[3] + '-' + match[2] + '-' + match[1];
    }

    function formatEuro(value) {
        const number = Number(value);
        if (!Number.isFinite(number)) {
            return '';
        }
        return new Intl.NumberFormat('nl-NL', {
            style: 'currency',
            currency: 'EUR',
            maximumFractionDigits: 0
        }).format(number);
    }

    function renderLineChart(canvasId, chartPayload) {
        const canvas = document.getElementById(canvasId);
        if (!canvas || typeof Chart === 'undefined' || !chartPayload || !Array.isArray(chartPayload.labels) || chartPayload.labels.length === 0) {
            return;
        }

        const datasets = (chartPayload.series || []).map(function (serie, index) {
            const color = palette[index % palette.length];
            return {
                label: serie.name || serie.account_no || ('Serie ' + (index + 1)),
                data: serie.data || [],
                borderColor: color,
                backgroundColor: color,
                tension: 0.25,
                pointRadius: 2,
                pointHoverRadius: 4,
                borderWidth: 2,
                spanGaps: true
            };
        });

        new Chart(canvas, {
            type: 'line',
            data: {
                labels: chartPayload.labels.map(formatDateLabel),
                datasets: datasets
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: {
                    mode: 'nearest',
                    intersect: false
                },
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: {
                            boxWidth: 12,
                            usePointStyle: true
                        }
                    },
                    tooltip: {
                        callbacks: {
                            label: function (context) {
                                const label = context.dataset.label || '';
                                return label + ': ' + formatEuro(context.parsed.y);
                            }
                        }
                    }
                },
                scales: {
                    x: {
                        ticks: {
                            maxRotation: 0,
                            autoSkip: true,
                            maxTicksLimit: 8
                        },
                        grid: {
                            display: false
                        }
                    },
                    y: {
                        ticks: {
                            callback: function (value) {
                                return formatEuro(value);
                            }
                        }
                    }
                }
            }
        });
    }

    renderLineChart('moneta-gl-chart', <?= $chartJson ?>);
    renderLineChart('moneta-forecast-chart', <?= $forecastJson ?>);

    const company = <?= json_encode($company, JSON_UNESCAPED_UNICODE) ?>;
    const i18n = {
        saving: <?= json_encode(LOC('moneta.groups.saving'), JSON_UNESCAPED_UNICODE) ?>,
        saved: <?= json_encode(LOC('moneta.groups.saved'), JSON_UNESCAPED_UNICODE) ?>,
        saveFailed: <?= json_encode(LOC('moneta.groups.save_failed'), JSON_UNESCAPED_UNICODE) ?>,
        groupDefault: <?= json_encode(LOC('moneta.groups.default_name'), JSON_UNESCAPED_UNICODE) ?>,
        removeGroup: <?= json_encode(LOC('moneta.groups.remove'), JSON_UNESCAPED_UNICODE) ?>,
        addAccount: <?= json_encode(LOC('moneta.groups.add_account'), JSON_UNESCAPED_UNICODE) ?>,
        removeAccount: <?= json_encode(LOC('moneta.groups.remove_account'), JSON_UNESCAPED_UNICODE) ?>,
        noAccounts: <?= json_encode(LOC('moneta.picker.empty'), JSON_UNESCAPED_UNICODE) ?>,
        reloadHint: <?= json_encode(LOC('moneta.groups.reload_hint'), JSON_UNESCAPED_UNICODE) ?>
    };

    let groups = <?= $groupsJson ?>;
    let glAccounts = null;
    let pickerGroupIndex = null;
    let saveTimer = null;
    let tempId = -1;

    const groupsModal = document.getElementById('moneta-groups-modal');
    const pickerModal = document.getElementById('moneta-picker-modal');
    const groupsList = document.getElementById('moneta-groups-list');
    const saveState = document.getElementById('moneta-groups-save-state');
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

    function normalizeGroupsForSave() {
        return groups.map(function (group) {
            return {
                id: group.id > 0 ? group.id : null,
                name: group.name || i18n.groupDefault,
                accounts: (group.accounts || []).map(function (account) {
                    return account.account_no;
                })
            };
        });
    }

    function scheduleSave() {
        clearTimeout(saveTimer);
        saveState.textContent = i18n.saving;
        saveTimer = setTimeout(persistGroups, 400);
    }

    async function persistGroups() {
        try {
            const response = await fetch('moneta_api.php?action=save_groups&company=' + encodeURIComponent(company), {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json'
                },
                credentials: 'same-origin',
                body: JSON.stringify({ groups: normalizeGroupsForSave() })
            });
            const data = await response.json();
            if (!response.ok || !data.ok) {
                throw new Error((data && data.error) || ('HTTP ' + response.status));
            }
            groups = data.groups || [];
            renderGroups();
            saveState.textContent = i18n.saved + ' — ' + i18n.reloadHint;
        } catch (error) {
            saveState.textContent = i18n.saveFailed + ': ' + (error.message || error);
        }
    }

    function renderGroups() {
        groupsList.innerHTML = '';
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
                scheduleSave();
            });

            const addBtn = document.createElement('button');
            addBtn.type = 'button';
            addBtn.className = 'moneta-btn-icon';
            addBtn.title = i18n.addAccount;
            addBtn.textContent = '+';
            addBtn.addEventListener('click', function () {
                openPicker(groupIndex);
            });

            const removeBtn = document.createElement('button');
            removeBtn.type = 'button';
            removeBtn.className = 'moneta-btn-icon moneta-btn-danger';
            removeBtn.title = i18n.removeGroup;
            removeBtn.textContent = '×';
            removeBtn.addEventListener('click', function () {
                groups.splice(groupIndex, 1);
                renderGroups();
                scheduleSave();
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
                const del = document.createElement('button');
                del.type = 'button';
                del.className = 'moneta-btn-icon moneta-btn-danger';
                del.title = i18n.removeAccount;
                del.textContent = '×';
                del.addEventListener('click', function () {
                    groups[groupIndex].accounts.splice(accountIndex, 1);
                    renderGroups();
                    scheduleSave();
                });
                row.appendChild(label);
                row.appendChild(del);
                list.appendChild(row);
            });
            box.appendChild(list);
            groupsList.appendChild(box);
        });
    }

    async function ensureGlAccounts() {
        if (glAccounts) {
            return glAccounts;
        }
        const response = await fetch('moneta_api.php?action=gl_accounts&company=' + encodeURIComponent(company), {
            headers: { 'Accept': 'application/json' },
            credentials: 'same-origin'
        });
        const data = await response.json();
        if (!response.ok || !data.ok) {
            throw new Error((data && data.error) || ('HTTP ' + response.status));
        }
        glAccounts = data.accounts || [];
        return glAccounts;
    }

    function renderPicker(filter) {
        const needle = String(filter || '').trim().toLowerCase();
        pickerList.innerHTML = '';
        const used = {};
        if (pickerGroupIndex !== null && groups[pickerGroupIndex]) {
            (groups[pickerGroupIndex].accounts || []).forEach(function (account) {
                used[account.account_no] = true;
            });
        }
        const matches = (glAccounts || []).filter(function (account) {
            if (used[account.account_no]) {
                return false;
            }
            if (!needle) {
                return true;
            }
            const hay = ((account.account_no || '') + ' ' + (account.account_name || '') + ' ' + (account.account_type || '')).toLowerCase();
            return hay.indexOf(needle) !== -1;
        });
        if (matches.length === 0) {
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
                if (pickerGroupIndex === null || !groups[pickerGroupIndex]) {
                    return;
                }
                groups[pickerGroupIndex].accounts = groups[pickerGroupIndex].accounts || [];
                groups[pickerGroupIndex].accounts.push({
                    account_no: account.account_no,
                    account_name: account.account_name
                });
                closeModal(pickerModal);
                renderGroups();
                scheduleSave();
            });
            pickerList.appendChild(btn);
        });
    }

    async function openPicker(groupIndex) {
        pickerGroupIndex = groupIndex;
        pickerSearch.value = '';
        openModal(pickerModal);
        pickerList.innerHTML = '<div class="moneta-empty">…</div>';
        try {
            await ensureGlAccounts();
            renderPicker('');
        } catch (error) {
            pickerList.innerHTML = '<div class="moneta-empty">' + (error.message || error) + '</div>';
        }
    }

    document.getElementById('moneta-open-groups').addEventListener('click', function () {
        renderGroups();
        saveState.textContent = '';
        openModal(groupsModal);
    });
    document.getElementById('moneta-close-groups').addEventListener('click', function () {
        closeModal(groupsModal);
    });
    document.getElementById('moneta-close-picker').addEventListener('click', function () {
        closeModal(pickerModal);
    });
    document.getElementById('moneta-add-group').addEventListener('click', function () {
        groups.push({
            id: tempId--,
            name: i18n.groupDefault,
            sort_order: groups.length,
            accounts: []
        });
        renderGroups();
        scheduleSave();
    });
    pickerSearch.addEventListener('input', function () {
        renderPicker(pickerSearch.value);
    });
    groupsModal.addEventListener('click', function (event) {
        if (event.target === groupsModal) {
            closeModal(groupsModal);
        }
    });
    pickerModal.addEventListener('click', function (event) {
        if (event.target === pickerModal) {
            closeModal(pickerModal);
        }
    });
})();
</script>
</body>
</html>

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

function moneta_url(array $params = []): string
{
    $query = $_GET;
    foreach ($params as $key => $value) {
        if ($value === null || $value === '') {
            unset($query[$key]);
            continue;
        }
        $query[$key] = $value;
    }
    unset($query['lang'], $query['_loaded']);

    $path = strtok((string) ($_SERVER['REQUEST_URI'] ?? 'index.php'), '?') ?: 'index.php';
    $lang = getCurrentLanguage();
    $query['lang'] = $lang;

    return $path . '?' . http_build_query($query);
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

auth_set_current_company_context($company);

$errorKey = '';
$chartData = ['labels' => [], 'series' => []];

try {
    $chartData = moneta_bank_chart_data($company, $dateFrom, $dateTo);
} catch (Throwable $loadError) {
    $errorKey = 'moneta.error.load_failed';
}

$hasChartData = ($chartData['labels'] ?? []) !== [] && ($chartData['series'] ?? []) !== [];
$chartJson = json_encode($chartData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
if (!is_string($chartJson)) {
    $chartJson = '{"labels":[],"series":[]}';
}

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
        .moneta-form input, .moneta-form select, .moneta-btn {
            font: inherit; border-radius: 10px; border: 1px solid var(--kvt-line); padding: 12px 14px;
        }
        .moneta-form input, .moneta-form select { width: 100%; box-sizing: border-box; }
        .moneta-btn {
            background: var(--kvt-main-blue); color: #fff; border-color: var(--kvt-main-blue);
            cursor: pointer; text-decoration: none; display: inline-block; text-align: center;
        }
        .moneta-alert {
            border: 1px solid #fecaca; background: #fef2f2; color: var(--kvt-danger);
            border-radius: 10px; padding: 12px 14px; margin-bottom: 16px;
        }
        .moneta-empty {
            border: 1px dashed var(--kvt-line); border-radius: 10px; padding: 24px 16px;
            color: var(--kvt-muted); text-align: center;
        }
        .moneta-chart-wrap { position: relative; height: 320px; width: 100%; }
        @media (min-width: 640px) {
            .moneta-form-grid { grid-template-columns: 1.2fr 1fr 1fr auto; align-items: end; }
            .moneta-form-grid .moneta-btn { width: auto; min-width: 120px; }
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
                <button class="moneta-btn contract-nav" type="submit"><?= moneta_h(LOC('moneta.btn.apply')) ?></button>
            </div>
        </form>
    </section>

    <?php if ($errorKey !== ''): ?>
        <div class="moneta-alert"><?= moneta_h(LOC($errorKey)) ?></div>
    <?php endif; ?>

    <section class="moneta-card">
        <h2><?= moneta_h(LOC('moneta.chart.bank_title')) ?></h2>
        <p class="moneta-subtitle"><?= moneta_h(LOC('moneta.chart.bank_subtitle')) ?></p>

        <?php if ($hasChartData): ?>
            <div class="moneta-chart-wrap">
                <canvas id="moneta-bank-chart" aria-label="<?= moneta_h(LOC('moneta.chart.bank_title')) ?>"></canvas>
            </div>
        <?php else: ?>
            <div class="moneta-empty"><?= moneta_h(LOC('moneta.empty.bank')) ?></div>
        <?php endif; ?>
    </section>
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

    const chartPayload = <?= $chartJson ?>;
    const canvas = document.getElementById('moneta-bank-chart');
    if (!canvas || typeof Chart === 'undefined' || !chartPayload || !Array.isArray(chartPayload.labels)) {
        return;
    }

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

    const datasets = (chartPayload.series || []).map(function (serie, index) {
        const color = palette[index % palette.length];
        return {
            label: serie.name || serie.account_no || ('Rekening ' + (index + 1)),
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
})();
</script>
</body>
</html>

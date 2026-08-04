<?php

/**
 * Technische backfill-pagina voor Rekeningschema-saldi.
 * Niet gelinkt vanuit de UI — alleen via directe URL.
 *
 * Backfill: van opgegeven startdatum (verleden) tot de dag vóór de eerste
 * opgeslagen snapshot. Per dag: ophalen → sparsely opslaan → volgende (AJAX).
 * Onderbreken is veilig: reeds verwerkte dagen blijven staan.
 */

set_time_limit(300);
ini_set('max_execution_time', '300');

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/logincheck.php';
require_once __DIR__ . '/odata.php';
require_once __DIR__ . '/auth_helper.php';
require_once __DIR__ . '/moneta_data.php';

function moneta_backfill_h(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

$isAjax = isset($_GET['ajax']) || isset($_POST['ajax'])
    || str_contains((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json');

$companies = project_companies_for_page();
$company = trim((string) ($_GET['company'] ?? $_POST['company'] ?? ''));
if ($company === '' || !in_array($company, $companies, true)) {
    $company = (string) ($companies[0] ?? '');
}

$action = trim((string) ($_GET['action'] ?? $_POST['action'] ?? ''));

if ($action !== '' && $isAjax) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');

    try {
        if ($company === '') {
            throw new InvalidArgumentException('Geen bedrijf geselecteerd.');
        }
        auth_set_current_company_context($company);

        if ($action === 'status') {
            $first = moneta_first_gl_snapshot_date($company);
            $latest = moneta_latest_gl_snapshot_date($company);
            $ceiling = moneta_ensure_gl_backfill_ceiling($company);
            $rangeEnd = moneta_backfill_range_end($company);
            $earliestPosting = moneta_earliest_gl_posting_date($company, 600);
            $accountCount = count(moneta_list_gl_accounts($company));
            echo json_encode([
                'ok' => true,
                'company' => $company,
                'first_snapshot_date' => $first,
                'latest_snapshot_date' => $latest,
                'backfill_ceiling_date' => $ceiling,
                'backfill_end_date' => $rangeEnd,
                'earliest_gl_posting_date' => $earliestPosting,
                'gl_account_catalog_count' => $accountCount,
                'entity' => MONETA_GL_ENTITY,
                'odata_filter_template' => "Date_Filter eq '..YYYY-MM-DD'",
                'select' => MONETA_GL_SELECT,
                'ttl_seconds' => MONETA_NIGHTLY_ODATA_TTL,
                'storage' => 'SQLite gl_balance_snapshots (sparse: ongewijzigd t.o.v. vorige dag wordt overgeslagen)',
                'notes' => [
                    'Date_Filter werkt: Balance_at_Date is cumulatief t/m de gekozen dag.',
                    'Vóór earliest_gl_posting_date bestaan er geen G_LEntries → saldi zijn terecht 0.',
                    'backfill_end_date = backfill_ceiling − 1 (ceiling = eerste nightly-dag).',
                    'run_day krijgt end_date mee zodat de range tijdens de run niet opschuift.',
                ],
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            exit;
        }

        if ($action === 'run_day') {
            $day = moneta_parse_date((string) ($_GET['date'] ?? $_POST['date'] ?? ''));
            if ($day === '') {
                throw new InvalidArgumentException('Parameter date (YYYY-MM-DD) is verplicht.');
            }
            $today = date('Y-m-d');
            if ($day >= $today) {
                throw new InvalidArgumentException("Backfill alleen voor verleden dagen (gevraagd={$day}, today={$today}).");
            }

            // Vast eind van deze run (client locked bij start). Zo schuift het einde niet op
            // wanneer de net opgeslagen backfill-dag de nieuwe MIN(snapshot) wordt.
            $rangeEnd = moneta_parse_date((string) ($_GET['end_date'] ?? $_POST['end_date'] ?? ''));
            if ($rangeEnd === '') {
                $rangeEnd = moneta_backfill_range_end($company);
            }
            $suggestedEnd = moneta_backfill_range_end($company);
            if ($rangeEnd > $suggestedEnd) {
                $rangeEnd = $suggestedEnd;
            }
            if ($day > $rangeEnd) {
                throw new InvalidArgumentException(
                    "Datum {$day} ligt na backfill-einde {$rangeEnd} (locked end_date / ceiling−1)."
                );
            }

            $startedAt = hrtime(true);
            $result = moneta_snapshot_gl_balances_for_company($company, $day, MONETA_NIGHTLY_ODATA_TTL, true);
            $durationMs = (int) round((hrtime(true) - $startedAt) / 1_000_000);

            echo json_encode([
                'ok' => true,
                'company' => $company,
                'date' => $day,
                'accounts_fetched' => (int) ($result['accounts'] ?? 0),
                'rows_stored' => (int) ($result['stored'] ?? 0),
                'group_balances_stored' => (int) ($result['group_balances_stored'] ?? 0),
                'duration_ms' => $durationMs,
                'backfill_end_date' => $rangeEnd,
                'next_hint' => $day < $rangeEnd
                    ? (new DateTimeImmutable($day))->modify('+1 day')->format('Y-m-d')
                    : null,
                'message' => sprintf(
                    'Day %s: fetched %d Rekeningschema rows, stored %d GL + %d group balances in %d ms.',
                    $day,
                    (int) ($result['accounts'] ?? 0),
                    (int) ($result['stored'] ?? 0),
                    (int) ($result['group_balances_stored'] ?? 0),
                    $durationMs
                ),
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            exit;
        }

        throw new InvalidArgumentException('Onbekende action. Gebruik status of run_day.');
    } catch (Throwable $error) {
        http_response_code(500);
        echo json_encode([
            'ok' => false,
            'error' => $error->getMessage(),
            'company' => $company,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}

auth_set_current_company_context($company);
$firstSnapshot = $company !== '' ? moneta_first_gl_snapshot_date($company) : '';
$latestSnapshot = $company !== '' ? moneta_latest_gl_snapshot_date($company) : '';
$ceilingSnapshot = $company !== '' ? moneta_ensure_gl_backfill_ceiling($company) : '';
$rangeEnd = $company !== '' ? moneta_backfill_range_end($company) : '';
$earliestPosting = '';
try {
    $earliestPosting = $company !== '' ? moneta_earliest_gl_posting_date($company, 600) : '';
} catch (Throwable $ignored) {
    $earliestPosting = '';
}
$defaultStart = $earliestPosting !== '' ? $earliestPosting : (new DateTimeImmutable('today'))->modify('-1 year')->format('Y-m-d');
if ($rangeEnd !== '' && $defaultStart > $rangeEnd) {
    $defaultStart = $rangeEnd;
}

?><!DOCTYPE html>
<html lang="nl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Moneta — GL backfill</title>
    <link rel="stylesheet" href="brand.css">
    <style>
        body { margin: 0; font: 14px/1.45 Consolas, "Segoe UI Mono", monospace; background: #0b1220; color: #e2e8f0; }
        .wrap { max-width: 960px; margin: 0 auto; padding: 20px; }
        h1 { font: 700 1.4rem/1.2 system-ui, sans-serif; margin: 0 0 8px; color: #f8fafc; }
        .muted { color: #94a3b8; margin: 0 0 16px; font-family: system-ui, sans-serif; }
        .panel {
            background: #111827; border: 1px solid #334155; border-radius: 10px;
            padding: 14px 16px; margin-bottom: 14px;
        }
        .panel h2 { margin: 0 0 10px; font: 700 0.95rem system-ui, sans-serif; color: #cbd5e1; }
        label { display: grid; gap: 4px; margin-bottom: 10px; color: #94a3b8; }
        input, select, button {
            font: inherit; padding: 8px 10px; border-radius: 8px;
            border: 1px solid #475569; background: #0f172a; color: #f1f5f9;
        }
        button {
            background: #00529B; border-color: #00529B; cursor: pointer; font-weight: 700;
            font-family: system-ui, sans-serif;
        }
        button:disabled { opacity: 0.5; cursor: not-allowed; }
        button.secondary { background: #334155; border-color: #475569; }
        .row { display: flex; flex-wrap: wrap; gap: 10px; align-items: end; }
        .row > * { flex: 1 1 160px; }
        .meta { display: grid; gap: 4px; font-size: 0.92rem; }
        .meta code { color: #7dd3fc; }
        #log {
            height: 420px; overflow: auto; white-space: pre-wrap; word-break: break-word;
            background: #020617; border: 1px solid #1e293b; border-radius: 8px;
            padding: 10px; color: #a7f3d0;
        }
        .log-err { color: #fca5a5; }
        .log-info { color: #93c5fd; }
        .log-ok { color: #86efac; }
        .progress { height: 8px; background: #1e293b; border-radius: 999px; overflow: hidden; margin-top: 8px; }
        .progress > span { display: block; height: 100%; width: 0; background: #22c55e; transition: width 0.2s ease; }
    </style>
</head>
<body>
<div class="wrap">
    <h1>GL balance backfill</h1>
    <p class="muted">
        Technische tool. Entity <code>Rekeningschema</code> met
        <code>$filter=Date_Filter eq '..YYYY-MM-DD'</code>.
        Sparse opslag in <code>gl_balance_snapshots</code>. Geen menu-link — bookmark de URL.
        Backfill alleen vanaf de eerste G_LEntries-boekingsdatum; eerder is Balance_at_Date terecht 0.
    </p>

    <div class="panel">
        <h2>Status</h2>
        <div class="meta" id="status-meta">
            <div>company: <code><?= moneta_backfill_h($company) ?></code></div>
            <div>first_snapshot_date: <code><?= moneta_backfill_h($firstSnapshot !== '' ? $firstSnapshot : '(none)') ?></code></div>
            <div>latest_snapshot_date: <code><?= moneta_backfill_h($latestSnapshot !== '' ? $latestSnapshot : '(none)') ?></code></div>
            <div>earliest_gl_posting_date: <code><?= moneta_backfill_h($earliestPosting !== '' ? $earliestPosting : '(detecting…)') ?></code></div>
            <div>backfill_ceiling_date: <code><?= moneta_backfill_h($ceilingSnapshot !== '' ? $ceilingSnapshot : '(none)') ?></code></div>
            <div>backfill_end_date (= ceiling−1): <code><?= moneta_backfill_h($rangeEnd) ?></code></div>
            <div>odata_ttl: <code><?= (int) MONETA_NIGHTLY_ODATA_TTL ?>s</code></div>
        </div>
    </div>

    <div class="panel">
        <h2>Run parameters</h2>
        <div class="row">
            <label>
                company
                <select id="company">
                    <?php foreach ($companies as $option): ?>
                        <option value="<?= moneta_backfill_h($option) ?>"<?= $option === $company ? ' selected' : '' ?>>
                            <?= moneta_backfill_h($option) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>
                start_date (inclusive, past)
                <input type="date" id="start_date" value="<?= moneta_backfill_h($defaultStart) ?>">
            </label>
            <label>
                end_date (auto, inclusive)
                <input type="date" id="end_date" value="<?= moneta_backfill_h($rangeEnd) ?>" readonly>
            </label>
        </div>
        <div class="row">
            <button type="button" id="btn-refresh">Refresh status</button>
            <button type="button" id="btn-start">Start day-by-day backfill</button>
            <button type="button" class="secondary" id="btn-stop" disabled>Stop after current day</button>
        </div>
        <div class="progress" aria-hidden="true"><span id="progress-bar"></span></div>
        <div class="muted" id="progress-label" style="margin-top:8px;">Idle.</div>
    </div>

    <div class="panel">
        <h2>Log</h2>
        <div id="log"></div>
    </div>
</div>

<script>
(function () {
    const logEl = document.getElementById('log');
    const companyEl = document.getElementById('company');
    const startEl = document.getElementById('start_date');
    const endEl = document.getElementById('end_date');
    const statusMeta = document.getElementById('status-meta');
    const progressBar = document.getElementById('progress-bar');
    const progressLabel = document.getElementById('progress-label');
    const btnRefresh = document.getElementById('btn-refresh');
    const btnStart = document.getElementById('btn-start');
    const btnStop = document.getElementById('btn-stop');

    let stopRequested = false;
    let running = false;

    function log(message, cls) {
        const line = document.createElement('div');
        if (cls) {
            line.className = cls;
        }
        line.textContent = '[' + new Date().toISOString() + '] ' + message;
        logEl.appendChild(line);
        logEl.scrollTop = logEl.scrollHeight;
    }

    function daysBetween(from, to) {
        const a = new Date(from + 'T00:00:00Z');
        const b = new Date(to + 'T00:00:00Z');
        return Math.round((b - a) / 86400000);
    }

    function addDays(iso, n) {
        const d = new Date(iso + 'T00:00:00Z');
        d.setUTCDate(d.getUTCDate() + n);
        return d.toISOString().slice(0, 10);
    }

    async function api(action, params) {
        const url = new URL(window.location.href);
        url.search = '';
        url.searchParams.set('ajax', '1');
        url.searchParams.set('action', action);
        Object.keys(params || {}).forEach(function (key) {
            url.searchParams.set(key, params[key]);
        });
        const response = await fetch(url.toString(), {
            headers: { 'Accept': 'application/json' },
            credentials: 'same-origin'
        });
        const text = await response.text();
        let data;
        try {
            data = JSON.parse(text);
        } catch (e) {
            throw new Error('Non-JSON response (' + response.status + '): ' + text.slice(0, 400));
        }
        if (!response.ok || !data.ok) {
            throw new Error((data && data.error) ? data.error : ('HTTP ' + response.status));
        }
        return data;
    }

    async function refreshStatus(options) {
        options = options || {};
        const company = companyEl.value;
        log('GET status company=' + company, 'log-info');
        const data = await api('status', { company: company });
        if (!options.keepEndDate) {
            endEl.value = data.backfill_end_date || '';
        }
        if (!options.keepStartDate && data.earliest_gl_posting_date) {
            const earliest = data.earliest_gl_posting_date;
            if (!startEl.value || startEl.value < earliest) {
                startEl.value = earliest;
            }
        }
        statusMeta.innerHTML =
            '<div>company: <code>' + data.company + '</code></div>' +
            '<div>first_snapshot_date: <code>' + (data.first_snapshot_date || '(none)') + '</code></div>' +
            '<div>latest_snapshot_date: <code>' + (data.latest_snapshot_date || '(none)') + '</code></div>' +
            '<div>earliest_gl_posting_date: <code>' + (data.earliest_gl_posting_date || '(none)') + '</code></div>' +
            '<div>backfill_ceiling_date: <code>' + (data.backfill_ceiling_date || '(none)') + '</code></div>' +
            '<div>backfill_end_date: <code>' + (data.backfill_end_date || '') + '</code></div>' +
            '<div>gl_account_catalog_count: <code>' + data.gl_account_catalog_count + '</code></div>' +
            '<div>filter: <code>' + data.odata_filter_template + '</code></div>' +
            '<div>select: <code>' + data.select + '</code></div>' +
            '<div>storage: <code>' + data.storage + '</code></div>';
        log('status ok first=' + (data.first_snapshot_date || 'none')
            + ' earliest_posting=' + (data.earliest_gl_posting_date || 'none')
            + ' ceiling=' + (data.backfill_ceiling_date || 'none')
            + ' end=' + data.backfill_end_date
            + ' catalog=' + data.gl_account_catalog_count, 'log-ok');
        if (Array.isArray(data.notes)) {
            data.notes.forEach(function (note) {
                log('note: ' + note, 'log-info');
            });
        }
        return data;
    }

    async function runBackfill() {
        if (running) {
            return;
        }
        running = true;
        stopRequested = false;
        btnStart.disabled = true;
        btnStop.disabled = false;
        btnRefresh.disabled = true;

        try {
            const status = await refreshStatus();
            const company = companyEl.value;
            let cursor = startEl.value;
            const end = endEl.value || status.backfill_end_date;
            const earliest = status.earliest_gl_posting_date || '';
            if (earliest && cursor < earliest) {
                log('Clamping start_date from ' + cursor + ' to earliest_gl_posting_date ' + earliest, 'log-info');
                cursor = earliest;
                startEl.value = earliest;
            }
            if (!cursor || !end) {
                throw new Error('start_date en end_date zijn verplicht.');
            }
            if (cursor > end) {
                throw new Error('start_date (' + cursor + ') > end_date (' + end + '). Niets te doen — mogelijk bestaat er al eerdere data.');
            }

            const totalDays = daysBetween(cursor, end) + 1;
            let done = 0;
            log('Start backfill company=' + company + ' range=' + cursor + '..' + end
                + ' (' + totalDays + ' days), sequential AJAX; end_date locked for this run', 'log-info');

            while (cursor <= end) {
                if (stopRequested) {
                    log('Stop requested after completing previous day. Halted before ' + cursor + '.', 'log-info');
                    break;
                }
                progressLabel.textContent = 'Running ' + cursor + ' (' + (done + 1) + '/' + totalDays + ')…';
                const dayResult = await api('run_day', {
                    company: company,
                    date: cursor,
                    end_date: end
                });
                done++;
                const pct = Math.round((done / totalDays) * 100);
                progressBar.style.width = pct + '%';
                log(dayResult.message
                    + ' next=' + (dayResult.next_hint || 'done'), 'log-ok');
                cursor = addDays(cursor, 1);
            }

            progressLabel.textContent = stopRequested
                ? 'Stopped. Processed ' + done + '/' + totalDays + ' days. Data tot hier is opgeslagen.'
                : 'Finished. Processed ' + done + '/' + totalDays + ' days.';
            await refreshStatus({ keepEndDate: true });
        } catch (error) {
            log('FAIL: ' + (error && error.message ? error.message : error), 'log-err');
            progressLabel.textContent = 'Failed — zie log. Reeds opgeslagen dagen blijven staan.';
        } finally {
            running = false;
            btnStart.disabled = false;
            btnStop.disabled = true;
            btnRefresh.disabled = false;
        }
    }

    btnRefresh.addEventListener('click', function () {
        refreshStatus().catch(function (error) {
            log('FAIL status: ' + error.message, 'log-err');
        });
    });
    btnStart.addEventListener('click', runBackfill);
    btnStop.addEventListener('click', function () {
        stopRequested = true;
        log('Stop requested — huidige dag wordt afgemaakt, daarna halt.', 'log-info');
    });
    companyEl.addEventListener('change', function () {
        refreshStatus().catch(function (error) {
            log('FAIL status: ' + error.message, 'log-err');
        });
    });

    log('Page ready. Endpoint: backfill.php?ajax=1&action=status|run_day', 'log-info');
})();
</script>
</body>
</html>

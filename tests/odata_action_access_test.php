<?php
/**
 * odata.php-acties zijn niet anoniem, en cache_status lekt geen voorbeeldrijen.
 * De FTP-deploy uploadt .htaccess/index in data/ en cache/ en laat sqlite met rust.
 * Run: php tests/odata_action_access_test.php
 */

require dirname(__DIR__) . '/web/odata.php';

function fail(string $message): void
{
    fwrite(STDERR, "FAIL: $message\n");
    exit(1);
}

function assert_same($expected, $actual, string $label): void
{
    if ($expected !== $actual) {
        fail($label . ' verwacht ' . var_export($expected, true) . ' kreeg ' . var_export($actual, true));
    }
}

function assert_contains(string $haystack, string $needle, string $label): void
{
    if (strpos($haystack, $needle) === false) {
        fail($label . ' mist "' . $needle . '"');
    }
}

function assert_not_contains(string $haystack, string $needle, string $label): void
{
    if (strpos($haystack, $needle) !== false) {
        fail($label . ' bevat "' . $needle . '"');
    }
}

$root = dirname(__DIR__);
$odataSource = (string) file_get_contents($root . '/web/odata.php');
$dispatch = substr($odataSource, (int) strrpos($odataSource, '$odataAction = '));
$requireAt = strpos($dispatch, 'odata_require_direct_action_access()');
$statusAt = strpos($dispatch, 'odata_send_cache_status_json()');
$deleteAt = strpos($dispatch, 'odata_send_cache_delete_json()');
$clearAt = strpos($dispatch, 'odata_send_cache_clear_json()');
if ($requireAt === false || $statusAt === false || $deleteAt === false || $clearAt === false) {
    fail('actie-dispatch mist login of een cache-actie');
}
if ($requireAt > $statusAt || $requireAt > $deleteAt || $requireAt > $clearAt) {
    fail('logincheck moet vóór cache_status, cache_delete en cache_clear');
}
$gateStart = strpos($odataSource, 'function odata_require_direct_action_access');
$gateEnd = strpos($odataSource, 'function odata_release_session_lock');
if ($gateStart === false || $gateEnd === false || $gateEnd < $gateStart) {
    fail('login-gate of sessie-vrijgave ontbreekt');
}
$gate = substr($odataSource, $gateStart, $gateEnd - $gateStart);
$emailAt = strpos($gate, 'odata_email_is_allowed');
$releaseAt = strpos($gate, 'odata_release_session_lock()');
if ($emailAt === false || $releaseAt === false || $releaseAt < $emailAt) {
    fail('sessielock moet vrij na de logincheck, vóór het lezen van de cache');
}
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
$_SESSION['moneta_access_probe'] = 1;
odata_release_session_lock();
if (session_status() === PHP_SESSION_ACTIVE) {
    fail('actieve sessie blijft gelockt');
}
assert_contains($odataSource, 'session_write_close()', 'sessie dicht na de check');

assert_contains($odataSource, 'php_sapi_name()', 'cli-check');
assert_contains($odataSource, "=== 'cli'", 'cli-vergelijking');
assert_contains($odataSource, 'login/lib.php', 'login-lib');
assert_not_contains($odataSource, 'is_trusted_requester', 'geen localhost-bypass');
assert_not_contains($odataSource, 'odata_cache_extract_attributes_from_payload', 'geen voorbeeldrij-extractor');
assert_not_contains($odataSource, 'entry.attributes', 'widget toont geen voorbeeldrij');

$nightlySource = (string) file_get_contents($root . '/web/nightly.php');
$runnerAt = strpos($nightlySource, 'odata_runner_request_authorized()');
$loginAt = strpos($nightlySource, "require_once __DIR__ . '/logincheck.php'");
if ($runnerAt === false || $loginAt === false || $runnerAt > $loginAt) {
    fail('nightly moet CLI of runner-geheim vóór logincheck toestaan');
}

$allowed = [
    'analyst@kvt.nl',
    'planner@kvt.nl' => [15, 40],
];
assert_same(true, odata_email_is_allowed('Analyst@kvt.nl', $allowed), 'lijst-gebruiker');
assert_same(true, odata_email_is_allowed('planner@kvt.nl', $allowed), 'map-gebruiker');
assert_same(false, odata_email_is_allowed('andere@kvt.nl', $allowed), 'niet op de lijst');
assert_same(false, odata_email_is_allowed('', $allowed), 'lege sessie');
assert_same(false, odata_email_is_allowed('analyst@kvt.nl', null), 'geen whitelist');

assert_same(true, odata_direct_action_skips_login('cli', '', ''), 'cli zonder geheim');
assert_same(false, odata_direct_action_skips_login('fpm-fcgi', '', ''), 'web anoniem');
assert_same(false, odata_direct_action_skips_login('fpm-fcgi', '', 'server-secret'), 'web zonder header');
assert_same(false, odata_direct_action_skips_login('fpm-fcgi', 'server-secret', ''), 'leeg servergeheim');
assert_same(false, odata_direct_action_skips_login('fpm-fcgi', 'nope', 'server-secret'), 'fout geheim');
assert_same(true, odata_direct_action_skips_login('cgi-fcgi', 'server-secret', 'server-secret'), 'bestaand servergeheim');
assert_same(false, odata_direct_action_skips_login('apache2handler', 'server-secret', 'ander-geheim'), 'geheim komt niet overeen');
assert_same(true, odata_runner_request_authorized(), 'dit testproces is CLI');

$savedHeader = $_SERVER['HTTP_X_MONETA_SECRET'] ?? null;
$savedGetSecret = $_GET['secret'] ?? null;
$_SERVER['HTTP_X_MONETA_SECRET'] = '  uit-de-header  ';
$_GET['secret'] = 'uit-de-query';
assert_same('uit-de-header', odata_presented_runner_secret(), 'header, geen query');
if ($savedHeader === null) {
    unset($_SERVER['HTTP_X_MONETA_SECRET']);
} else {
    $_SERVER['HTTP_X_MONETA_SECRET'] = $savedHeader;
}
if ($savedGetSecret === null) {
    unset($_GET['secret']);
} else {
    $_GET['secret'] = $savedGetSecret;
}

$authPath = sys_get_temp_dir() . '/moneta-auth-runner-secret.php';
file_put_contents($authPath, "<?php\n\$monetaRunnerSecret = \"auth-php-geheim\";\n\$allowedUsers = ['nacht@kvt.nl'];\n");
$previousAuthPath = $GLOBALS['JUNA_MONETA_AUTH_PHP_PATH'] ?? null;
$previousRunner = $GLOBALS['monetaRunnerSecret'] ?? null;
unset($GLOBALS['monetaRunnerSecret']);
$previousEnv = getenv('MONETA_RUNNER_SECRET');
putenv('MONETA_RUNNER_SECRET');
$GLOBALS['JUNA_MONETA_AUTH_PHP_PATH'] = $authPath;
$fromFile = odata_read_auth_file_vars();
assert_same('auth-php-geheim', odata_configured_runner_secret($fromFile), 'geheim uit auth.php');
assert_same(true, odata_email_is_allowed('nacht@kvt.nl', $fromFile['allowedUsers'] ?? null), 'whitelist uit auth.php');
putenv('MONETA_RUNNER_SECRET=env-geheim');
assert_same('auth-php-geheim', odata_configured_runner_secret($fromFile), 'auth.php wint van een lege global');
$GLOBALS['monetaRunnerSecret'] = 'global-geheim';
assert_same('global-geheim', odata_configured_runner_secret($fromFile), 'global wint');
unset($GLOBALS['monetaRunnerSecret']);
putenv('MONETA_RUNNER_SECRET');
if ($previousAuthPath === null) {
    unset($GLOBALS['JUNA_MONETA_AUTH_PHP_PATH']);
} else {
    $GLOBALS['JUNA_MONETA_AUTH_PHP_PATH'] = $previousAuthPath;
}
if ($previousRunner !== null) {
    $GLOBALS['monetaRunnerSecret'] = $previousRunner;
}
if ($previousEnv !== false && $previousEnv !== '') {
    putenv('MONETA_RUNNER_SECRET=' . $previousEnv);
}
@unlink($authPath);

$cacheDir = cache_base_dir();
$fixtureName = 'moneta-access-test-' . bin2hex(random_bytes(8)) . '.json';
$fixturePath = $cacheDir . '/' . $fixtureName;
$accountNo = '8000-SECRET';
$accountName = 'Rekeningschema omzet geheim';
$balance = '987654.32';
$sourceUrl = "https://bc.example:7148/Production/ODataV4/Company('KVT')/Rekeningschema";
$fixture = [
    '_meta' => [
        'cached_at' => time() - 60,
        'expires_at' => time() + 86400,
        'source_url' => $sourceUrl,
        'fetched' => true,
    ],
    'data' => [[
        'No' => $accountNo,
        'Name' => $accountName,
        'Balance' => $balance,
        '@odata.etag' => 'W/"example"',
    ]],
];
file_put_contents($fixturePath, json_encode($fixture, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

try {
    $payload = odata_cache_status_payload();
    $encoded = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($encoded)) {
        fail('cache_status payload is geen JSON');
    }
    assert_not_contains($encoded, $accountNo, 'cache_status');
    assert_not_contains($encoded, $accountName, 'cache_status');
    assert_not_contains($encoded, $balance, 'cache_status');
    assert_not_contains($encoded, 'attributes', 'cache_status');
    assert_not_contains($encoded, 'Rekeningschema omzet', 'cache_status');

    $match = null;
    foreach ($payload['entries'] as $entry) {
        if (($entry['id'] ?? '') === $fixtureName) {
            $match = $entry;
            break;
        }
    }
    if (!is_array($match)) {
        fail('fixture ontbreekt in cache_status');
    }
    if (array_key_exists('attributes', $match) || array_key_exists('data', $match)) {
        fail('cache_status-entry bevat rijdata');
    }
    assert_same($sourceUrl, $match['url'] ?? '', 'bron-url voor ingelogde widget');
    if ((int) ($match['size_bytes'] ?? 0) <= 0) {
        fail('cache_status mist bestandsgrootte');
    }
} finally {
    @unlink($fixturePath);
}

$widget = injectTimerHtml([
    'statusUrl' => 'odata.php?action=cache_status',
]);
assert_not_contains($widget, 'attributes', 'widget-html');
assert_contains($widget, 'odata.php?action=cache_status', 'widget houdt status-url');

$protectedDirs = [
    $root . '/web/data',
    $root . '/web/data/user_prefs',
    $root . '/web/cache',
    $root . '/web/cache/odata',
];
foreach ($protectedDirs as $dir) {
    $htaccess = $dir . '/.htaccess';
    $rules = (string) file_get_contents($htaccess);
    assert_contains($rules, 'Options -Indexes', $htaccess);
    assert_contains($rules, 'Require all denied', $htaccess);
    assert_contains($rules, 'Deny from all', $htaccess);
    assert_contains($rules, '<IfModule mod_authz_core.c>', $htaccess);
    assert_contains($rules, '<IfModule !mod_authz_core.c>', $htaccess);
    foreach (['index.html', 'index.php'] as $indexName) {
        $indexFile = $dir . '/' . $indexName;
        if (!is_file($indexFile)) {
            fail('ontbrekend indexbestand ' . $indexFile);
        }
        $indexBody = (string) file_get_contents($indexFile);
        if (strlen($indexBody) > 16) {
            fail('indexbestand is niet leeg: ' . $indexFile);
        }
        assert_not_contains($indexBody, 'Rekeningschema', $indexFile);
    }
}

$workflow = (string) file_get_contents($root . '/.github/workflows/deploy-ftp.yml');
assert_not_contains($workflow, '--delete-excluded', 'ftp-deploy verwijdert uitgesloten data niet');
$includeDataAt = strpos($workflow, '--include-glob data/.htaccess');
$includeCacheAt = strpos($workflow, '--include-glob cache/.htaccess');
$excludeDataAt = strpos($workflow, '--exclude-glob data/**');
$excludePrefsAt = strpos($workflow, '--exclude-glob data/user_prefs/**');
$excludeCacheAt = strpos($workflow, '--exclude-glob cache/**');
$excludeOdataAt = strpos($workflow, '--exclude-glob cache/odata/**');
if ($includeDataAt === false || $includeCacheAt === false || $excludeDataAt === false || $excludePrefsAt === false || $excludeCacheAt === false || $excludeOdataAt === false) {
    fail('ftp-deploy mist data- of cache-patronen');
}
if ($includeDataAt < $excludeDataAt || $includeDataAt < $excludePrefsAt) {
    fail('data/.htaccess moet ná de data-excludes staan, anders uploadt lftp hem niet');
}
if ($includeCacheAt < $excludeCacheAt || $includeCacheAt < $excludeOdataAt) {
    fail('cache/.htaccess moet ná de cache-excludes staan, anders uploadt lftp hem niet');
}
foreach ([
    '--include-glob data/index.html',
    '--include-glob data/index.php',
    '--include-glob data/user_prefs/.htaccess',
    '--include-glob data/user_prefs/index.html',
    '--include-glob data/user_prefs/index.php',
    '--include-glob cache/index.html',
    '--include-glob cache/index.php',
    '--include-glob cache/odata/.htaccess',
    '--include-glob cache/odata/index.html',
    '--include-glob cache/odata/index.php',
    '--exclude-glob data/*.sqlite',
    '--exclude-glob data/*.sqlite-*',
    '--exclude-glob auth.php',
    '--exclude-glob .htaccess',
] as $needle) {
    assert_contains($workflow, $needle, 'ftp-deploy');
}

$mirrorPos = strpos($workflow, 'mirror -R --delete');
$mirrorEnd = strpos($workflow, './web .');
if ($mirrorPos === false || $mirrorEnd === false || $mirrorEnd < $mirrorPos) {
    fail('mirror-blok ontbreekt');
}
$mirrorBlock = substr($workflow, $mirrorPos, $mirrorEnd - $mirrorPos);
preg_match_all('/--(include-glob|exclude-glob)\s+(\S+)/', $mirrorBlock, $globMatches, PREG_SET_ORDER);
if ($globMatches === []) {
    fail('geen mirror-globs');
}

$lftp = trim((string) shell_exec('command -v lftp 2>/dev/null'));
if ($lftp !== '') {
    $base = sys_get_temp_dir() . '/moneta-lftp-' . bin2hex(random_bytes(4));
    $src = $base . '/src';
    $dst = $base . '/dst';
    foreach ([
        $src . '/web/data/user_prefs',
        $src . '/web/cache/odata',
        $dst . '/data/user_prefs',
        $dst . '/cache/odata',
    ] as $dir) {
        if (!mkdir($dir, 0777, true) && !is_dir($dir)) {
            fail('kan tempmap niet aanmaken: ' . $dir);
        }
    }
    $sqliteBody = 'sqlite-mag-niet-veranderen-' . bin2hex(random_bytes(4));
    $prefsBody = '{"company":"geheim"}';
    $cacheBody = '{"rows":"niet wissen"}';
    $rootHtaccess = "Require valid-user\n";
    $authBody = "<?php \$auth = 'niet-overschrijven';\n";
    file_put_contents($dst . '/data/moneta.sqlite', $sqliteBody);
    file_put_contents($dst . '/data/moneta.sqlite-wal', 'wal');
    file_put_contents($dst . '/data/user_prefs/user.json', $prefsBody);
    file_put_contents($dst . '/cache/odata/abc.json', $cacheBody);
    file_put_contents($dst . '/cache/.cleanup_marker', 'lock');
    file_put_contents($dst . '/.htaccess', $rootHtaccess);
    file_put_contents($dst . '/auth.php', $authBody);
    foreach ([
        'data/.htaccess',
        'data/index.html',
        'data/index.php',
        'data/user_prefs/.htaccess',
        'data/user_prefs/index.html',
        'data/user_prefs/index.php',
        'cache/.htaccess',
        'cache/index.html',
        'cache/index.php',
        'cache/odata/.htaccess',
        'cache/odata/index.html',
        'cache/odata/index.php',
    ] as $relative) {
        $from = $root . '/web/' . $relative;
        $to = $src . '/web/' . $relative;
        if (!copy($from, $to)) {
            fail('kopie mislukt: ' . $relative);
        }
    }
    file_put_contents($src . '/web/index.php', "<?php echo 'ui';\n");

    $args = '';
    foreach ($globMatches as $globMatch) {
        $args .= ' --' . $globMatch[1] . ' ' . escapeshellarg($globMatch[2]);
    }
    $script = 'set cmd:fail-exit true; open file://' . $dst . '; lcd ' . escapeshellarg($src)
        . '; mirror -R -v --delete' . $args . ' ./web .; bye';
    $descriptor = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $process = proc_open([$lftp, '-c', $script], $descriptor, $pipes, $root);
    if (!is_resource($process)) {
        fail('lftp startte niet');
    }
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);
    $stdout = is_string($stdout) ? $stdout : '';
    $stderr = is_string($stderr) ? $stderr : '';
    if ($exitCode !== 0) {
        fail('lftp exit ' . $exitCode . ' ' . $stderr . ' ' . $stdout);
    }
    assert_same($sqliteBody, (string) file_get_contents($dst . '/data/moneta.sqlite'), 'sqlite blijft staan');
    assert_same('wal', (string) file_get_contents($dst . '/data/moneta.sqlite-wal'), 'sqlite-wal blijft staan');
    assert_same($prefsBody, (string) file_get_contents($dst . '/data/user_prefs/user.json'), 'user_prefs blijft staan');
    assert_same($cacheBody, (string) file_get_contents($dst . '/cache/odata/abc.json'), 'cache-json blijft staan');
    assert_same('lock', (string) file_get_contents($dst . '/cache/.cleanup_marker'), 'cache-lock blijft staan');
    assert_same($rootHtaccess, (string) file_get_contents($dst . '/.htaccess'), 'root-.htaccess blijft staan');
    assert_same($authBody, (string) file_get_contents($dst . '/auth.php'), 'auth.php blijft staan');
    foreach ([
        'data/.htaccess',
        'data/index.html',
        'data/index.php',
        'data/user_prefs/.htaccess',
        'cache/.htaccess',
        'cache/odata/.htaccess',
    ] as $relative) {
        $uploaded = (string) file_get_contents($dst . '/' . $relative);
        $expected = (string) file_get_contents($root . '/web/' . $relative);
        assert_same($expected, $uploaded, 'upload ' . $relative);
    }
    assert_contains($stdout, 'data/.htaccess', 'lftp noemt data/.htaccess');
    assert_not_contains($stdout, 'moneta.sqlite', 'lftp raakt sqlite niet aan');
    $cleanup = static function (string $dir) use (&$cleanup): void {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            if (is_dir($path)) {
                $cleanup($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    };
    $cleanup($base);
}

$phpCgi = trim((string) shell_exec('command -v php-cgi 2>/dev/null'));
if ($phpCgi !== '') {
    $script = sys_get_temp_dir() . '/moneta-odata-cgi-' . bin2hex(random_bytes(4)) . '.php';
    $odataFile = $root . '/web/odata.php';
    file_put_contents($script, "<?php\n"
        . '$_SERVER["SCRIPT_FILENAME"] = ' . var_export($odataFile, true) . ";\n"
        . '$_SERVER["SCRIPT_NAME"] = "/moneta/odata.php";' . "\n"
        . '$_SERVER["PHP_SELF"] = "/moneta/odata.php";' . "\n"
        . '$_SERVER["REMOTE_ADDR"] = "203.0.113.50";' . "\n"
        . '$_SERVER["SERVER_ADDR"] = "198.51.100.10";' . "\n"
        . '$_GET["action"] = "cache_status";' . "\n"
        . 'unset($_SERVER["HTTP_X_MONETA_SECRET"]);' . "\n"
        . 'require ' . var_export($odataFile, true) . ";\n");
    $descriptor = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $process = proc_open([$phpCgi, '-q', '-d', 'display_errors=0', $script], $descriptor, $pipes, $root);
    if (!is_resource($process)) {
        @unlink($script);
        fail('php-cgi startte niet');
    }
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);
    @unlink($script);
    $stdout = is_string($stdout) ? $stdout : '';
    $stderr = is_string($stderr) ? $stderr : '';
    if ($exitCode !== 0 && strpos($stdout, 'Geen toegang') === false) {
        fail('anonieme cgi-actie exit ' . $exitCode . ' ' . $stderr . ' ' . $stdout);
    }
    assert_contains($stdout, 'Geen toegang', 'anonieme cgi-actie');
    assert_not_contains($stdout, $accountNo, 'anonieme cgi-actie');
    assert_not_contains($stdout, $accountName, 'anonieme cgi-actie');
    assert_not_contains($stdout, 'Rekeningschema', 'anonieme cgi-actie');
}

echo "OK odata action access\n";

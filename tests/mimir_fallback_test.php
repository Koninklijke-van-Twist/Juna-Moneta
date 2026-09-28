<?php
/**
 * Simuleert een onbereikbare Mímir en controleert de directe BC-fallback.
 * Run: php tests/mimir_fallback_test.php
 */

$logFile = sys_get_temp_dir() . '/juna-moneta-mimir-fallback-test.log';
@unlink($logFile);
ini_set('error_log', $logFile);
ini_set('log_errors', '1');

$mimirApi = 'mimir_test_key_should_not_leak';
$mimirBase = 'http://127.0.0.1:9';
$baseUrl = 'https://bc.example:7148/';
$environment = 'Production';
$auth = ['mode' => 'basic', 'user' => 'bcuser', 'pass' => 'bc-secret'];
$auth_list = ['Production' => $auth];

$calls = [];
$GLOBALS['JUNA_MONETA_ODATA_BC_FETCH'] = static function (string $url, array $auth, int $ttl) use (&$calls): array {
    $calls[] = [
        'url' => $url,
        'user' => (string) ($auth['user'] ?? ''),
        'ttl' => $ttl,
    ];
    if (strpos($url, '/Stale/') !== false) {
        throw new Exception('HTTP 404 from OData: stale environment');
    }
    if (preg_match('#/ODataV4/Company\\(#', $url) === 1) {
        return [['No' => 'WO-1']];
    }
    if (preg_match('#/ODataV4/Compan#i', $url) === 1) {
        return [
            ['Name' => 'KVT Gas'],
            ['Name' => 'Hunter van Twist'],
            ['Name' => 'Koninklijke van Twist'],
        ];
    }
    return [['No' => 'WO-1']];
};

require dirname(__DIR__) . '/web/odata.php';
require dirname(__DIR__) . '/web/project_data.php';

function fail(string $message): void
{
    fwrite(STDERR, "FAIL: $message\n");
    exit(1);
}

function fallback_log(): string
{
    global $logFile;
    $raw = @file_get_contents($logFile);
    return is_string($raw) ? $raw : '';
}

function fallback_count(): int
{
    return substr_count(fallback_log(), '[Juna-Moneta] Mímir failed, falling back to direct OData:');
}

if (odata_mimir_connect_timeout_seconds() !== 10) {
    fail('connect-timeout moet 10s zijn');
}
if (odata_mimir_timeout_seconds_for_sapi('cli') !== 600) {
    fail('CLI-timeout moet 600s blijven');
}
if (odata_mimir_timeout_seconds_for_sapi('fpm-fcgi') !== 90 || odata_mimir_timeout_seconds_for_sapi('apache2handler') !== 90) {
    fail('web-timeout moet ongeveer 90s zijn');
}
if (PHP_SAPI === 'cli' && odata_mimir_timeout_seconds() !== 600) {
    fail('huidige CLI-sapi moet de lange timeout gebruiken');
}

$names = odata_mimir_list_companies(null);
$expectedNames = ['Hunter van Twist', 'Koninklijke van Twist', 'KVT Gas'];
if ($names !== $expectedNames) {
    fail('company-fallback gaf ' . json_encode($names) . ' i.p.v. de gesorteerde BC-namen');
}
if (!odata_mimir_circuit_open()) {
    fail('circuit moet open na de eerste Mímir-fout');
}
if (count($calls) !== 1 || strpos($calls[0]['url'], 'https://bc.example:7148/Production/ODataV4/Company') !== 0) {
    fail('company-fallback riep de directe BC-fetch niet aan: ' . json_encode($calls));
}
if ($calls[0]['user'] !== 'bcuser') {
    fail('company-fallback gebruikte niet de BC-credentials');
}

$mimirBase = 'http://192.0.2.1:9';
$started = microtime(true);
$rows = odata_get_all(
    "https://mimir.invalid/Production/ODataV4/Company('Koninklijke%20van%20Twist')/AppWerkorders?\$select=No",
    $auth,
    120
);
$elapsed = microtime(true) - $started;
if ($elapsed >= 2.0) {
    fail('circuit breaker sloeg Mímir niet over (' . round($elapsed, 3) . 's)');
}
if (($rows[0]['No'] ?? '') !== 'WO-1') {
    fail('entity-fallback gaf niet de gestubde BC-rijen terug');
}
$entityCall = $calls[1] ?? null;
$expectedEntityUrl = "https://bc.example:7148/Production/ODataV4/Company('Koninklijke%20van%20Twist')/AppWerkorders?\$select=No";
if (!is_array($entityCall) || $entityCall['url'] !== $expectedEntityUrl || $entityCall['user'] !== 'bcuser' || $entityCall['ttl'] !== 120) {
    fail('entity-fallback URL/auth/ttl klopt niet: ' . json_encode($entityCall));
}
if (fallback_count() !== 1) {
    fail('alleen de eerste Mímir-fout mag gelogd worden, log=' . fallback_log());
}
$log = fallback_log();
if (strpos($log, 'mimir_test_key_should_not_leak') !== false || strpos($log, 'bc-secret') !== false) {
    fail('log bevat een geheim');
}
if (strpos($log, '[Juna-Moneta] Mímir failed, falling back to direct OData:') === false) {
    fail('logregel mist het verwachte prefix');
}

odata_mimir_circuit_reset();
$mimirBase = 'http://127.0.0.1:9';
$beforeQuery = count($calls);
$queryRows = odata_mimir_query('KVT Gas', 'AppResource', ['$select' => 'No,Name'], 60);
if (($queryRows[0]['No'] ?? '') !== 'WO-1') {
    fail('odata_mimir_query viel niet terug op de stub');
}
$queryCall = $calls[$beforeQuery] ?? null;
if (!is_array($queryCall) || strpos($queryCall['url'], "https://bc.example:7148/Production/ODataV4/Company('KVT%20Gas')/AppResource?") !== 0) {
    fail('query-fallback bouwde niet de pre-Mímir BC-URL: ' . json_encode($queryCall));
}

odata_mimir_circuit_reset();
$beforeFetch = count($calls);
$fetchRows = odata_mimir_fetch_all(
    "https://mimir.invalid/Production/ODataV4/Company('KVT%20Gas')/AppWerkorders?\$select=No",
    15
);
if (($fetchRows[0]['No'] ?? '') !== 'WO-1') {
    fail('odata_mimir_fetch_all viel niet terug');
}
$fetchCall = $calls[$beforeFetch] ?? null;
if (!is_array($fetchCall) || $fetchCall['url'] !== "https://bc.example:7148/Production/ODataV4/Company('KVT%20Gas')/AppWerkorders?\$select=No") {
    fail('fetch_all-fallback herschreef de URL niet: ' . json_encode($fetchCall));
}

odata_mimir_circuit_reset();
$map = odata_mimir_company_environment_map(null);
if (($map['Hunter van Twist'] ?? '') !== 'Production' || ($map['KVT Gas'] ?? '') !== 'Production') {
    fail('environment-map viel niet terug op BC: ' . json_encode($map));
}

odata_mimir_circuit_reset();
$mimirBase = 'http://127.0.0.1:9';
$GLOBALS['demeter_company_environment_map'] = null;
$GLOBALS['demeter_companies_by_environment'] = null;
$GLOBALS['demeter_active_environments'] = null;
$discovered = auth_discover_companies_across_active_environments(30);
$discoveredNames = is_array($discovered['companies'] ?? null) ? $discovered['companies'] : [];
if ($discoveredNames !== $expectedNames) {
    fail('company-discovery-fallback gaf ' . json_encode($discoveredNames));
}
if (($discovered['map']['KVT Gas'] ?? '') !== 'Production') {
    fail('company-discovery-fallback zette de environment-map niet: ' . json_encode($discovered['map'] ?? null));
}

odata_mimir_circuit_reset();
$GLOBALS['demeter_company_environment_map'] = null;
$beforeProject = count($calls);
$startedProject = microtime(true);
$projectRows = project_fetch_rows('KVT Gas', 'Rekeningschema', ['$select' => 'No,Name'], 60);
$projectElapsed = microtime(true) - $startedProject;
if (($projectRows[0]['No'] ?? '') !== 'WO-1') {
    fail('project_fetch_rows viel niet terug op de stub');
}
$projectCall = null;
for ($i = $beforeProject; $i < count($calls); $i++) {
    if (strpos($calls[$i]['url'], '/Rekeningschema?') !== false) {
        $projectCall = $calls[$i];
    }
}
$expectedProjectUrl = "https://bc.example:7148/Production/ODataV4/Company('KVT%20Gas')/Rekeningschema?%24select=No%2CName";
if (!is_array($projectCall) || $projectCall['url'] !== $expectedProjectUrl || $projectCall['user'] !== 'bcuser' || $projectCall['ttl'] !== 60) {
    fail('project_fetch_rows-fallback gebruikte niet het pre-Mímir pad: ' . json_encode($projectCall));
}
if (!odata_mimir_circuit_open()) {
    fail('project_fetch_rows moet het circuit openen');
}
$beforeSecond = count($calls);
$loggedBeforeSecond = fallback_count();
$startedSecond = microtime(true);
$secondRows = project_fetch_rows('Hunter van Twist', 'Rekeningschema', ['$select' => 'No'], 60);
$secondElapsed = microtime(true) - $startedSecond;
if ($secondElapsed >= 2.0 || ($secondRows[0]['No'] ?? '') !== 'WO-1') {
    fail('tweede project_fetch_rows sloeg Mímir niet over (' . round($secondElapsed, 3) . 's)');
}
if (count($calls) <= $beforeSecond) {
    fail('tweede project_fetch_rows deed geen directe BC-fetch');
}
if (fallback_count() !== $loggedBeforeSecond) {
    fail('na het openen van het circuit mag niet opnieuw gelogd worden');
}
if ($projectElapsed >= 2.0 && $secondElapsed >= 2.0) {
    fail('fallback bleef op Mímir wachten');
}

$sandboxAuth = ['mode' => 'basic', 'user' => 'sandbox-user', 'pass' => 'sandbox-secret'];
$spaceAuth = ['mode' => 'basic', 'user' => 'space-user', 'pass' => 'space-secret'];
$auth_list = [
    'Production' => $auth,
    'Sandbox' => $sandboxAuth,
    'Sandbox Two' => $spaceAuth,
];
$GLOBALS['demeter_company_environment_map'] = [
    'Other Co' => 'Sandbox',
    'KVT Gas' => 'Production',
];
odata_mimir_circuit_reset();
$mimirBase = 'http://127.0.0.1:9';
$beforeOther = count($calls);
$loggedBeforeOther = fallback_count();
$otherRows = odata_mimir_query('Other Co', 'Rekeningschema', ['$select' => 'No'], 10);
$otherCall = $calls[$beforeOther] ?? null;
$expectedOtherPrefix = "https://bc.example:7148/Sandbox/ODataV4/Company('Other%20Co')/Rekeningschema?";
if (($otherRows[0]['No'] ?? '') !== 'WO-1' || !is_array($otherCall) || strpos((string) ($otherCall['url'] ?? ''), $expectedOtherPrefix) !== 0 || ($otherCall['user'] ?? '') !== 'sandbox-user') {
    fail('query gebruikte niet het environment van het bedrijf: ' . json_encode($otherCall));
}
if (fallback_count() !== $loggedBeforeOther + 1) {
    fail('de eerste Mímir-fout moet precies één keer gelogd worden, log=' . fallback_log());
}
$loggedAfterOther = fallback_count();
$secondEnvRows = odata_get_all(
    "https://mimir.invalid/Sandbox/ODataV4/Company('Other%20Co')/AppWerkorders?\$select=No",
    $auth,
    12
);
$secondEnvCall = $calls[count($calls) - 1] ?? null;
$expectedSandboxUrl = "https://bc.example:7148/Sandbox/ODataV4/Company('Other%20Co')/AppWerkorders?\$select=No";
if (($secondEnvRows[0]['No'] ?? '') !== 'WO-1' || !is_array($secondEnvCall) || ($secondEnvCall['url'] ?? '') !== $expectedSandboxUrl || ($secondEnvCall['user'] ?? '') !== 'sandbox-user') {
    fail('URL-herschrijving hield het primaire environment: ' . json_encode($secondEnvCall));
}
if (fallback_count() !== $loggedAfterOther) {
    fail('open circuit mag niet opnieuw loggen');
}

odata_mimir_circuit_reset();
$beforeSpace = count($calls);
$spaceRows = odata_get_all(
    "https://mimir.invalid/Sandbox%20Two/ODataV4/Company('KVT%20Gas')/AppWerkorders?\$select=No",
    $auth,
    8
);
$spaceCall = $calls[$beforeSpace] ?? null;
$expectedSpaceUrl = "https://bc.example:7148/Sandbox%20Two/ODataV4/Company('KVT%20Gas')/AppWerkorders?\$select=No";
if (($spaceRows[0]['No'] ?? '') !== 'WO-1' || !is_array($spaceCall) || ($spaceCall['url'] ?? '') !== $expectedSpaceUrl || ($spaceCall['user'] ?? '') !== 'space-user') {
    fail('env-segment werd dubbel geëncodeerd of kreeg de verkeerde auth: ' . json_encode($spaceCall));
}

odata_mimir_circuit_reset();
$mappedRows = odata_get_all(
    "https://mimir.invalid/mimir/ODataV4/Company('Other%20Co')/AppWerkorders?\$select=No",
    $auth,
    9
);
$mappedCall = $calls[count($calls) - 1] ?? null;
$expectedMappedUrl = "https://bc.example:7148/Sandbox/ODataV4/Company('Other%20Co')/AppWerkorders?\$select=No";
if (($mappedRows[0]['No'] ?? '') !== 'WO-1' || !is_array($mappedCall) || ($mappedCall['url'] ?? '') !== $expectedMappedUrl || ($mappedCall['user'] ?? '') !== 'sandbox-user') {
    fail('mimir-segment negeerde de company-map: ' . json_encode($mappedCall));
}

odata_mimir_circuit_reset();
$beforeSandboxCompanies = count($calls);
odata_mimir_list_companies('Sandbox');
$sandboxCompanyCall = $calls[$beforeSandboxCompanies] ?? null;
if (!is_array($sandboxCompanyCall) || strpos((string) ($sandboxCompanyCall['url'] ?? ''), 'https://bc.example:7148/Sandbox/ODataV4/Company') !== 0 || ($sandboxCompanyCall['user'] ?? '') !== 'sandbox-user') {
    fail('company-lijst gebruikte niet het gevraagde environment: ' . json_encode($sandboxCompanyCall));
}
if (count($calls) !== $beforeSandboxCompanies + 1) {
    fail('company-lijst voor één environment deed meerdere fetches');
}

$environment = 'mimir';
$cacheKey = build_cache_key('https://bc.example:7148/Sandbox/ODataV4/Company', $sandboxAuth);
$cacheParts = explode('|', $cacheKey);
$cacheEnv = (string) ($cacheParts[count($cacheParts) - 1] ?? '');
if ($cacheEnv !== 'Sandbox') {
    fail('cache-key gebruikt geen echt BC-environment: ' . $cacheKey);
}
$environment = 'Production';

odata_mimir_circuit_reset();
$loggedBeforeLogic = fallback_count();
$callsBeforeLogic = count($calls);
$logicError = null;
try {
    odata_mimir_or_direct(
        static function (): array {
            throw new RuntimeException('Bedrijfsnaam-overlap tussen environments');
        },
        static function (): array {
            throw new RuntimeException('directe fallback mocht niet starten');
        }
    );
} catch (RuntimeException $logicCaught) {
    $logicError = $logicCaught;
}
if (!$logicError instanceof RuntimeException || strpos($logicError->getMessage(), 'Bedrijfsnaam-overlap') === false) {
    fail('niet-Mímir-fout werd opgeslokt: ' . ($logicError instanceof Throwable ? $logicError->getMessage() : 'geen'));
}
if (odata_mimir_circuit_open() || fallback_count() !== $loggedBeforeLogic || count($calls) !== $callsBeforeLogic) {
    fail('een fout van de caller opende het circuit of viel terug op BC');
}

odata_mimir_circuit_reset();
$loggedBeforeTranslate = fallback_count();
$beforeTranslate = count($calls);
$encodedCompanyUrl = 'https://mimir.invalid/Production/ODataV4/Company(%27KVT%20Gas%27)/AppWerkorders?$select=No';
$translatedRows = odata_get_all($encodedCompanyUrl, $auth, 11);
$translateCall = $calls[$beforeTranslate] ?? null;
$expectedTranslateUrl = 'https://bc.example:7148/Production/ODataV4/Company(%27KVT%20Gas%27)/AppWerkorders?$select=No';
if (($translatedRows[0]['No'] ?? '') !== 'WO-1' || !is_array($translateCall) || ($translateCall['url'] ?? '') !== $expectedTranslateUrl || ($translateCall['user'] ?? '') !== 'bcuser') {
    fail('onvertaalbare OData-URL viel niet terug op directe BC: ' . json_encode($translateCall));
}
if (odata_mimir_circuit_open() || fallback_count() !== $loggedBeforeTranslate) {
    fail('een vertaalfout mag het circuit niet openen en niet als Mímir-storing gelogd worden');
}

$savedAuthList = $auth_list;
$auth_list = [
    'Stale' => ['mode' => 'basic', 'user' => 'stale-user', 'pass' => 'stale-secret'],
    'Production' => $auth,
];
$partialRows = odata_direct_companies_as_rows(null);
$partialNames = [];
foreach ($partialRows as $partialRow) {
    $partialNames[] = (string) ($partialRow['Name'] ?? '');
}
if (!in_array('KVT Gas', $partialNames, true) || ($partialRows[0]['environment'] ?? '') === 'Stale') {
    fail('een falend environment blokkeerde de company-lijst: ' . json_encode($partialRows));
}
$staleExplicit = null;
try {
    odata_direct_companies_as_rows('Stale');
    fail('een expliciet falend environment moet de fout doorgeven');
} catch (Throwable $staleExplicit) {
}
if (!$staleExplicit instanceof Throwable || strpos($staleExplicit->getMessage(), '404') === false) {
    fail('expliciet environment gaf niet de environment-fout door');
}
$auth_list = [
    'Stale' => ['mode' => 'basic', 'user' => 'stale-user', 'pass' => 'stale-secret'],
];
$staleAll = null;
try {
    odata_direct_companies_as_rows(null);
    fail('als elk environment faalt moet de fout terugkomen');
} catch (Throwable $staleAll) {
}
if (!$staleAll instanceof Throwable || strpos($staleAll->getMessage(), '404') === false) {
    fail('lege company-lijst verborg de environment-fout: ' . ($staleAll instanceof Throwable ? $staleAll->getMessage() : 'geen'));
}
$auth_list = $savedAuthList;

$authFile = sys_get_temp_dir() . '/juna-moneta-auth-fallback.php';
file_put_contents($authFile, <<<'PHP'
<?php
$baseUrl = 'https://from-auth.example:7148/';
$base = 'https://from-auth.example:7148/';
$environment = 'Sandbox';
$auth = ['mode' => 'basic', 'user' => 'file-user', 'pass' => 'file-secret'];
$auth_list = [
    'Sandbox' => $auth,
];
PHP);
$GLOBALS['JUNA_MONETA_AUTH_PHP_PATH'] = $authFile;
if (isset($GLOBALS['JUNA_MONETA_AUTH_PHP_INCLUDED']) && is_array($GLOBALS['JUNA_MONETA_AUTH_PHP_INCLUDED'])) {
    unset($GLOBALS['JUNA_MONETA_AUTH_PHP_INCLUDED'][$authFile]);
}
$baseUrl = 'https://mimir.invalid/';
$environment = 'mimir';
$auth = [];
$auth_list = [];
$GLOBALS['base'] = '';
odata_ensure_bc_config_loaded();
if (($GLOBALS['baseUrl'] ?? '') !== 'https://from-auth.example:7148/') {
    fail('lazy auth.php zette baseUrl niet in $GLOBALS: ' . json_encode($GLOBALS['baseUrl'] ?? null));
}
if (($GLOBALS['environment'] ?? '') !== 'Sandbox' || ($GLOBALS['auth']['user'] ?? '') !== 'file-user') {
    fail('lazy auth.php zette environment/auth niet in $GLOBALS');
}
if (($GLOBALS['auth_list']['Sandbox']['user'] ?? '') !== 'file-user' || ($GLOBALS['base'] ?? '') !== 'https://from-auth.example:7148/') {
    fail('lazy auth.php zette auth_list/base niet in $GLOBALS');
}
$baseUrl = 'https://keep.example:7148/';
$environment = 'mimir';
$auth = [];
$auth_list = [];
if (isset($GLOBALS['JUNA_MONETA_AUTH_PHP_INCLUDED']) && is_array($GLOBALS['JUNA_MONETA_AUTH_PHP_INCLUDED'])) {
    unset($GLOBALS['JUNA_MONETA_AUTH_PHP_INCLUDED'][$authFile]);
}
odata_ensure_bc_config_loaded();
if (($GLOBALS['baseUrl'] ?? '') !== 'https://keep.example:7148/') {
    fail('een gezette baseUrl werd overschreven door auth.php');
}
if (($GLOBALS['environment'] ?? '') !== 'Sandbox') {
    fail('de no-overwrite-check laadde auth.php niet opnieuw');
}
unset($GLOBALS['JUNA_MONETA_AUTH_PHP_PATH']);
@unlink($authFile);
if (strpos(fallback_log(), 'sandbox-secret') !== false || strpos(fallback_log(), 'space-secret') !== false || strpos(fallback_log(), 'file-secret') !== false) {
    fail('log bevat een geheim uit de tweede environment of auth.php');
}

$loggedBeforeRethrow = fallback_count();
$callsBeforeRethrow = count($calls);
odata_mimir_circuit_reset();
$mimirBase = 'http://127.0.0.1:9';
$baseUrl = 'https://mimir.invalid/';
$environment = 'mimir';
$auth = [];
$auth_list = [];
$rethrown = null;
try {
    odata_get_all('https://mimir.invalid/mimir/ODataV4/Company(\'X\')/AppWerkorders', ['mode' => 'basic', 'user' => '', 'pass' => ''], 30);
    fail('zonder BC-credentials moet de oorspronkelijke Mímir-fout terugkomen');
} catch (Throwable $exception) {
    $rethrown = $exception;
}
if (!$rethrown instanceof Throwable || strpos($rethrown->getMessage(), 'Mímir') === false) {
    fail('hergooide fout is niet de Mímir-fout: ' . ($rethrown instanceof Throwable ? $rethrown->getMessage() : 'geen exception'));
}
if (stripos($rethrown->getMessage(), 'credential') !== false) {
    fail('hergooide fout maskeert Mímir met een credentials-melding: ' . $rethrown->getMessage());
}
if (count($calls) !== $callsBeforeRethrow) {
    fail('zonder BC-credentials mag de directe fetch niet starten');
}
if (fallback_count() !== $loggedBeforeRethrow) {
    fail('zonder BC-credentials mag er geen fallback gelogd worden');
}

odata_mimir_circuit_reset();
$mimirApi = '';
$mimirBase = 'http://127.0.0.1:9';
$baseUrl = 'https://bc.example:7148/';
$environment = 'Production';
$auth = ['mode' => 'basic', 'user' => 'bcuser', 'pass' => 'bc-secret'];
$auth_list = ['Production' => $auth];
$loggedBeforeDirect = fallback_count();
$directOnlyUrl = 'https://mimir.invalid/Production/ODataV4/Company(\'KVT%20Gas\')/AppWerkorders?$select=No';
$directRows = odata_get_all($directOnlyUrl, $auth, 45);
if (odata_mimir_circuit_open()) {
    fail('lege $mimirApi mag Mímir niet proberen');
}
if (fallback_count() !== $loggedBeforeDirect) {
    fail('lege $mimirApi mag geen Mímir-fallback loggen');
}
$directCall = $calls[count($calls) - 1] ?? null;
if (($directRows[0]['No'] ?? '') !== 'WO-1' || !is_array($directCall) || $directCall['url'] !== $directOnlyUrl) {
    fail('lege $mimirApi moet de oude directe route ongewijzigd gebruiken: ' . json_encode($directCall));
}

echo "OK\n";

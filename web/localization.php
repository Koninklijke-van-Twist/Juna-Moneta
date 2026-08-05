<?php

/**
 * Constants
 */

const FLAG_SVGS = [
    'nl' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 900 600"><rect width="900" height="600" fill="#AE1C28"/><rect width="900" height="400" fill="#fff"/><rect width="900" height="200" fill="#fff"/><rect width="900" height="200" y="0" fill="#AE1C28"/><rect width="900" height="200" y="200" fill="#fff"/><rect width="900" height="200" y="400" fill="#21468B"/></svg>',
    'en' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 60 40"><clipPath id="a"><path d="M0 0v40h60V0z"/></clipPath><clipPath id="b"><path d="M30 20h30v20zv20H0zH0V0zV0h30z"/></clipPath><g clip-path="url(#a)"><path d="M0 0v40h60V0z" fill="#012169"/><path d="M0 0l60 40m0-40L0 40" stroke="#fff" stroke-width="8"/><path d="M0 0l60 40m0-40L0 40" clip-path="url(#b)" stroke="#C8102E" stroke-width="5"/><path d="M30 0v40M0 20h60" stroke="#fff" stroke-width="13"/><path d="M30 0v40M0 20h60" stroke="#C8102E" stroke-width="8"/></g></svg>',
    'de' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 5 3"><rect width="5" height="3" y="0" fill="#000"/><rect width="5" height="2" y="1" fill="#D00"/><rect width="5" height="1" y="2" fill="#FFCE00"/></svg>',
    'fr' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 900 600"><rect width="900" height="600" fill="#ED2939"/><rect width="600" height="600" fill="#fff"/><rect width="300" height="600" fill="#002395"/></svg>',
];

const SUPPORTED_LANGUAGES = [
    'nl' => ['flag' => '🇳🇱', 'label' => 'Nederlands'],
    'en' => ['flag' => '🇬🇧', 'label' => 'English'],
    'de' => ['flag' => '🇩🇪', 'label' => 'Deutsch'],
    'fr' => ['flag' => '🇫🇷', 'label' => 'Français'],
];

const LOCALE_BY_LANG = [
    'nl' => 'nl-NL',
    'en' => 'en-GB',
    'de' => 'de-DE',
    'fr' => 'fr-FR',
];

const TRANSLATIONS = [
    'nl' => [
        'lang.menu_aria' => 'Taal kiezen',
        'lang.switch_to' => 'Schakel naar %s',
        'app.title' => 'Moneta',
        'moneta.hero.title' => 'Financieel overzicht',
        'moneta.hero.subtitle' => 'Dagelijkse Rekeningschema-saldi uit de nachtjob, weergegeven als grafieken.',
        'moneta.label.company' => 'Bedrijf',
        'moneta.label.date_from' => 'Historie van',
        'moneta.label.date_to' => 'Historie tot',
        'moneta.label.forecast_from' => 'Prognose van',
        'moneta.label.forecast_to' => 'Prognose tot',
        'moneta.section.history' => 'Saldohistorie',
        'moneta.section.forecast' => 'Liquiditeitsprognose',
        'moneta.btn.apply' => 'Toepassen',
        'moneta.btn.edit_groups' => 'Groepen bewerken',
        'moneta.chart.gl_title' => 'Saldi per groep',
        'moneta.chart.gl_subtitle' => 'Som van Rekeningschema-rekeningen per groep over de gekozen periode.',
        'moneta.chart.forecast_title' => 'Prognose saldi',
        'moneta.chart.forecast_subtitle' => 'Start vanaf groepssaldi: + geplande termijnfacturen, − basislijnkosten. Toewijzing via ProjectPosten (proportioneel per grootboekrekening).',
        'moneta.chart.forecast_link_note' => 'ProjectPosten Type=grootboekrekening: gewicht = aantal hits / totaal. Grootboek → grafiekgroep; ontbrekende mapping → niet toegewezen.',
        'moneta.chart.forecast_meta' => '%d termijnen / %d kostengroepen in periode (%d niet toegewezen).',
        'moneta.empty.groups' => 'Nog geen groepen. Klik op “Groepen bewerken” om rekeningen uit het Rekeningschema te groeperen.',
        'moneta.empty.gl' => 'Nog geen Rekeningschema-saldi in de cache voor deze groepen. Draai nightly.php of de backfill.',
        'moneta.empty.forecast' => 'Nog geen prognosegegevens. Draai nightly.php om open projecten en termijnfacturen te cachen.',
        'moneta.groups.title' => 'Grafiekgroepen',
        'moneta.groups.subtitle' => 'Wijzigingen worden automatisch opgeslagen. Elke groep wordt één lijn in de grafiek.',
        'moneta.groups.add' => 'Groep toevoegen',
        'moneta.groups.close' => 'Sluiten',
        'moneta.groups.default_name' => 'Nieuwe groep',
        'moneta.groups.remove' => 'Groep verwijderen',
        'moneta.groups.add_account' => 'Rekening toevoegen',
        'moneta.groups.remove_account' => 'Rekening verwijderen',
        'moneta.groups.saving' => 'Opslaan…',
        'moneta.groups.saved' => 'Opgeslagen',
        'moneta.groups.save_failed' => 'Opslaan mislukt',
        'moneta.groups.refreshing' => 'Grafiek verversen…',
        'moneta.groups.reload_hint' => 'Vernieuw de pagina om de grafiek bij te werken',
        'moneta.picker.title' => 'Rekening kiezen',
        'moneta.picker.subtitle' => 'Kies een rekening uit het Rekeningschema.',
        'moneta.picker.search' => 'Zoeken op nummer of naam…',
        'moneta.picker.empty' => 'Geen rekeningen gevonden. Draai eerst nightly.php zodat het schema is opgehaald.',
        'moneta.error.load_failed' => 'Gegevens ophalen mislukt. Probeer het later opnieuw.',
        'moneta.loader.wait' => 'Even geduld...',
    ],

    'en' => [
        'lang.menu_aria' => 'Choose language',
        'lang.switch_to' => 'Switch to %s',
        'app.title' => 'Moneta',
        'moneta.hero.title' => 'Financial overview',
        'moneta.hero.subtitle' => 'Daily chart of accounts balances from the night job, shown as charts.',
        'moneta.label.company' => 'Company',
        'moneta.label.date_from' => 'History from',
        'moneta.label.date_to' => 'History to',
        'moneta.label.forecast_from' => 'Forecast from',
        'moneta.label.forecast_to' => 'Forecast to',
        'moneta.section.history' => 'Balance history',
        'moneta.section.forecast' => 'Cash forecast',
        'moneta.btn.apply' => 'Apply',
        'moneta.btn.edit_groups' => 'Edit groups',
        'moneta.chart.gl_title' => 'Balances by group',
        'moneta.chart.gl_subtitle' => 'Sum of chart of accounts lines per group over the selected period.',
        'moneta.chart.forecast_title' => 'Balance forecast',
        'moneta.chart.forecast_subtitle' => 'Starts from group balances: + planned installments, − baseline costs. Allocation via ProjectPosten (proportional by G/L account).',
        'moneta.chart.forecast_link_note' => 'ProjectPosten Type=G/L Account: weight = hit count / total. G/L → chart group; missing mapping → unassigned.',
        'moneta.chart.forecast_meta' => '%d installments / %d cost groups in period (%d unassigned).',
        'moneta.empty.groups' => 'No groups yet. Click “Edit groups” to group accounts from the chart of accounts.',
        'moneta.empty.gl' => 'No chart of accounts balances in cache for these groups yet. Run nightly.php or the backfill.',
        'moneta.empty.forecast' => 'No forecast data yet. Run nightly.php to cache open projects and installments.',
        'moneta.groups.title' => 'Chart groups',
        'moneta.groups.subtitle' => 'Changes are saved automatically. Each group becomes one line in the chart.',
        'moneta.groups.add' => 'Add group',
        'moneta.groups.close' => 'Close',
        'moneta.groups.default_name' => 'New group',
        'moneta.groups.remove' => 'Remove group',
        'moneta.groups.add_account' => 'Add account',
        'moneta.groups.remove_account' => 'Remove account',
        'moneta.groups.saving' => 'Saving…',
        'moneta.groups.saved' => 'Saved',
        'moneta.groups.save_failed' => 'Save failed',
        'moneta.groups.refreshing' => 'Refreshing chart…',
        'moneta.groups.reload_hint' => 'Reload the page to refresh the chart',
        'moneta.picker.title' => 'Choose account',
        'moneta.picker.subtitle' => 'Pick an account from the chart of accounts (Rekeningschema).',
        'moneta.picker.search' => 'Search by number or name…',
        'moneta.picker.empty' => 'No accounts found. Run nightly.php first so the chart of accounts is cached.',
        'moneta.error.load_failed' => 'Failed to load data. Please try again later.',
        'moneta.loader.wait' => 'Please wait...',
    ],

    'de' => [
        'lang.menu_aria' => 'Sprache wählen',
        'lang.switch_to' => 'Wechseln zu %s',
        'app.title' => 'Moneta',
        'moneta.hero.title' => 'Finanzübersicht',
        'moneta.hero.subtitle' => 'Tägliche Kontenplansalden aus dem Nachtjob, dargestellt als Diagramme.',
        'moneta.label.company' => 'Unternehmen',
        'moneta.label.date_from' => 'Historie von',
        'moneta.label.date_to' => 'Historie bis',
        'moneta.label.forecast_from' => 'Prognose von',
        'moneta.label.forecast_to' => 'Prognose bis',
        'moneta.section.history' => 'Saldenhistorie',
        'moneta.section.forecast' => 'Liquiditätsprognose',
        'moneta.btn.apply' => 'Anwenden',
        'moneta.btn.edit_groups' => 'Gruppen bearbeiten',
        'moneta.chart.gl_title' => 'Salden nach Gruppe',
        'moneta.chart.gl_subtitle' => 'Summe der Kontenplanzeilen je Gruppe über den gewählten Zeitraum.',
        'moneta.chart.forecast_title' => 'Saldo-Prognose',
        'moneta.chart.forecast_subtitle' => 'Startet bei Gruppensalden: + geplante Raten, − Baseline-Kosten. Zuordnung über ProjectPosten (anteilig je G/L-Konto).',
        'moneta.chart.forecast_link_note' => 'ProjectPosten Type=Grootboekrekening: Gewicht = Treffer / Gesamt. G/L → Diagrammgruppe; fehlende Zuordnung → nicht zugewiesen.',
        'moneta.chart.forecast_meta' => '%d Raten / %d Kostengruppen im Zeitraum (%d nicht zugewiesen).',
        'moneta.empty.groups' => 'Noch keine Gruppen. Klicken Sie auf „Gruppen bearbeiten“, um Konten zu gruppieren.',
        'moneta.empty.gl' => 'Noch keine Kontenplansalden im Cache für diese Gruppen. Führen Sie nightly.php oder den Backfill aus.',
        'moneta.empty.forecast' => 'Noch keine Prognosedaten. Führen Sie nightly.php aus, um offene Projekte und Raten zu cachen.',
        'moneta.groups.title' => 'Diagrammgruppen',
        'moneta.groups.subtitle' => 'Änderungen werden automatisch gespeichert. Jede Gruppe wird eine Linie im Diagramm.',
        'moneta.groups.add' => 'Gruppe hinzufügen',
        'moneta.groups.close' => 'Schließen',
        'moneta.groups.default_name' => 'Neue Gruppe',
        'moneta.groups.remove' => 'Gruppe entfernen',
        'moneta.groups.add_account' => 'Konto hinzufügen',
        'moneta.groups.remove_account' => 'Konto entfernen',
        'moneta.groups.saving' => 'Speichern…',
        'moneta.groups.saved' => 'Gespeichert',
        'moneta.groups.save_failed' => 'Speichern fehlgeschlagen',
        'moneta.groups.refreshing' => 'Diagramm wird aktualisiert…',
        'moneta.groups.reload_hint' => 'Seite neu laden, um das Diagramm zu aktualisieren',
        'moneta.picker.title' => 'Konto wählen',
        'moneta.picker.subtitle' => 'Wählen Sie ein Konto aus dem Kontenplan (Rekeningschema).',
        'moneta.picker.search' => 'Nach Nummer oder Name suchen…',
        'moneta.picker.empty' => 'Keine Konten gefunden. Führen Sie zuerst nightly.php aus.',
        'moneta.error.load_failed' => 'Daten konnten nicht geladen werden. Bitte später erneut versuchen.',
        'moneta.loader.wait' => 'Bitte warten...',
    ],

    'fr' => [
        'lang.menu_aria' => 'Choisir la langue',
        'lang.switch_to' => 'Passer en %s',
        'app.title' => 'Moneta',
        'moneta.hero.title' => 'Aperçu financier',
        'moneta.hero.subtitle' => 'Soldes quotidiens du plan comptable issus du job nocturne, affichés en graphiques.',
        'moneta.label.company' => 'Société',
        'moneta.label.date_from' => 'Historique du',
        'moneta.label.date_to' => 'Historique au',
        'moneta.label.forecast_from' => 'Prévision du',
        'moneta.label.forecast_to' => 'Prévision au',
        'moneta.section.history' => 'Historique des soldes',
        'moneta.section.forecast' => 'Prévision de trésorerie',
        'moneta.btn.apply' => 'Appliquer',
        'moneta.btn.edit_groups' => 'Modifier les groupes',
        'moneta.chart.gl_title' => 'Soldes par groupe',
        'moneta.chart.gl_subtitle' => 'Somme des comptes du plan comptable par groupe sur la période choisie.',
        'moneta.chart.forecast_title' => 'Prévision des soldes',
        'moneta.chart.forecast_subtitle' => 'Part des soldes de groupe : + acomptes, − coûts baseline. Affectation via ProjectPosten (proportionnelle par compte G/L).',
        'moneta.chart.forecast_link_note' => 'ProjectPosten Type=compte G/L : poids = occurrences / total. G/L → groupe du graphique ; sans mapping → non attribué.',
        'moneta.chart.forecast_meta' => '%d acomptes / %d groupes de coûts sur la période (%d non attribués).',
        'moneta.empty.groups' => 'Aucun groupe. Cliquez sur « Modifier les groupes » pour regrouper des comptes.',
        'moneta.empty.gl' => 'Pas encore de soldes du plan comptable en cache pour ces groupes. Exécutez nightly.php ou le backfill.',
        'moneta.empty.forecast' => 'Pas encore de prévision. Exécutez nightly.php pour mettre en cache projets ouverts et acomptes.',
        'moneta.groups.title' => 'Groupes du graphique',
        'moneta.groups.subtitle' => 'Les modifications sont enregistrées automatiquement. Chaque groupe devient une ligne.',
        'moneta.groups.add' => 'Ajouter un groupe',
        'moneta.groups.close' => 'Fermer',
        'moneta.groups.default_name' => 'Nouveau groupe',
        'moneta.groups.remove' => 'Supprimer le groupe',
        'moneta.groups.add_account' => 'Ajouter un compte',
        'moneta.groups.remove_account' => 'Retirer le compte',
        'moneta.groups.saving' => 'Enregistrement…',
        'moneta.groups.saved' => 'Enregistré',
        'moneta.groups.save_failed' => 'Échec de l’enregistrement',
        'moneta.groups.refreshing' => 'Actualisation du graphique…',
        'moneta.groups.reload_hint' => 'Rechargez la page pour actualiser le graphique',
        'moneta.picker.title' => 'Choisir un compte',
        'moneta.picker.subtitle' => 'Choisissez un compte du plan comptable (Rekeningschema).',
        'moneta.picker.search' => 'Rechercher par numéro ou nom…',
        'moneta.picker.empty' => 'Aucun compte trouvé. Exécutez d’abord nightly.php.',
        'moneta.error.load_failed' => 'Échec du chargement des données. Réessayez plus tard.',
        'moneta.loader.wait' => 'Veuillez patienter...',
    ],
];
/**
 * Functies
 */

function getUserPrefsPath(string $email): ?string
{
    $email = strtolower(trim($email));
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return null;
    }
    $dir = __DIR__ . '/data/user_prefs';
    $filename = preg_replace('/[^a-z0-9._\-]/', '_', $email) . '.json';
    return $dir . '/' . $filename;
}

function loadUserPrefs(string $email): array
{
    $path = getUserPrefsPath($email);
    if ($path === null || !is_file($path)) {
        return [];
    }
    $data = json_decode(file_get_contents($path), true);
    return is_array($data) ? $data : [];
}

function saveUserPref(string $email, string $key, mixed $value): void
{
    $path = getUserPrefsPath($email);
    if ($path === null) {
        return;
    }
    $dir = dirname($path);
    if (!is_dir($dir)) {
        mkdir($dir, 0750, true);
    }
    $prefs = loadUserPrefs($email);
    $prefs[$key] = $value;
    file_put_contents($path, json_encode($prefs, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
}

function getCurrentLanguage(): string
{
    $lang = (string) ($_SESSION['lang'] ?? 'nl');
    return array_key_exists($lang, SUPPORTED_LANGUAGES) ? $lang : 'nl';
}

function getHtmlLang(): string
{
    return getCurrentLanguage();
}

function getDateLocale(): string
{
    $lang = getCurrentLanguage();
    return LOCALE_BY_LANG[$lang] ?? 'nl-NL';
}

/**
 * Geeft de vertaling voor $key in de actieve taal.
 * Extra $args worden via sprintf ingevoegd (voor %d, %s, etc.).
 */
function LOC(string $key, mixed ...$args): string
{
    $lang = getCurrentLanguage();
    $translations = TRANSLATIONS[$lang] ?? TRANSLATIONS['nl'];
    $string = $translations[$key] ?? (TRANSLATIONS['nl'][$key] ?? $key);

    return $args !== [] ? sprintf($string, ...$args) : $string;
}

function localizationFlagSvg(string $lang): string
{
    $svg = FLAG_SVGS[$lang] ?? '';
    if ($svg === '') {
        return '';
    }

    $safeLang = preg_replace('/[^a-z0-9]/', '', $lang) ?? $lang;
    return str_replace(
        ['id="a"', 'url(#a)', 'id="b"', 'url(#b)'],
        ['id="flag-' . $safeLang . '-a"', 'url(#flag-' . $safeLang . '-a)', 'id="flag-' . $safeLang . '-b"', 'url(#flag-' . $safeLang . '-b)'],
        $svg
    );
}

function localizationUrlWithLang(string $lang): string
{
    $params = $_GET;
    unset($params['lang']);
    $params['lang'] = $lang;
    $path = strtok((string) ($_SERVER['REQUEST_URI'] ?? ''), '?') ?: '';
    $query = http_build_query($params);
    return $path . ($query !== '' ? '?' . $query : '');
}

function localizationJsTranslations(array $keys): string
{
    $payload = [];
    foreach ($keys as $key) {
        $payload[$key] = LOC($key);
    }

    return json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

function renderLanguageSwitcherStyles(): void
{
    echo <<<'CSS'
<style>
.lang-switcher {
    position: fixed;
    top: 12px;
    right: 12px;
    z-index: 5000;
    font-family: inherit;
}
.lang-switcher-toggle {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 42px;
    height: 30px;
    padding: 0;
    border: 1px solid rgba(0, 82, 155, 0.25);
    border-radius: 6px;
    background: #ffffff;
    box-shadow: 0 4px 14px rgba(15, 23, 42, 0.12);
    cursor: pointer;
}
.lang-switcher-toggle:hover {
    background: #f2f9ff;
}
.lang-switcher-toggle svg {
    width: 28px;
    height: auto;
    display: block;
    border-radius: 2px;
    overflow: hidden;
}
.lang-switcher-menu {
    position: absolute;
    top: calc(100% + 6px);
    right: 0;
    min-width: 160px;
    margin: 0;
    padding: 6px;
    list-style: none;
    background: #ffffff;
    border: 1px solid #c9d7eb;
    border-radius: 10px;
    box-shadow: 0 12px 28px rgba(15, 23, 42, 0.18);
    display: none;
}
.lang-switcher.is-open .lang-switcher-menu {
    display: block;
}
.lang-switcher-item a {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 8px 10px;
    border-radius: 8px;
    color: var(--kvt-text, #1f2937);
    text-decoration: none;
    font-size: 14px;
    font-weight: 600;
}
.lang-switcher-item a:hover {
    background: #edf7ff;
}
.lang-switcher-item.is-active a {
    background: #e6f4ff;
}
.lang-switcher-item svg {
    width: 24px;
    height: auto;
    flex-shrink: 0;
    border-radius: 2px;
    overflow: hidden;
}
@media print {
    .lang-switcher {
        display: none !important;
    }
}
</style>
CSS;
}

function renderLanguageSwitcher(): void
{
    $current = getCurrentLanguage();
    $menuAria = htmlspecialchars(LOC('lang.menu_aria'), ENT_QUOTES);

    echo '<div class="lang-switcher" data-lang-switcher>';
    echo '<button type="button" class="lang-switcher-toggle" aria-haspopup="true" aria-expanded="false" aria-label="' . $menuAria . '">';
    echo localizationFlagSvg($current);
    echo '</button>';
    echo '<ul class="lang-switcher-menu" role="menu">';

    foreach (SUPPORTED_LANGUAGES as $code => $meta) {
        if ($code === $current) {
            continue;
        }

        $label = (string) ($meta['label'] ?? $code);
        $href = htmlspecialchars(localizationUrlWithLang($code), ENT_QUOTES);
        $title = htmlspecialchars(LOC('lang.switch_to', $label), ENT_QUOTES);

        echo '<li class="lang-switcher-item" role="none">';
        echo '<a role="menuitem" href="' . $href . '" title="' . $title . '">';
        echo localizationFlagSvg($code);
        echo '<span>' . htmlspecialchars($label) . '</span>';
        echo '</a>';
        echo '</li>';
    }

    echo '</ul>';
    echo '</div>';
}

function renderLanguageSwitcherScript(): void
{
    echo <<<'JS'
<script>
(function () {
    document.querySelectorAll('[data-lang-switcher]').forEach(function (root) {
        var toggle = root.querySelector('.lang-switcher-toggle');
        if (!toggle) {
            return;
        }

        toggle.addEventListener('click', function (event) {
            event.stopPropagation();
            var isOpen = root.classList.toggle('is-open');
            toggle.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
        });

        document.addEventListener('click', function () {
            root.classList.remove('is-open');
            toggle.setAttribute('aria-expanded', 'false');
        });

        root.addEventListener('click', function (event) {
            event.stopPropagation();
        });
    });
})();
</script>
JS;
}

/**
 * Page load
 */

if (session_status() !== PHP_SESSION_ACTIVE) {
    @session_start();
}

if (!isset($_SESSION['lang'])) {
    $prefEmail = strtolower(trim((string) ($_SESSION['user']['email'] ?? '')));
    if ($prefEmail !== '') {
        $savedPrefs = loadUserPrefs($prefEmail);
        if (isset($savedPrefs['lang']) && array_key_exists($savedPrefs['lang'], SUPPORTED_LANGUAGES)) {
            $_SESSION['lang'] = $savedPrefs['lang'];
        }
    }
}

if (!isset($_SESSION['lang']) || !array_key_exists((string) $_SESSION['lang'], SUPPORTED_LANGUAGES)) {
    $_SESSION['lang'] = 'nl';
}

if (isset($_GET['lang']) && array_key_exists($_GET['lang'], SUPPORTED_LANGUAGES)) {
    $requestedLang = (string) $_GET['lang'];
    $langChanged = $requestedLang !== getCurrentLanguage();
    $_SESSION['lang'] = $requestedLang;
    $prefEmail = strtolower(trim((string) ($_SESSION['user']['email'] ?? '')));
    if ($prefEmail !== '' && $langChanged) {
        saveUserPref($prefEmail, 'lang', $requestedLang);
    }

    $isApiAction = isset($_GET['action']) && trim((string) $_GET['action']) !== '';
    if (!$isApiAction && strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'GET') {
        $params = $_GET;
        unset($params['lang']);
        $path = strtok((string) ($_SERVER['REQUEST_URI'] ?? ''), '?') ?: '';
        $query = http_build_query($params);
        header('Location: ' . $path . ($query !== '' ? '?' . $query : ''));
        exit;
    }
}

if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

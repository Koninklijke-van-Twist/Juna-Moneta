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
        'moneta.hero.subtitle' => 'Rekeningschema-saldi en jouw prognose in één overzicht.',
        'moneta.label.company' => 'Bedrijf',
        'moneta.label.date_from' => 'Van',
        'moneta.label.date_to' => 'Tot',
        'moneta.btn.apply' => 'Toepassen',
        'moneta.btn.edit_groups' => 'Groepen bewerken',
        'moneta.btn.edit_derived' => 'Combinatie bewerken',
        'moneta.btn.forecast' => 'Prognose',
        'moneta.btn.add_balance_chart' => 'Grafiek toevoegen',
        'moneta.btn.add_derived_chart' => 'Combinatiegrafiek toevoegen',
        'moneta.btn.delete_chart' => 'Grafiek verwijderen',
        'moneta.chart.balance_subtitle' => 'Som van Rekeningschema-rekeningen per groep. Na vandaag: stippellijn met prognose.',
        'moneta.chart.derived_subtitle' => 'Rekenkundige combinatie van groepen uit andere grafieken.',
        'moneta.chart.today' => 'Vandaag',
        'moneta.empty.groups' => 'Nog geen groepen. Voeg een groep toe en kies rekeningen uit het Rekeningschema.',
        'moneta.empty.gl' => 'Nog geen saldi in de cache voor deze groepen. Draai nightly.php of de backfill.',
        'moneta.empty.derived' => 'Nog geen combinatieregels. Klik op “Combinatie bewerken” om series toe te voegen.',
        'moneta.empty.charts' => 'Nog geen grafieken. Voeg een grafiek of combinatiegrafiek toe.',
        'moneta.groups.title' => 'Groepen bewerken',
        'moneta.groups.subtitle' => 'Wijzigingen worden automatisch opgeslagen. Elke groep wordt één lijn.',
        'moneta.groups.add' => 'Groep toevoegen',
        'moneta.groups.close' => 'Sluiten',
        'moneta.groups.default_name' => 'Nieuwe groep',
        'moneta.groups.remove' => 'Groep verwijderen',
        'moneta.groups.add_account' => 'Rekening toevoegen',
        'moneta.groups.remove_account' => 'Rekening verwijderen',
        'moneta.groups.negate' => 'Negatief',
        'moneta.groups.negate_hint' => 'Keer het teken van deze rekening om in de groepssom (−1).',
        'moneta.groups.saving' => 'Opslaan…',
        'moneta.groups.saved' => 'Opgeslagen',
        'moneta.groups.save_failed' => 'Opslaan mislukt',
        'moneta.groups.refreshing' => 'Grafiek verversen…',
        'moneta.picker.title' => 'Rekening kiezen',
        'moneta.picker.subtitle' => 'Zoek en kies een rekening uit het Rekeningschema.',
        'moneta.picker.search' => 'Zoeken op nummer of naam…',
        'moneta.picker.empty' => 'Geen rekeningen gevonden. Draai eerst nightly.php zodat het schema is opgehaald.',
        'moneta.derived.title' => 'Combinatiegrafiek',
        'moneta.derived.subtitle' => 'Kies twee groepen en een bewerking. Elke regel wordt één lijn.',
        'moneta.derived.add' => 'Serie toevoegen',
        'moneta.derived.default_name' => 'Nieuwe serie',
        'moneta.derived.left' => 'Linker groep',
        'moneta.derived.right' => 'Rechter groep',
        'moneta.derived.operator' => 'Bewerking',
        'moneta.derived.name' => 'Naam',
        'moneta.derived.remove' => 'Serie verwijderen',
        'moneta.forecast.title' => 'Prognose',
        'moneta.forecast.subtitle' => 'Eenmalige kosten en herhalende regels op Rekeningschema-rekeningen. Ze gelden op alle grafieken waarin die rekening in een groep zit.',
        'moneta.forecast.one_time' => 'Eenmalig',
        'moneta.forecast.rules' => 'Herhalend',
        'moneta.forecast.add_one_time' => 'Eenmalige regel toevoegen',
        'moneta.forecast.add_rule' => 'Herhalende regel toevoegen',
        'moneta.forecast.edit' => 'Bewerken',
        'moneta.forecast.edit_title' => 'Prognose bewerken',
        'moneta.forecast.search' => 'Zoeken op naam…',
        'moneta.forecast.filter_account' => 'Filter op rekening',
        'moneta.forecast.all_accounts' => 'Alle rekeningen',
        'moneta.forecast.empty_filtered' => 'Geen prognoses voor deze filter.',
        'moneta.forecast.empty_list' => 'Nog geen prognoseregels.',
        'moneta.forecast.untitled' => 'Naamloos',
        'moneta.forecast.save_item' => 'Opslaan',
        'moneta.forecast.cancel_edit' => 'Terug',
        'moneta.forecast.count' => '%d regels',
        'moneta.forecast.account' => 'Rekening',
        'moneta.forecast.amount' => 'Bedrag',
        'moneta.forecast.name' => 'Naam',
        'moneta.forecast.description' => 'Beschrijving',
        'moneta.forecast.event_date' => 'Datum',
        'moneta.forecast.start_date' => 'Startdatum',
        'moneta.forecast.end_date' => 'Einddatum (optioneel)',
        'moneta.forecast.repeat_n' => 'Elke N',
        'moneta.forecast.repeat_unit' => 'Periode',
        'moneta.forecast.remove' => 'Verwijderen',
        'moneta.forecast.choose_account' => 'Rekening kiezen…',
        'moneta.forecast.unit.day' => 'Dag',
        'moneta.forecast.unit.week' => 'Week',
        'moneta.forecast.unit.month' => 'Maand',
        'moneta.forecast.unit.year' => 'Jaar',
        'moneta.forecast.validation.required' => 'Vul de verplichte velden in (rekening, datum, N ≥ 1).',
        'moneta.forecast.validation.end_before_start' => 'Einddatum moet op of na de startdatum liggen.',
        'moneta.confirm.delete_chart' => 'Deze grafiek verwijderen?',
        'moneta.error.load_failed' => 'Gegevens ophalen mislukt. Probeer het later opnieuw.',
        'moneta.loader.wait' => 'Even geduld...',
    ],

    'en' => [
        'lang.menu_aria' => 'Choose language',
        'lang.switch_to' => 'Switch to %s',
        'app.title' => 'Moneta',
        'moneta.hero.title' => 'Financial overview',
        'moneta.hero.subtitle' => 'Chart of accounts balances and your forecast in one view.',
        'moneta.label.company' => 'Company',
        'moneta.label.date_from' => 'From',
        'moneta.label.date_to' => 'To',
        'moneta.btn.apply' => 'Apply',
        'moneta.btn.edit_groups' => 'Edit groups',
        'moneta.btn.edit_derived' => 'Edit combination',
        'moneta.btn.forecast' => 'Forecast',
        'moneta.btn.add_balance_chart' => 'Add chart',
        'moneta.btn.add_derived_chart' => 'Add combination chart',
        'moneta.btn.delete_chart' => 'Delete chart',
        'moneta.chart.balance_subtitle' => 'Sum of chart of accounts lines per group. After today: dotted line with forecast.',
        'moneta.chart.derived_subtitle' => 'Arithmetic combination of groups from other charts.',
        'moneta.chart.today' => 'Today',
        'moneta.empty.groups' => 'No groups yet. Add a group and pick accounts from the chart of accounts.',
        'moneta.empty.gl' => 'No balances in cache for these groups yet. Run nightly.php or the backfill.',
        'moneta.empty.derived' => 'No combination series yet. Click “Edit combination” to add series.',
        'moneta.empty.charts' => 'No charts yet. Add a chart or combination chart.',
        'moneta.groups.title' => 'Edit groups',
        'moneta.groups.subtitle' => 'Changes are saved automatically. Each group becomes one line.',
        'moneta.groups.add' => 'Add group',
        'moneta.groups.close' => 'Close',
        'moneta.groups.default_name' => 'New group',
        'moneta.groups.remove' => 'Remove group',
        'moneta.groups.add_account' => 'Add account',
        'moneta.groups.remove_account' => 'Remove account',
        'moneta.groups.negate' => 'Negative',
        'moneta.groups.negate_hint' => 'Flip the sign of this account in the group total (−1).',
        'moneta.groups.saving' => 'Saving…',
        'moneta.groups.saved' => 'Saved',
        'moneta.groups.save_failed' => 'Save failed',
        'moneta.groups.refreshing' => 'Refreshing chart…',
        'moneta.picker.title' => 'Choose account',
        'moneta.picker.subtitle' => 'Search and pick an account from the chart of accounts.',
        'moneta.picker.search' => 'Search by number or name…',
        'moneta.picker.empty' => 'No accounts found. Run nightly.php first so the chart of accounts is cached.',
        'moneta.derived.title' => 'Combination chart',
        'moneta.derived.subtitle' => 'Pick two groups and an operator. Each rule becomes one line.',
        'moneta.derived.add' => 'Add series',
        'moneta.derived.default_name' => 'New series',
        'moneta.derived.left' => 'Left group',
        'moneta.derived.right' => 'Right group',
        'moneta.derived.operator' => 'Operator',
        'moneta.derived.name' => 'Name',
        'moneta.derived.remove' => 'Remove series',
        'moneta.forecast.title' => 'Forecast',
        'moneta.forecast.subtitle' => 'One-time costs and recurring rules on chart of accounts lines. They apply to every chart whose groups include that account.',
        'moneta.forecast.one_time' => 'One-time',
        'moneta.forecast.rules' => 'Recurring',
        'moneta.forecast.add_one_time' => 'Add one-time item',
        'moneta.forecast.add_rule' => 'Add recurring rule',
        'moneta.forecast.edit' => 'Edit',
        'moneta.forecast.edit_title' => 'Edit forecast',
        'moneta.forecast.search' => 'Search by name…',
        'moneta.forecast.filter_account' => 'Filter by account',
        'moneta.forecast.all_accounts' => 'All accounts',
        'moneta.forecast.empty_filtered' => 'No forecasts match this filter.',
        'moneta.forecast.empty_list' => 'No forecast rules yet.',
        'moneta.forecast.untitled' => 'Untitled',
        'moneta.forecast.save_item' => 'Save',
        'moneta.forecast.cancel_edit' => 'Back',
        'moneta.forecast.count' => '%d rules',
        'moneta.forecast.account' => 'Account',
        'moneta.forecast.amount' => 'Amount',
        'moneta.forecast.name' => 'Name',
        'moneta.forecast.description' => 'Description',
        'moneta.forecast.event_date' => 'Date',
        'moneta.forecast.start_date' => 'Start date',
        'moneta.forecast.end_date' => 'End date (optional)',
        'moneta.forecast.repeat_n' => 'Every N',
        'moneta.forecast.repeat_unit' => 'Period',
        'moneta.forecast.remove' => 'Remove',
        'moneta.forecast.choose_account' => 'Choose account…',
        'moneta.forecast.unit.day' => 'Day',
        'moneta.forecast.unit.week' => 'Week',
        'moneta.forecast.unit.month' => 'Month',
        'moneta.forecast.unit.year' => 'Year',
        'moneta.forecast.validation.required' => 'Please fill required fields (account, date, N ≥ 1).',
        'moneta.forecast.validation.end_before_start' => 'End date must be on or after the start date.',
        'moneta.confirm.delete_chart' => 'Delete this chart?',
        'moneta.error.load_failed' => 'Failed to load data. Please try again later.',
        'moneta.loader.wait' => 'Please wait...',
    ],

    'de' => [
        'lang.menu_aria' => 'Sprache wählen',
        'lang.switch_to' => 'Wechseln zu %s',
        'app.title' => 'Moneta',
        'moneta.hero.title' => 'Finanzübersicht',
        'moneta.hero.subtitle' => 'Kontenplansalden und Ihre Prognose in einer Ansicht.',
        'moneta.label.company' => 'Unternehmen',
        'moneta.label.date_from' => 'Von',
        'moneta.label.date_to' => 'Bis',
        'moneta.btn.apply' => 'Anwenden',
        'moneta.btn.edit_groups' => 'Gruppen bearbeiten',
        'moneta.btn.edit_derived' => 'Kombination bearbeiten',
        'moneta.btn.forecast' => 'Prognose',
        'moneta.btn.add_balance_chart' => 'Diagramm hinzufügen',
        'moneta.btn.add_derived_chart' => 'Kombinationsdiagramm hinzufügen',
        'moneta.btn.delete_chart' => 'Diagramm löschen',
        'moneta.chart.balance_subtitle' => 'Summe der Kontenplanzeilen je Gruppe. Nach heute: gestrichelte Linie mit Prognose.',
        'moneta.chart.derived_subtitle' => 'Rechnerische Kombination von Gruppen aus anderen Diagrammen.',
        'moneta.chart.today' => 'Heute',
        'moneta.empty.groups' => 'Noch keine Gruppen. Fügen Sie eine Gruppe hinzu und wählen Sie Konten.',
        'moneta.empty.gl' => 'Noch keine Salden im Cache für diese Gruppen. Führen Sie nightly.php oder den Backfill aus.',
        'moneta.empty.derived' => 'Noch keine Kombinationsreihen. Klicken Sie auf „Kombination bearbeiten“.',
        'moneta.empty.charts' => 'Noch keine Diagramme. Fügen Sie ein Diagramm hinzu.',
        'moneta.groups.title' => 'Gruppen bearbeiten',
        'moneta.groups.subtitle' => 'Änderungen werden automatisch gespeichert. Jede Gruppe wird eine Linie.',
        'moneta.groups.add' => 'Gruppe hinzufügen',
        'moneta.groups.close' => 'Schließen',
        'moneta.groups.default_name' => 'Neue Gruppe',
        'moneta.groups.remove' => 'Gruppe entfernen',
        'moneta.groups.add_account' => 'Konto hinzufügen',
        'moneta.groups.remove_account' => 'Konto entfernen',
        'moneta.groups.negate' => 'Negativ',
        'moneta.groups.negate_hint' => 'Vorzeichen dieses Kontos in der Gruppensumme umkehren (−1).',
        'moneta.groups.saving' => 'Speichern…',
        'moneta.groups.saved' => 'Gespeichert',
        'moneta.groups.save_failed' => 'Speichern fehlgeschlagen',
        'moneta.groups.refreshing' => 'Diagramm wird aktualisiert…',
        'moneta.picker.title' => 'Konto wählen',
        'moneta.picker.subtitle' => 'Suchen und wählen Sie ein Konto aus dem Kontenplan.',
        'moneta.picker.search' => 'Nach Nummer oder Name suchen…',
        'moneta.picker.empty' => 'Keine Konten gefunden. Führen Sie zuerst nightly.php aus.',
        'moneta.derived.title' => 'Kombinationsdiagramm',
        'moneta.derived.subtitle' => 'Wählen Sie zwei Gruppen und eine Operation. Jede Regel wird eine Linie.',
        'moneta.derived.add' => 'Serie hinzufügen',
        'moneta.derived.default_name' => 'Neue Serie',
        'moneta.derived.left' => 'Linke Gruppe',
        'moneta.derived.right' => 'Rechte Gruppe',
        'moneta.derived.operator' => 'Operation',
        'moneta.derived.name' => 'Name',
        'moneta.derived.remove' => 'Serie entfernen',
        'moneta.forecast.title' => 'Prognose',
        'moneta.forecast.subtitle' => 'Einmalige Kosten und wiederkehrende Regeln auf Kontenplanzeilen. Sie gelten für alle Diagramme, deren Gruppen das Konto enthalten.',
        'moneta.forecast.one_time' => 'Einmalig',
        'moneta.forecast.rules' => 'Wiederkehrend',
        'moneta.forecast.add_one_time' => 'Einmalige Regel hinzufügen',
        'moneta.forecast.add_rule' => 'Wiederkehrende Regel hinzufügen',
        'moneta.forecast.edit' => 'Bearbeiten',
        'moneta.forecast.edit_title' => 'Prognose bearbeiten',
        'moneta.forecast.search' => 'Nach Name suchen…',
        'moneta.forecast.filter_account' => 'Nach Konto filtern',
        'moneta.forecast.all_accounts' => 'Alle Konten',
        'moneta.forecast.empty_filtered' => 'Keine Prognosen für diesen Filter.',
        'moneta.forecast.empty_list' => 'Noch keine Prognoseregeln.',
        'moneta.forecast.untitled' => 'Ohne Namen',
        'moneta.forecast.save_item' => 'Speichern',
        'moneta.forecast.cancel_edit' => 'Zurück',
        'moneta.forecast.count' => '%d Regeln',
        'moneta.forecast.account' => 'Konto',
        'moneta.forecast.amount' => 'Betrag',
        'moneta.forecast.name' => 'Name',
        'moneta.forecast.description' => 'Beschreibung',
        'moneta.forecast.event_date' => 'Datum',
        'moneta.forecast.start_date' => 'Startdatum',
        'moneta.forecast.end_date' => 'Enddatum (optional)',
        'moneta.forecast.repeat_n' => 'Alle N',
        'moneta.forecast.repeat_unit' => 'Periode',
        'moneta.forecast.remove' => 'Entfernen',
        'moneta.forecast.choose_account' => 'Konto wählen…',
        'moneta.forecast.unit.day' => 'Tag',
        'moneta.forecast.unit.week' => 'Woche',
        'moneta.forecast.unit.month' => 'Monat',
        'moneta.forecast.unit.year' => 'Jahr',
        'moneta.forecast.validation.required' => 'Bitte Pflichtfelder ausfüllen (Konto, Datum, N ≥ 1).',
        'moneta.forecast.validation.end_before_start' => 'Enddatum muss am oder nach dem Startdatum liegen.',
        'moneta.confirm.delete_chart' => 'Dieses Diagramm löschen?',
        'moneta.error.load_failed' => 'Daten konnten nicht geladen werden. Bitte später erneut versuchen.',
        'moneta.loader.wait' => 'Bitte warten...',
    ],

    'fr' => [
        'lang.menu_aria' => 'Choisir la langue',
        'lang.switch_to' => 'Passer en %s',
        'app.title' => 'Moneta',
        'moneta.hero.title' => 'Aperçu financier',
        'moneta.hero.subtitle' => 'Soldes du plan comptable et votre prévision dans une seule vue.',
        'moneta.label.company' => 'Société',
        'moneta.label.date_from' => 'Du',
        'moneta.label.date_to' => 'Au',
        'moneta.btn.apply' => 'Appliquer',
        'moneta.btn.edit_groups' => 'Modifier les groupes',
        'moneta.btn.edit_derived' => 'Modifier la combinaison',
        'moneta.btn.forecast' => 'Prévision',
        'moneta.btn.add_balance_chart' => 'Ajouter un graphique',
        'moneta.btn.add_derived_chart' => 'Ajouter un graphique combiné',
        'moneta.btn.delete_chart' => 'Supprimer le graphique',
        'moneta.chart.balance_subtitle' => 'Somme des comptes par groupe. Après aujourd’hui : ligne pointillée avec prévision.',
        'moneta.chart.derived_subtitle' => 'Combinaison arithmétique de groupes d’autres graphiques.',
        'moneta.chart.today' => 'Aujourd’hui',
        'moneta.empty.groups' => 'Aucun groupe. Ajoutez un groupe et choisissez des comptes.',
        'moneta.empty.gl' => 'Pas encore de soldes en cache pour ces groupes. Exécutez nightly.php ou le backfill.',
        'moneta.empty.derived' => 'Aucune série combinée. Cliquez sur « Modifier la combinaison ».',
        'moneta.empty.charts' => 'Aucun graphique. Ajoutez un graphique ou un graphique combiné.',
        'moneta.groups.title' => 'Modifier les groupes',
        'moneta.groups.subtitle' => 'Les modifications sont enregistrées automatiquement. Chaque groupe devient une ligne.',
        'moneta.groups.add' => 'Ajouter un groupe',
        'moneta.groups.close' => 'Fermer',
        'moneta.groups.default_name' => 'Nouveau groupe',
        'moneta.groups.remove' => 'Supprimer le groupe',
        'moneta.groups.add_account' => 'Ajouter un compte',
        'moneta.groups.remove_account' => 'Retirer le compte',
        'moneta.groups.negate' => 'Négatif',
        'moneta.groups.negate_hint' => 'Inverser le signe de ce compte dans le total du groupe (−1).',
        'moneta.groups.saving' => 'Enregistrement…',
        'moneta.groups.saved' => 'Enregistré',
        'moneta.groups.save_failed' => 'Échec de l’enregistrement',
        'moneta.groups.refreshing' => 'Actualisation du graphique…',
        'moneta.picker.title' => 'Choisir un compte',
        'moneta.picker.subtitle' => 'Recherchez et choisissez un compte du plan comptable.',
        'moneta.picker.search' => 'Rechercher par numéro ou nom…',
        'moneta.picker.empty' => 'Aucun compte trouvé. Exécutez d’abord nightly.php.',
        'moneta.derived.title' => 'Graphique combiné',
        'moneta.derived.subtitle' => 'Choisissez deux groupes et une opération. Chaque règle devient une ligne.',
        'moneta.derived.add' => 'Ajouter une série',
        'moneta.derived.default_name' => 'Nouvelle série',
        'moneta.derived.left' => 'Groupe gauche',
        'moneta.derived.right' => 'Groupe droit',
        'moneta.derived.operator' => 'Opération',
        'moneta.derived.name' => 'Nom',
        'moneta.derived.remove' => 'Supprimer la série',
        'moneta.forecast.title' => 'Prévision',
        'moneta.forecast.subtitle' => 'Coûts ponctuels et règles récurrentes sur le plan comptable. Elles s’appliquent à tous les graphiques dont les groupes contiennent ce compte.',
        'moneta.forecast.one_time' => 'Ponctuel',
        'moneta.forecast.rules' => 'Récurrent',
        'moneta.forecast.add_one_time' => 'Ajouter un élément ponctuel',
        'moneta.forecast.add_rule' => 'Ajouter une règle récurrente',
        'moneta.forecast.edit' => 'Modifier',
        'moneta.forecast.edit_title' => 'Modifier la prévision',
        'moneta.forecast.search' => 'Rechercher par nom…',
        'moneta.forecast.filter_account' => 'Filtrer par compte',
        'moneta.forecast.all_accounts' => 'Tous les comptes',
        'moneta.forecast.empty_filtered' => 'Aucune prévision pour ce filtre.',
        'moneta.forecast.empty_list' => 'Aucune règle de prévision.',
        'moneta.forecast.untitled' => 'Sans nom',
        'moneta.forecast.save_item' => 'Enregistrer',
        'moneta.forecast.cancel_edit' => 'Retour',
        'moneta.forecast.count' => '%d règles',
        'moneta.forecast.account' => 'Compte',
        'moneta.forecast.amount' => 'Montant',
        'moneta.forecast.name' => 'Nom',
        'moneta.forecast.description' => 'Description',
        'moneta.forecast.event_date' => 'Date',
        'moneta.forecast.start_date' => 'Date de début',
        'moneta.forecast.end_date' => 'Date de fin (optionnelle)',
        'moneta.forecast.repeat_n' => 'Tous les N',
        'moneta.forecast.repeat_unit' => 'Période',
        'moneta.forecast.remove' => 'Supprimer',
        'moneta.forecast.choose_account' => 'Choisir un compte…',
        'moneta.forecast.unit.day' => 'Jour',
        'moneta.forecast.unit.week' => 'Semaine',
        'moneta.forecast.unit.month' => 'Mois',
        'moneta.forecast.unit.year' => 'Année',
        'moneta.forecast.validation.required' => 'Veuillez remplir les champs obligatoires (compte, date, N ≥ 1).',
        'moneta.forecast.validation.end_before_start' => 'La date de fin doit être le jour de début ou après.',
        'moneta.confirm.delete_chart' => 'Supprimer ce graphique ?',
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

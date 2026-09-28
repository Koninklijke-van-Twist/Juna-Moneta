# Sancus

Kosten/opbrengsten-overzicht voor contracten via Business Central-projectposten.

## Mímir (optioneel)

Zet in `web/auth.php` (niet in git):

```php
$mimirApi  = 'mimir_…';
// optioneel:
$mimirBase = 'https://sleutels.kvt.nl/mimir/api';
```

Met `$mimirApi` gezet proberen company-discovery en alle OData-fetches (`odata_get_all` / `project_fetch_rows`, inclusief `nightly.php` en andere CLI-jobs) eerst Mímir. Faalt die aanroep (verbinding/timeout, non-2xx, ongeldige JSON of een Mímir-foutpayload), dan haalt Juna-Moneta dezelfde data op via het oude directe Business Central-pad (`$baseUrl`, `$auth` / `$auth_list`, `$environment`, lokale odata-filecache) en slaat Mímir voor de rest van dat PHP-verzoek over. Laat die BC-credentials in `auth.php` naast `$mimirApi` staan; ontbreken ze, dan komt de oorspronkelijke Mímir-fout terug. Zonder `$mimirApi` blijft het bestaande directe BC-pad ongewijzigd.

**max_age-beleid**

| Soort fetch | `max_age` naar Mímir |
| --- | --- |
| `nightly.php` snapshot-build | **14400** (`MONETA_NIGHTLY_MAX_AGE`, 4u) |
| UI / on-demand | **604800** (`MONETA_GL_ODATA_TTL`, 7 dagen) |
| `hourly.php` | niet aanwezig in Juna-Moneta |

Tim moet `$mimirApi` (en optioneel `$mimirBase`) lokaal/op de server zetten, mét de BC-credentials ernaast; `auth.php` wordt niet gecommit. Zie [Mímir Implementatie](https://wiki.kvt.nl/books/mimir/page/implementatie) en `web/auth_TEMPLATE.php`.


# Sancus

Kosten/opbrengsten-overzicht voor contracten via Business Central-projectposten.

## Mímir (optioneel)

Zet in `web/auth.php` (niet in git):

```php
$mimirApi  = 'mimir_…';
// optioneel:
$mimirBase = 'https://sleutels.kvt.nl/mimir/api';
```

Met `$mimirApi` gezet zijn `$auth_list`, `$environment`, `$baseUrl` en `$auth` ongebruikt voor Business Central — company-discovery en alle OData-fetches (`odata_get_all` / `project_fetch_rows`, inclusief `nightly.php`) lopen via Mímir. Zonder `$mimirApi` blijft het bestaande directe BC-pad ongewijzigd.

**max_age-beleid**

| Soort fetch | `max_age` naar Mímir |
| --- | --- |
| `nightly.php` snapshot-build | **14400** (`MONETA_NIGHTLY_MAX_AGE`, 4u) |
| UI / on-demand | **604800** (`MONETA_GL_ODATA_TTL`, 7 dagen) |
| `hourly.php` | niet aanwezig in Juna-Moneta |

Tim moet `$mimirApi` (en optioneel `$mimirBase`) lokaal/op de server zetten; `auth.php` wordt niet gecommit. Zie [Mímir Implementatie](https://wiki.kvt.nl/books/mimir/page/implementatie).


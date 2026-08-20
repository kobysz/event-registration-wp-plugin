# Auto-update, release i CI (Plan 6B) — projekt techniczny

Data: 2026-08-20
Status: zatwierdzony do planowania implementacji
Buduje na: [Plan 2 — CPT + bootstrap](2026-08-18-cpt-rest-admin-design.md) (`Plugin` punkt kompozycji, `plugin_file()`), całości scalonego kodu (event-registration.php, ci.yml)

## 1. Cel i kontekst

Wtyczka jest produktem instalowanym na wielu stronach spoza wp.org. Ostatni plan roadmapy domyka
**dystrybucję**: automatyczne aktualizacje z GitHub Releases, powtarzalny pipeline pakujący instalowalny
zip przy tagu, oraz spójne single-source wersji. Dziś: brak `Update URI`, wersja zduplikowana
(`event-registration.php` nagłówek `0.1.0` ORAZ `Plugin::VERSION`), brak tagów/releasów, `ci.yml`
testuje ale nie buduje artefaktu.

**Decyzje bazowe:** updater **self-rolled** (filtry WP, zero runtime-dependency, testowalny w kontenerze
przez `pre_http_request`), wersja **derywowana z nagłówka** (`get_file_data`, `Plugin::VERSION` znika),
CI: **release job + guard wersji**, pin actions na SHA odłożony.

Rdzeń updatera i derywacja wersji są testowalne. Workflow YAML i budowa zipa to infrastruktura —
weryfikowane realnym tagiem/releasem, nie testami jednostkowymi (świadomie).

## 2. Zakres

### v1 (Plan 6B)
- `Plugin::version()` (derywacja z nagłówka), usunięcie `Plugin::VERSION`, aktualizacja jedynego callera
- `GitHubUpdater` (`src/Update/`) — filtry `pre_set_site_transient_update_plugins` + `plugins_api`, fetch+cache GitHub Releases
- Nagłówek `Update URI` w `event-registration.php`
- `.github/workflows/release.yml` — build zip + GitHub release na tag `v*`, z guardem wersji
- `.distignore` — lista wykluczeń runtime z zipa
- Rejestracja `GitHubUpdater::register` w bootstrapie
- Krótka sekcja „Wydawanie" w `README.md`

### Poza Planem 6B
- `plugin-update-checker` (biblioteka) → wybrano self-rolled (bez runtime-dependency)
- `readme.txt` (WordPress.org) → nie dystrybuujemy przez wp.org; View details bierze changelog z release body
- Pin actions na commit SHA → odłożony (można dodać później)
- `upgrader_source_selection` (naprawa nazwy folderu) → zip buduje poprawny folder `event-registration/`, niepotrzebne
- Rollback/downgrade, kanały beta, delta-updates → YAGNI
- Własny cron sprawdzania aktualizacji → WP woła transient-filtr sam (2×/dzień + ekran wtyczek)

### Świadomie pominięte (YAGNI)
- Zipball źródłowy jako fallback pakietu — wymaga `upgrader_source_selection`; wymagamy assetu `event-registration.zip`
- Podpisywanie/weryfikacja zipa poza HTTPS+GitHub — rdzeniowy `Plugin_Upgrader` wystarcza dla wewnętrznego produktu

## 3. Decyzje projektowe

| # | Decyzja | Wybór | Uzasadnienie |
|---|---------|-------|--------------|
| 1 | Mechanizm update | Self-rolled (filtry WP) | Zero runtime-dependency; testowalny w kontenerze; spójny z house style |
| 2 | Single-source wersji | Derywacja z nagłówka (`get_file_data`) | Eliminuje drift dwóch literałów; nagłówek = jedno źródło prawdy |
| 3 | Pakiet update | Asset `event-registration.zip` z release'u | Poprawna struktura folderu; brak potrzeby `upgrader_source_selection` |
| 4 | Fetch GitHub | `wp_remote_get` + transient 12h + negative-cache | Rate-limit GitHub; UA obowiązkowy; backoff przy błędzie |
| 5 | Guard wersji | Krok w release.yml (nagłówek == tag) | Blokuje release przy rozjeździe wersji |
| 6 | Wykluczenia zipa | `.distignore` + rsync | Zip tylko runtime (src/vendor/build/assets), bez dev/tests |
| 7 | Pin actions SHA | Odłożony | Poza zakresem; `@v4`/`@v2` na razie |

## 4. Architektura

```
src/Plugin.php                       - const VERSION; + version() (get_file_data z nagłówka, cache statyk)
src/Admin/EventConfigAssets.php      Plugin::VERSION → Plugin::version()
src/Update/GitHubUpdater.php         nowy: filtry update + fetch/cache GitHub Releases
event-registration.php               + nagłówek Update URI; rejestracja GitHubUpdater::register
.github/workflows/release.yml        nowy: build zip + release na tag v*, guard wersji
.distignore                          nowy: wykluczenia runtime z zipa
README.md                            + sekcja „Wydawanie"
```

Granice: logika update wyłącznie w `GitHubUpdater` (testowalna, HTTP przez `wp_remote_get`). Wersja
przez `Plugin::version()`. Workflow/zip = infra (review + realny release). Zero zmian w `src/Domain/**`.

## 5. Single-source wersji

`Plugin` już trzyma `self::$plugin_file` (`boot(__FILE__)`) i `plugin_file()`. Zmiany:
- Usuń `public const VERSION = '0.1.0'`.
- Dodaj:
```php
private static string $version = '';

public static function version(): string {
	if ( '' === self::$version ) {
		$data = get_file_data( self::$plugin_file, array( 'Version' => 'Version' ) );
		self::$version = (string) ( $data['Version'] ?? '' );
	}
	return self::$version;
}
```
- `EventConfigAssets.php:53` `Plugin::VERSION` → `Plugin::version()`.

`get_file_data` jest w `wp-includes/functions.php` — dostępne przy `plugins_loaded`. Cache w statyku
(czyta plik raz na request). `TEXT_DOMAIN`/`plugin_file()` bez zmian.

## 6. GitHubUpdater (`src/Update/`)

Repo: `kobysz/event-registration-wp-plugin`. Rejestracja na `admin_init` (aktualizacje dotyczą admina):
```
register(): add_filter('pre_set_site_transient_update_plugins', inject_update)
            add_filter('plugins_api', plugins_api, 10, 3)
```
`basename = plugin_basename( Plugin::plugin_file() )` (`event-registration/event-registration.php`);
`slug = dirname( basename )` (`event-registration`).

### `fetch_latest(): ?array` — jedyne HTTP
```
$cached = get_transient( 'evreg_update_latest' );
if ( false !== $cached ) return '' === $cached ? null : $cached;   // '' = negative cache
$res = wp_remote_get(
	'https://api.github.com/repos/kobysz/event-registration-wp-plugin/releases/latest',
	array( 'timeout'=>10, 'headers'=>array( 'Accept'=>'application/vnd.github+json', 'User-Agent'=>'event-registration-wp' ) )
);
if ( is_wp_error($res) || 200 !== wp_remote_retrieve_response_code($res) ) {
	set_transient( 'evreg_update_latest', '', HOUR_IN_SECONDS );   // backoff
	return null;
}
$data = json_decode( wp_remote_retrieve_body($res), true );
$parsed = array(
	'version'   => ltrim( (string)($data['tag_name'] ?? ''), 'v' ),
	'package'   => self::pick_asset( is_array($data['assets'] ?? null) ? $data['assets'] : array() ),
	'changelog' => (string)($data['body'] ?? ''),
	'url'       => (string)($data['html_url'] ?? ''),
);
set_transient( 'evreg_update_latest', $parsed, 12 * HOUR_IN_SECONDS );
return $parsed;
```
`pick_asset( array $assets ): string` — pierwszy asset z `name === 'event-registration.zip'` →
`browser_download_url`; brak → `''`.

### `inject_update( $transient )`
```
if ( ! is_object($transient) ) return $transient;
$latest = $this->fetch_latest();
if ( null===$latest || ''===$latest['version'] || ''===$latest['package'] ) return $transient;
if ( ! version_compare( $latest['version'], Plugin::version(), '>' ) ) return $transient;
$transient->response[ $this->basename ] = (object) array(
	'slug'=>$this->slug, 'plugin'=>$this->basename,
	'new_version'=>$latest['version'], 'package'=>$latest['package'], 'url'=>$latest['url'],
);
return $transient;
```

### `plugins_api( $result, $action, $args )`
```
if ( 'plugin_information' !== $action || ($args->slug ?? '') !== $this->slug ) return $result;
$latest = $this->fetch_latest();
if ( null === $latest ) return $result;
return (object) array(
	'name'=>'Event Registration', 'slug'=>$this->slug, 'version'=>$latest['version'],
	'download_link'=>$latest['package'],
	'sections'=>array( 'changelog'=>wp_kses_post( wpautop( $latest['changelog'] ) ) ),
);
```

**Bez własnego crona:** WP woła `pre_set_site_transient_update_plugins` przy `wp_update_plugins`
(2×/dzień + ekran wtyczek). Transient 12h koresponduje. Package pobiera i instaluje rdzeniowy
`Plugin_Upgrader`. Z API czytamy tylko pola (żadnego wykonania kodu). UA obowiązkowy (GitHub wymaga).

## 7. Release pipeline + Update URI

### Nagłówek (`event-registration.php`)
Dodaj przy innych polach: `Update URI: https://github.com/kobysz/event-registration-wp-plugin`.
Host ≠ w.org → rdzeń WP nie aktualizuje z wp.org; `GitHubUpdater` rządzi.

### `.github/workflows/release.yml` (trigger `push: tags: ['v*']`, `permissions: contents: write`)
Kroki: checkout → setup-php 8.1 (composer v2) → setup-node 20 → **guard wersji** (nagłówek `Version`
== `${GITHUB_REF_NAME#v}`, fail przy rozjeździe) → `composer install --no-dev --optimize-autoloader
--prefer-dist --no-progress` → `npm ci` → `npm run build` → build zip (rsync drzewa do katalogu
`event-registration/` z `--exclude-from=.distignore`, `zip -rq event-registration.zip event-registration`)
→ `softprops/action-gh-release@v2` z `files: event-registration.zip` + `generate_release_notes: true`.

Guard wersji (krok):
```bash
HEADER=$(grep -oP 'Version:\s*\K[0-9.]+' event-registration.php | head -1)
TAG="${GITHUB_REF_NAME#v}"
test "$HEADER" = "$TAG" || { echo "::error::Wersja nagłówka ($HEADER) != tag ($TAG)"; exit 1; }
```

### `.distignore` (wykluczenia z zipa)
```
.git*
.github
.superpowers
.distignore
node_modules
tests
docs
scripts
*.dist
phpunit*.xml*
.wp-env.json
playwright.config.*
package.json
package-lock.json
composer.json
composer.lock
README.md
```
Zip zawiera runtime: `event-registration.php`, `uninstall.php`, `src/`, `vendor/` (`--no-dev`),
`build/`, `assets/` (w tym `assets/public/`). Wszystko pod `event-registration/` → poprawny slug folderu.

### `README.md` — sekcja „Wydawanie"
Krótko: podbij `Version:` w nagłówku → commit → `git tag vX.Y.Z && git push --tags` → release.yml
zbuduje i opublikuje zip; instalacje z auto-update dostaną go w ≤12h. Bez `readme.txt` (wp.org pominięty).

`ci.yml` bez zmian (guard wersji ma sens tylko przy tagu; PR nie ma tagu). Pin SHA odłożony.

## 8. Bezpieczeństwo

- Fetch przez HTTPS do api.github.com; UA obowiązkowy; timeout 10s; negative-cache przy błędzie/rate-limicie.
- Z odpowiedzi API czytamy WYŁĄCZNIE pola danych (tag/assets/body/url) — żadnego eval/include.
- Instalację pakietu robi rdzeniowy `Plugin_Upgrader` (rozpakowanie/uprawnienia WP).
- `permissions: contents: write` tylko w release.yml (tworzenie release + upload assetu), nie w ci.yml.
- Guard wersji blokuje opublikowanie release'u niespójnego z tagiem (mniej pomyłek dystrybucji).
- Changelog renderowany `wp_kses_post( wpautop(...) )` (View details).

## 9. Testy

### Integracyjne (`tests/Integration/`, kontener) — `GitHubUpdater`
HTTP mockowane filtrem `pre_http_request` zwracającym spreparowaną odpowiedź GitHub Releases API;
`delete_transient('evreg_update_latest')` w setUp dla izolacji.
- **Nowsza wersja:** mock tag `v0.2.0` + asset `event-registration.zip` → `inject_update($transient)`
  ustawia `$transient->response[$basename]` z `new_version='0.2.0'` i `package`=URL assetu.
- **Równa/starsza:** mock tag == `Plugin::version()` → brak wpisu w `response`.
- **Brak assetu:** mock bez `event-registration.zip` → `package=''` → brak wstrzyku.
- **Błąd HTTP / !=200:** mock WP_Error albo 500 → brak wstrzyku + transient `''` (negative cache); kolejne wywołanie nie robi HTTP (asercja przez licznik trafień filtra).
- **Cache:** drugie `inject_update` po sukcesie nie woła HTTP (transient trafiony).
- **`plugins_api`:** `action='plugin_information'`, `args->slug=slug` → obiekt z `version`/`download_link`/`sections.changelog`; inny slug/action → zwraca `$result` bez zmian.
- **`pick_asset`:** wybiera po nazwie, ignoruje inne assety, brak → `''`.
- **`inject_update` na nie-obiekcie** (`false`) → zwraca wejście bez błędu.

### Integracyjne — `Plugin::version()`
- Zwraca wersję z nagłówka głównego pliku (== `Version:` w `event-registration.php`); cache (drugie wywołanie bez ponownego odczytu — trudne do asercji, wystarczy równość wartości).

### Infra (NIE testowane jednostkowo — weryfikacja realnym releasem)
- `release.yml`, `.distignore`, build zip, GitHub release — poza suitą. Weryfikacja: realny `git tag vX.Y.Z && git push --tags` → workflow zielony, zip-asset opublikowany, testowa instalacja aktualizuje.

### Architektura
`DomainPurityTest` zielony (`GitHubUpdater` w `src/Update`, nie Domain). phpcs + phpstan poziom 6 czysto.
Jawnie: sprawdź, czy usunięcie `Plugin::VERSION` nie zostawiło innych callerów (grep) — poza `EventConfigAssets`.

## 10. Kolejność implementacji

1. `Plugin::version()` + usunięcie `VERSION` + update callera — fundament, testowalne.
2. `GitHubUpdater` (fetch/cache/inject/plugins_api/pick_asset) — rdzeń, testowalny przez `pre_http_request`.
3. `Update URI` w nagłówku + rejestracja `GitHubUpdater::register` w bootstrapie.
4. `.github/workflows/release.yml` + `.distignore` + sekcja README — infra (review, nie TDD).

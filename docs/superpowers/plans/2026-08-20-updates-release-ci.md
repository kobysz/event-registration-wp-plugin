# Auto-update, release i CI (Plan 6B) — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Wtyczka aktualizuje się automatycznie z GitHub Releases, ma powtarzalny pipeline pakujący instalowalny zip przy tagu, i single-source wersji z nagłówka.

**Architecture:** Self-rolled `GitHubUpdater` podpina filtry `pre_set_site_transient_update_plugins` + `plugins_api`, pobiera latest release z GitHub (cache 12h, negative-cache), wstrzykuje update gdy tag > `Plugin::version()`. Wersja derywowana z nagłówka (`get_file_data`), `Plugin::VERSION` znika. `release.yml` na tag `v*` buduje zip (wykluczenia z `.distignore`) i publikuje jako asset. Rdzeń updatera testowalny przez `pre_http_request`; workflow to infra weryfikowana realnym releasem.

**Tech Stack:** PHP 8.1, WordPress (update transients, plugins_api, HTTP API), GitHub Actions, PHPUnit (kontener wp-env), PSR-4 `EvReg\`.

**Spec:** [docs/superpowers/specs/2026-08-20-updates-release-ci-design.md](../specs/2026-08-20-updates-release-ci-design.md)

## Global Constraints

- `src/Domain/**` = zero WordPressa. `GitHubUpdater` idzie do `src/Update/`. `DomainPurityTest` zielony.
- Updater czyta z GitHub API WYŁĄCZNIE pola danych (tag/assets/body/url) — żadnego eval/include. Fetch przez `wp_remote_get` z nagłówkiem `User-Agent` (GitHub wymaga), timeout 10s, negative-cache przy błędzie/rate-limicie.
- Wersja: jedno źródło = nagłówek `Version:` w `event-registration.php`, czytane przez `Plugin::version()`. Po usunięciu `Plugin::VERSION` NIE może zostać żaden inny caller (grep).
- Pakiet update = asset `event-registration.zip` z release'u (poprawny folder `event-registration/`). Bez assetu updater nie wstrzykuje (żadnego zipballa źródłowego).
- Pliki PHP poza `src/Domain/`: `defined('ABSPATH')||exit;`. Wszystkie stringi UI przez i18n gdzie dotyczy.
- phpcs (pod `phpcs.xml.dist`) i phpstan (poziom 6) czysto. Katalogi testów wielką literą.
- Zip release zawiera tylko runtime (src/vendor--no-dev/build/assets + event-registration.php + uninstall.php); bez tests/dev/node_modules.

---

### Task 1: Plugin::version() — single-source z nagłówka

**Files:**
- Modify: `src/Plugin.php` (usuń `VERSION`, dodaj `version()`)
- Modify: `src/Admin/EventConfigAssets.php` (caller)
- Test: `tests/Integration/PluginVersionTest.php`

**Interfaces:**
- Produces: `Plugin::version(): string` (wersja z nagłówka głównego pliku, cache w statyku).
- Removes: `Plugin::VERSION`.

- [ ] **Step 1: Test — version() zwraca wersję z nagłówka**

W `tests/Integration/PluginVersionTest.php`:
```php
public function test_version_matches_plugin_header(): void {
	$expected = get_file_data(
		dirname( __DIR__, 2 ) . '/event-registration.php', // ścieżka do głównego pliku wtyczki
		array( 'Version' => 'Version' )
	)['Version'];
	$this->assertNotSame( '', $expected );
	$this->assertSame( $expected, \EvReg\Plugin::version() );
}
```
(Dostosuj ścieżkę do głównego pliku wg layoutu testów — `Plugin::plugin_file()` zwraca ją w runtime; w teście integracyjnym wtyczka jest załadowana, więc `Plugin::plugin_file()` też działa: możesz asertować `Plugin::version()` == `get_file_data(Plugin::plugin_file(), ...)['Version']`.)

- [ ] **Step 2: Uruchom — ma paść**

Run: `node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpunit -c phpunit-integration.xml.dist --filter PluginVersion`
Expected: FAIL (`version()` nie istnieje / `VERSION` jeszcze użyte). RED weryfikuj jawnym try/catch gdy niejasne.

- [ ] **Step 3: Dodaj version(), usuń VERSION**

W `src/Plugin.php`: usuń `public const VERSION = '0.1.0';`. Dodaj pole + metodę:
```php
/**
 * Zderywowana wersja wtyczki (cache).
 *
 * @var string
 */
private static string $version = '';

/**
 * Zwraca wersję wtyczki odczytaną z nagłówka głównego pliku (jedno źródło prawdy).
 */
public static function version(): string {
	if ( '' === self::$version ) {
		$data           = get_file_data( self::$plugin_file, array( 'Version' => 'Version' ) );
		self::$version = (string) ( $data['Version'] ?? '' );
	}
	return self::$version;
}
```
`get_file_data` jest dostępne przy `plugins_loaded`. `TEXT_DOMAIN`/`plugin_file()`/`boot()` bez zmian.

- [ ] **Step 4: Zaktualizuj callera**

W `src/Admin/EventConfigAssets.php:53` zamień `Plugin::VERSION` na `Plugin::version()`. Grep całego `src/` za innymi użyciami `Plugin::VERSION` — jeśli są, zamień wszystkie.

- [ ] **Step 5: Uruchom — ma przejść**

Run: `node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpunit -c phpunit-integration.xml.dist --filter PluginVersion`
Expected: PASS. Dodatkowo `phpstan` — wyłapie pozostały `Plugin::VERSION` jeśli został.

- [ ] **Step 6: Commit**

```bash
git add src/Plugin.php src/Admin/EventConfigAssets.php tests/Integration/PluginVersionTest.php
git commit -m "refactor: derive plugin version from header, drop VERSION constant"
```

---

### Task 2: GitHubUpdater + Update URI + rejestracja

**Files:**
- Create: `src/Update/GitHubUpdater.php`
- Modify: `event-registration.php` (nagłówek `Update URI` + rejestracja `GitHubUpdater::register`)
- Test: `tests/Integration/Update/GitHubUpdaterTest.php`

**Interfaces:**
- Produces: `GitHubUpdater::register(): void`; instancyjne `inject_update($transient)`, `plugins_api($result,$action,$args)`; wewnętrzne `fetch_latest(): ?array`, `pick_asset(array): string`.
- Consumes: `Plugin::version()`, `Plugin::plugin_file()`.

- [ ] **Step 1: Testy — inject/plugins_api/pick_asset przez mock HTTP**

W `tests/Integration/Update/GitHubUpdaterTest.php`. `setUp`: `delete_transient('evreg_update_latest')`. Mock GitHub przez filtr `pre_http_request` zwracający tablicę odpowiedzi WP (`['response'=>['code'=>200],'body'=>json_encode($release)]`). Przypadki:

```php
// Nowsza wersja → wstrzyk
add_filter( 'pre_http_request', $mk_response( array(
	'tag_name' => 'v99.0.0',
	'html_url' => 'https://github.com/kobysz/event-registration-wp-plugin/releases/tag/v99.0.0',
	'body'     => 'Zmiany',
	'assets'   => array( array( 'name' => 'event-registration.zip', 'browser_download_url' => 'https://example.com/e.zip' ) ),
) ), 10, 3 );
$updater  = new GitHubUpdater();
$result   = $updater->inject_update( new \stdClass() );
$basename = plugin_basename( \EvReg\Plugin::plugin_file() );
$this->assertTrue( isset( $result->response[ $basename ] ) );   // PHPUnit 9.6-kompatybilne (bez assertObjectHasProperty z PU10)
$this->assertSame( '99.0.0', $result->response[ $basename ]->new_version );
$this->assertSame( 'https://example.com/e.zip', $result->response[ $basename ]->package );
```
Kolejne przypadki (osobne testy, każdy z `delete_transient` w setUp): równa wersja (`tag_name` = `Plugin::version()`) → brak klucza `$basename`; brak assetu `event-registration.zip` → brak wstrzyku; odpowiedź 500 lub `WP_Error` → brak wstrzyku; `inject_update(false)` → zwraca `false`; `plugins_api($result,'plugin_information',(object)['slug'=>'event-registration'])` → obiekt z `version`/`download_link`/`sections['changelog']`; `plugins_api($x,'other',...)` → zwraca `$x` niezmienione; negative-cache: po odpowiedzi błędnej drugi `inject_update` nie woła HTTP (policz trafienia filtra przez licznik w kluzurze).

- [ ] **Step 2: Uruchom — ma paść**

Run: `node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpunit -c phpunit-integration.xml.dist --filter GitHubUpdater`
Expected: FAIL (klasa nie istnieje).

- [ ] **Step 3: Implementuj GitHubUpdater**

`src/Update/GitHubUpdater.php`:
```php
<?php
declare( strict_types=1 );
namespace EvReg\Update;
use EvReg\Plugin;
defined( 'ABSPATH' ) || exit;

final class GitHubUpdater {

	private const REPO          = 'kobysz/event-registration-wp-plugin';
	private const ASSET_NAME    = 'event-registration.zip';
	private const CACHE_KEY     = 'evreg_update_latest';
	private const CACHE_TTL     = 12 * HOUR_IN_SECONDS;

	private string $basename;
	private string $slug;

	public function __construct() {
		$this->basename = plugin_basename( Plugin::plugin_file() );
		$this->slug     = dirname( $this->basename );
	}

	public static function register(): void {
		$self = new self();
		add_filter( 'pre_set_site_transient_update_plugins', array( $self, 'inject_update' ) );
		add_filter( 'plugins_api', array( $self, 'plugins_api' ), 10, 3 );
	}

	/**
	 * @param mixed $transient
	 * @return mixed
	 */
	public function inject_update( $transient ) {
		if ( ! is_object( $transient ) ) {
			return $transient;
		}
		$latest = $this->fetch_latest();
		if ( null === $latest || '' === $latest['version'] || '' === $latest['package'] ) {
			return $transient;
		}
		if ( ! version_compare( $latest['version'], Plugin::version(), '>' ) ) {
			return $transient;
		}
		if ( ! isset( $transient->response ) || ! is_array( $transient->response ) ) {
			$transient->response = array();
		}
		$transient->response[ $this->basename ] = (object) array(
			'slug'        => $this->slug,
			'plugin'      => $this->basename,
			'new_version' => $latest['version'],
			'package'     => $latest['package'],
			'url'         => $latest['url'],
		);
		return $transient;
	}

	/**
	 * @param mixed  $result
	 * @param string $action
	 * @param mixed  $args
	 * @return mixed
	 */
	public function plugins_api( $result, $action, $args ) {
		if ( 'plugin_information' !== $action || ( is_object( $args ) ? ( $args->slug ?? '' ) : '' ) !== $this->slug ) {
			return $result;
		}
		$latest = $this->fetch_latest();
		if ( null === $latest ) {
			return $result;
		}
		return (object) array(
			'name'          => 'Event Registration',
			'slug'          => $this->slug,
			'version'       => $latest['version'],
			'download_link' => $latest['package'],
			'sections'      => array( 'changelog' => wp_kses_post( wpautop( $latest['changelog'] ) ) ),
		);
	}

	/**
	 * @return array{version:string,package:string,changelog:string,url:string}|null
	 */
	private function fetch_latest(): ?array {
		$cached = get_transient( self::CACHE_KEY );
		if ( false !== $cached ) {
			return '' === $cached ? null : $cached;
		}
		$res = wp_remote_get(
			'https://api.github.com/repos/' . self::REPO . '/releases/latest',
			array(
				'timeout' => 10,
				'headers' => array(
					'Accept'     => 'application/vnd.github+json',
					'User-Agent' => 'event-registration-wp',
				),
			)
		);
		if ( is_wp_error( $res ) || 200 !== wp_remote_retrieve_response_code( $res ) ) {
			set_transient( self::CACHE_KEY, '', HOUR_IN_SECONDS );
			return null;
		}
		$data = json_decode( wp_remote_retrieve_body( $res ), true );
		if ( ! is_array( $data ) ) {
			set_transient( self::CACHE_KEY, '', HOUR_IN_SECONDS );
			return null;
		}
		$parsed = array(
			'version'   => ltrim( (string) ( $data['tag_name'] ?? '' ), 'v' ),
			'package'   => self::pick_asset( is_array( $data['assets'] ?? null ) ? $data['assets'] : array() ),
			'changelog' => (string) ( $data['body'] ?? '' ),
			'url'       => (string) ( $data['html_url'] ?? '' ),
		);
		set_transient( self::CACHE_KEY, $parsed, self::CACHE_TTL );
		return $parsed;
	}

	/**
	 * @param array<int,mixed> $assets
	 */
	private static function pick_asset( array $assets ): string {
		foreach ( $assets as $asset ) {
			if ( is_array( $asset ) && self::ASSET_NAME === ( $asset['name'] ?? '' ) ) {
				return (string) ( $asset['browser_download_url'] ?? '' );
			}
		}
		return '';
	}
}
```

- [ ] **Step 4: Update URI + rejestracja w bootstrapie**

W `event-registration.php`: dodaj do nagłówka (przy innych polach) linię:
```
 * Update URI:        https://github.com/kobysz/event-registration-wp-plugin
```
Dodaj rejestrację przy innych `*::register()` (na `plugins_loaded`):
```php
add_action( 'plugins_loaded', array( \EvReg\Update\GitHubUpdater::class, 'register' ) );
```
(Albo w tym samym miejscu, gdzie rejestrują się inne subsystemy — dopasuj do istniejącego wzorca w pliku.)

- [ ] **Step 5: Uruchom — ma przejść**

Run: `node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpunit -c phpunit-integration.xml.dist --filter GitHubUpdater`
Expected: PASS (wszystkie przypadki). phpstan/phpcs czysto.

- [ ] **Step 6: Commit**

```bash
git add src/Update/GitHubUpdater.php event-registration.php tests/Integration/Update/GitHubUpdaterTest.php
git commit -m "feat: add self-rolled GitHub updater and Update URI header"
```

---

### Task 3: release.yml + .distignore + README (infra)

**Files:**
- Create: `.github/workflows/release.yml`
- Create: `.distignore`
- Modify: `README.md` (sekcja „Wydawanie")

**Uwaga:** to infrastruktura — NIE ma testów jednostkowych. Weryfikacja: poprawność YAML/składni + zgodność z konwencją istniejącego `ci.yml`. Reviewer sprawdza logikę; realny release weryfikuje w praktyce.

- [ ] **Step 1: `.distignore`**

Utwórz `.distignore` (lista wykluczeń runtime z zipa):
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

- [ ] **Step 2: `release.yml`**

`.github/workflows/release.yml`:
```yaml
name: Release

on:
  push:
    tags: ['v*']

permissions:
  contents: write

jobs:
  release:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4

      - uses: shivammathur/setup-php@v2
        with:
          php-version: '8.1'
          tools: composer:v2
          coverage: none

      - uses: actions/setup-node@v4
        with:
          node-version: '20'

      - name: Sprawdź zgodność wersji z tagiem
        run: |
          HEADER=$(grep -oP 'Version:\s*\K[0-9.]+' event-registration.php | head -1)
          TAG="${GITHUB_REF_NAME#v}"
          echo "header=$HEADER tag=$TAG"
          test "$HEADER" = "$TAG" || { echo "::error::Wersja nagłówka ($HEADER) != tag ($TAG)"; exit 1; }

      - run: composer install --no-dev --optimize-autoloader --prefer-dist --no-progress

      - run: npm ci

      - run: npm run build

      - name: Zbuduj zip
        run: |
          STAGE="event-registration"
          rm -rf "$STAGE"
          mkdir "$STAGE"
          rsync -a --exclude-from=.distignore --exclude "$STAGE" ./ "$STAGE/"
          zip -rq event-registration.zip "$STAGE"

      - name: Utwórz release i załącz asset
        uses: softprops/action-gh-release@v2
        with:
          files: event-registration.zip
          generate_release_notes: true
```

- [ ] **Step 3: README — sekcja „Wydawanie"**

Dodaj do `README.md` krótką sekcję:
```markdown
## Wydawanie

1. Podbij `Version:` w nagłówku `event-registration.php` (to jedyne źródło wersji — `Plugin::version()` je czyta).
2. Commit, potem `git tag vX.Y.Z && git push --tags` (tag MUSI == wersja nagłówka, inaczej release-workflow padnie).
3. `release.yml` zbuduje zip (runtime bez plików dev, wg `.distignore`) i opublikuje go jako asset `event-registration.zip` w GitHub Release.
4. Instalacje z aktywnym auto-update dostaną aktualizację w ≤12h (cache), albo od razu po „Sprawdź aktualizacje" na ekranie wtyczek.
```

- [ ] **Step 4: Walidacja składni YAML/pełne suity**

Nie ma unit-testów dla infra. Sprawdź:
- YAML parsuje się (np. `node -e "require('js-yaml')"` niedostępne — zamiast tego wizualna kontrola wcięć, albo `python -c 'import yaml,sys; yaml.safe_load(open(".github/workflows/release.yml"))'` jeśli python w kontenerze/hoście dostępny; jeśli nie — dokładna ręczna kontrola wcięć wg wzorca `ci.yml`).
- Pełne suity (nietknięte tą zmianą, ale potwierdź zielone po całości brancha):
```
node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpunit -c phpunit-integration.xml.dist
node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpunit --testsuite unit
node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpstan analyse --memory-limit=1G
node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpcs
```
Expected: zielone (`.github`/`.distignore`/README poza scanem phpcs/phpstan; PHP suity bez zmian).

- [ ] **Step 5: Commit**

```bash
git add .github/workflows/release.yml .distignore README.md
git commit -m "ci: add tag-triggered release workflow with version guard"
```

---

## Uwagi wykonawcze

- **RED faza:** konsola PHPUnit zniekształca `Error` — weryfikuj jawnym try/catch drukującym `get_class($e)`.
- **Kolejność:** Task 2 zależy od Task 1 (`Plugin::version()`). Task 3 niezależny (infra). Zachowaj 1→3.
- **Mock HTTP:** testy `GitHubUpdater` NIE robią realnych żądań — filtr `pre_http_request` zwraca spreparowaną odpowiedź; `delete_transient('evreg_update_latest')` w `setUp` dla izolacji między przypadkami.
- **Po usunięciu `Plugin::VERSION`:** grep całego `src/` — żaden caller nie może zostać (phpstan złapie).
- **Infra weryfikacja przeglądarkowa/realna** (poza subagentami): `git tag v0.2.0 && git push --tags` → release.yml zielony, asset `event-registration.zip` opublikowany, testowa instalacja WP z niższą wersją widzi aktualizację i instaluje ją; „View details" pokazuje changelog.

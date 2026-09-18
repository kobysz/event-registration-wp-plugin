# Spec: Fundament i18n stringów wtyczki (B3a / B3 Plan 1)

Data: 2026-09-18. Backlog: B3 (`docs/superpowers/backlog.md`), pierwszy z trzech planów.

## Kontekst i cel

B3 (wielojęzyczność) zdekomponowane na 3 plany: **B3a fundament i18n
stringów wtyczki** (ten spec), B3b overlay treści per event + tłumaczony
formularz, B3c język maila/linku + formaty locale. Model treści per event
(B3b, ustalony w brainstormingu): „jeden event, wiele języków" przez overlay;
strony docelowe używają Polylang.

Cel Planu 1: statyczne stringi **PHP** wtyczki (publiczny formularz, maile,
ekrany admina PHP) tłumaczą się wg locale WordPressa. Polylang przełącza locale
per język, więc to automatycznie daje formularz w języku odwiedzającego dla
części statycznej.

## Stan obecny

- Nagłówek `Text Domain: event-registration` zadeklarowany; **brak**
  `Domain Path`, `load_plugin_textdomain`, katalogu `languages/`, plików
  `.pot/.po/.mo`. Zero ładowania tłumaczeń.
- Stringi źródłowe są **po polsku**, owinięte `__()`/`esc_html__()`/`__( , 'event-registration')`
  w PHP. Konwencja WP zakłada źródło angielskie; my mamy źródło polskie —
  **msgid = polski literał**. To działa: tłumaczenie mapuje polski msgid →
  język docelowy; przy locale polskim `__()` zwraca msgid (polski) bez `.mo`.
- Publiczny `assets/public/form.js` NIE zawiera stringów użytkownika (steruje
  tylko widocznością) — więc statyka publicznego formularza jest w 100% w PHP.
- React admin (`assets/admin/**`) używa `__()` z `@wordpress/i18n` (JS) —
  **poza zakresem** tego planu (wymaga `wp_set_script_translations` + JSON).

## Zakres

W zakresie:
- Ładowanie textdomain dla PHP: `Domain Path` + `load_plugin_textdomain` na `init`.
- Katalog `languages/`: `.pot` (źródło), `en_US.po` + `en_US.mo` (tłumaczenie EN
  wszystkich stringów PHP wtyczki).
- Pipeline generacji `.pot`/`.mo` (WP-CLI w kontenerze wp-env), npm scripty, doc.
- Packaging: `languages/` shipowane w zipie.
- Test integracyjny: string tłumaczy się przy locale en_US.

Poza zakresem (świadomie, kolejne plany/YAGNI):
- Tłumaczenia React-admina (JS/JSON, `wp_set_script_translations`) — Plan 1b/później.
- Inne języki niż EN (DE itd.) — dołożenie kolejnego `-<locale>.po/.mo` później.
- Treść per event (etykiety/typy/maile w wielu językach) — to **B3b (Plan 2)**.
- Formaty daty/waluty wg locale — **B3c (Plan 3)**.

## Architektura

### Ładowanie textdomain

- Nagłówek w `event-registration.php`: dodać `Domain Path: /languages`.
- Rejestracja ładowania: mała klasa `EvReg\I18n` (`src/I18n.php`) z metodą
  `register()` podpinającą `load_plugin_textdomain` na `init`:

  ```php
  add_action( 'init', static function (): void {
      load_plugin_textdomain(
          'event-registration',
          false,
          dirname( plugin_basename( Plugin::plugin_file() ) ) . '/languages'
      );
  } );
  ```

  Wołane z bootstrapu wtyczki obok innych `::register()`. `init` (nie
  `plugins_loaded`) zgodnie z zaleceniem WP 6.7+; Polylang ustawia locale przed
  `init`, więc właściwy `.mo` się załaduje.

### Katalog i pliki

```
languages/
  event-registration.pot          # źródło (polskie msgid), generowany
  event-registration-en_US.po     # tłumaczenie EN, ręczne/AI
  event-registration-en_US.mo     # skompilowane z .po
```

- `.pot`: nagłówki standardowe, `Language: `, `X-Domain: event-registration`.
- `-en_US.po`: dla każdego msgid (polski) — msgstr angielski. Puste msgstr =
  fallback do msgid (polski) — więc każdy string MUSI mieć msgstr.
- `.mo`: skompilowany; to on jest ładowany w runtime.

### Pipeline

npm scripty (host woła wrapper wp-env; WP-CLI ma `i18n`):

- `i18n:pot`:
  `node scripts/wp-env.cjs run cli --env-cwd=wp-content/plugins/event-registration -- wp i18n make-pot . languages/event-registration.pot --slug=event-registration --exclude=assets/admin,build,node_modules,tests,vendor,docs,scripts`
  (skan tylko PHP wtyczki; wyklucz JS-admin i katalogi nie-runtime).
- `i18n:mo`:
  `node scripts/wp-env.cjs run cli --env-cwd=wp-content/plugins/event-registration -- wp i18n make-mo languages languages`
  (kompiluje wszystkie `.po` w `languages/` do `.mo`).

Workflow tłumacza: `npm run i18n:pot` → zaktualizuj `-en_US.po` (msgstr) →
`npm run i18n:mo`. Doc w CLAUDE.md (sekcja Komendy) i/lub README.

`make-pot` msgid to polskie literały — to zamierzone (źródło PL). `--exclude`
pomija `assets/admin` (JS-admin poza zakresem) i katalogi nie-runtime.

### Packaging

- `languages/*.pot|.po|.mo` **commitowane** do gita (nie generowane przy
  buildzie zipa — `build-zip.sh` używa `git archive HEAD`).
- Sprawdzić `.distignore`: NIE może wykluczać `languages/`. `.pot`/`.po` można
  zostawić w zipie (małe) lub wykluczyć — **runtime potrzebuje tylko `.mo`**;
  MVP: shipujemy cały `languages/` (prościej, `.po` przydatne do dalszych
  tłumaczeń przez usera). Jeśli `.distignore` ma `*.po`/`*.pot` — dopisać wyjątek
  albo świadomie shipować tylko `.mo`. Decyzja: ship cały `languages/`.

## Testy

- **PHPUnit integration** (`tests/Integration/I18nTest.php` lub podobny):
  1. Załaduj `en_US.mo`: w teście ustaw locale na `en_US` i wymuś przeładowanie
     textdomain (`switch_to_locale('en_US')` albo `unload_textdomain` +
     `load_plugin_textdomain` z jawną ścieżką do `languages/`), potem asercja:
     `__( 'Wyślij zgłoszenie', 'event-registration' )` zwraca angielski
     odpowiednik (np. „Submit registration"). Wybrać 1–2 stabilne, jednoznaczne
     stringi jako kotwice testu.
  2. Po teście przywróć locale (teardown).
- **Sanity nagłówka:** test/asercja że `event-registration.php` ma
  `Domain Path: /languages` (np. `get_plugin_data`/regex) — opcjonalne, MVP może
  pominąć jeśli utrudnia.
- **Uwaga środowiskowa:** WP test suite ładuje wtyczkę; `load_plugin_textdomain`
  na `init` odpali w bootstrapie. Test musi jawnie przełączyć locale i
  przeładować `.mo` (domyślne locale testów to en_US bez naszego `.mo` przed
  jego istnieniem). Zweryfikować w RED/GREEN, że `.mo` faktycznie się wczytuje
  z `languages/` (ścieżka względem `plugin_basename`).

## Inwarianty / uwagi

- Nie zmieniamy źródłowych literałów (zostają PL) — tylko dodajemy ładowanie i
  katalog. Zero ryzyka regresji dla locale polskiego (brak `.mo` → msgid PL).
- `src/Domain/**` bez zmian (i18n to warstwa prezentacji; domena zwraca kody).
- Każdy plik PHP poza `src/Domain/` zaczyna od `defined( 'ABSPATH' ) || exit;` —
  dotyczy nowej `src/I18n.php`.
- phpcs/phpstan czyste dla nowego kodu.

## Kolejność implementacji (dla planu)

1. `src/I18n.php` + rejestracja w bootstrapie + `Domain Path` (bez katalogu
   jeszcze) — hook podpięty.
2. Wygeneruj `.pot` (`i18n:pot`), utwórz `-en_US.po` z tłumaczeniami EN, skompiluj
   `.mo` (`i18n:mo`). npm scripty.
3. Test integracyjny: string → EN przy locale en_US (RED bez `.mo`/ładowania →
   GREEN po).
4. `.distignore`/packaging: `languages/` w zipie; weryfikacja zawartości zipa.
5. Doc w CLAUDE.md (komendy i18n), pełne testy, paczka.

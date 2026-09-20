# Event Registration

Wtyczka WordPress do budowania formularzy rejestracji na wydarzenia: konfigurowalne pola formularza (z warunkową widocznością), typy zgłoszenia z cenami i limitami miejsc, noclegi (pakiety × pokoje × inwentarz) z opcją osoby towarzyszącej, potwierdzenia mailowe (double opt-in, kolejka z retry), panel zgłoszeń z edycją i eksportem CSV oraz cykl życia danych zgodny z WP Privacy API. Jeden CPT obsługuje wiele wydarzeń na stronie, z historią. Render formularza w klasach Bootstrap 5 (opcjonalnie) i wielojęzyczność przez Polylang (treści, maile, link potwierdzenia). Auto-aktualizacja z GitHub Releases.

## Wymagania

- PHP 8.1+
- WordPress 6.4+
- Docker (środowisko deweloperskie przez `wp-env`)
- Node.js 20+ (build i testy JS)

## Instalacja (produkcja)

Wtyczka potrzebuje autoloadera Composera dla własnych klas oraz zbudowanego frontendu. Paczka do wgrania musi zawierać `vendor/` i `build/`:

```bash
composer install --no-dev --optimize-autoloader
npm ci && npm run build
```

Następnie skopiuj katalog wtyczki do `wp-content/plugins/` (lub spakuj do ZIP i wgraj przez panel WordPress) i aktywuj.

## Rozwój

```bash
# Zależności
docker run --rm -v "$PWD:/app" -w //app composer:2 install
npm install

# Środowisko WordPress (Docker) — przez wrapper, nie gołe npx wp-env
node scripts/wp-env.cjs start        # dev: http://localhost:8891  (admin / password)

# Build frontendu React
npm run build                        # jednorazowo
npm run start                        # watch
```

Wtyczka jest zamontowana w kontenerze wp-env automatycznie.

## Testy

```bash
# JS (host) — logika edytora konfiguracji
npm run test:js

# PHP jednostkowe (bez WordPressa) — warstwa domeny
node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpunit --testsuite unit

# PHP integracyjne (z WordPressem)
node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpunit -c phpunit-integration.xml.dist

# Analiza statyczna i standardy kodu
node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpstan analyse
node scripts/wp-env.cjs run tests-cli --env-cwd=wp-content/plugins/event-registration -- vendor/bin/phpcs

# E2E (wymaga działającej wp-env)
npm run test:e2e
```

## Struktura

```
src/
├─ Domain/         Czysta logika (schema, warunki, walidacja, ceny, limity, assembler) — zero WordPressa
├─ Admin/          CPT, capability, enqueue aplikacji React
├─ Persistence/    Migracje tabel, repozytoria konfiguracji i zgłoszeń
├─ Services/       Transakcyjna rezerwacja i potwierdzenie miejsc
├─ Rest/           Endpointy konfiguracji eventu, szablonów maili, tłumaczeń
├─ Cron/           Wygaszanie zgłoszeń pending
├─ Frontend/       Formularz publiczny (loader, render, submit, blok/shortcode, potwierdzenie, język)
├─ Mail/           Kolejka mailowa (szablony, placeholdery, kolejkowanie, dispatcher, subskrybent zdarzeń)
├─ Privacy/        WP Privacy API (exporter + eraser anonimizujący)
├─ Update/         Auto-aktualizacja z GitHub Releases
└─ I18n.php        Ładowanie textdomain + katalog languages/
assets/admin/      Aplikacja React (edytor konfiguracji) — logika w ops/*, komponenty cienkie
assets/public/     Statyczny form.js (warunki + toggle companion/roommate) + form.css + bootstrap.min.css
tests/             Unit (bez WP), Integration (wp-env), e2e (Playwright)
docs/superpowers/  Specyfikacje i plany implementacji
```

## Status

**Roadmapa 6 planów zamknięta** — wtyczka funkcjonalna. Zrealizowane:

1. ✅ Warstwa domeny (schema formularza, warunki, walidacja, typy, noclegi, ceny, decyzja o limitach)
2. ✅ Konfiguracja eventu w adminie (CPT, REST, React admin z zakładkami)
3. ✅ Formularz publiczny + transakcyjna rezerwacja miejsc (blok/shortcode, render serwerowy, antyspam, PRG, endpoint potwierdzenia double opt-in, warunki JS)
4. ✅ Kolejka mailowa — silnik (double opt-in, retry, wygasanie, powiadomienia organizatora) + edytor szablonów per event + ekran kolejki
5. ✅ Panel zgłoszeń — lista, akcje cyklu życia, edycja odpowiedzi, eksport CSV
6. ✅ Cykl życia danych + compliance (uninstall za bramką, WP Privacy exporter/eraser)
7. ✅ Auto-aktualizacja z GitHub Releases + release/CI

**Iteracja funkcjonalna (po roadmapie):**

- ✅ Warunkowa widoczność pól/sekcji (builder + render + JS)
- ✅ Render formularza w Bootstrap 5 (opt-in)
- ✅ Wielojęzyczność (Polylang): tłumaczenia treści per event (pola, opcje, typy, noclegi, krótkie etykiety, opisy), mail i link potwierdzenia w języku zgłoszenia
- ✅ Osoba towarzysząca (podwójne zajęcie noclegu, wycena, konfiguracja)
- ✅ Krótka etykieta pola (nagłówek eksportu) + opis pomocniczy pola (help text pod polem)

Zmiany wydań: `CHANGELOG.md`. Rejestr odłożonych funkcji: `docs/superpowers/backlog.md`.

## Wydawanie

1. Przenieś wpisy z `## [Unreleased]` w `CHANGELOG.md` do nowej sekcji `## [X.Y.Z] - RRRR-MM-DD` (ta sekcja staje się treścią GitHub Release i „Szczegółów" wtyczki w panelu WP; brak sekcji dla tagu = release-workflow padnie).
2. Podbij `Version:` w nagłówku `event-registration.php` (to jedyne źródło wersji — `Plugin::version()` je czyta).
3. Commit, potem `git tag vX.Y.Z && git push origin vX.Y.Z` (tag MUSI == wersja nagłówka, inaczej release-workflow padnie).
4. `release.yml` zbuduje zip (runtime bez plików dev, wg `.distignore`), wyciągnie sekcję changelog dla wersji i opublikuje go jako asset `event-registration.zip` w GitHub Release.
5. Instalacje z aktywnym auto-update dostaną aktualizację w ≤12h (cache), albo od razu po „Sprawdź aktualizacje" na ekranie wtyczek.

Bieżące zmiany dopisuj do `## [Unreleased]` w `CHANGELOG.md` na bieżąco (format [Keep a Changelog], angielski, wpisy user-facing).

## Licencja

GPL-2.0-or-later.

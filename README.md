# Event Registration

Wtyczka WordPress do budowania formularzy rejestracji na wydarzenia: konfigurowalne pola formularza, typy zgłoszenia z cenami i limitami miejsc, noclegi (pakiety × pokoje × inwentarz), potwierdzenia mailowe i eksport listy uczestników. Jeden CPT obsługuje wiele wydarzeń na stronie, z historią.

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
├─ Rest/           Endpoint konfiguracji eventu
├─ Cron/           Wygaszanie zgłoszeń pending
├─ Frontend/       Formularz publiczny (loader, render, submit, blok/shortcode, potwierdzenie)
└─ Mail/           Kolejka mailowa (szablony, placeholdery, kolejkowanie, dispatcher, subskrybent zdarzeń)
assets/admin/      Aplikacja React (edytor konfiguracji) — logika w ops/*, komponenty cienkie
assets/public/     Statyczny form.js (warunki) + form.css
tests/             Unit (bez WP), Integration (wp-env), e2e (Playwright)
docs/superpowers/  Specyfikacje i plany implementacji
```

## Status

Wtyczka jest w budowie (roadmapa 6 planów). Gotowe:

1. ✅ Warstwa domeny (schema formularza, warunki, walidacja, typy, noclegi, ceny, decyzja o limitach)
2. ✅ Konfiguracja eventu w adminie (CPT, REST, React admin z pięcioma zakładkami)
3. ✅ Formularz publiczny + transakcyjna rezerwacja miejsc (blok/shortcode, render serwerowy, antyspam, PRG, endpoint potwierdzenia double opt-in, warunki JS)
4. ✅ Kolejka mailowa — silnik (double opt-in, retry, wygasanie, powiadomienia organizatora)
5. 🔶 Admin maili — edytor szablonów per event gotowy; ekran kolejki (podgląd/wznowienie) osobny plan
6. ⬜ Panel zgłoszeń (CRUD, eksport, lista rezerwowa)
7. ⬜ Auto-aktualizacja + utwardzenie CI

## Licencja

GPL-2.0-or-later.

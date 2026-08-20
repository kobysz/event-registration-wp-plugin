# Cykl życia danych i compliance (Plan 6A) — projekt techniczny

Data: 2026-08-20
Status: zatwierdzony do planowania implementacji
Buduje na: [Plan 1 — domena](2026-08-18-domain-design.md), [Plan 3A — backend rezerwacji](2026-08-18-reservation-backend-design.md) (`RegistrationRepository`, `Migrations`, tabele), [Plan 4A — kolejka mailowa](2026-08-19-mail-queue-design.md) (`MailQueueRepository`, `evreg_mail_queue`), [Plan 5C — eksport CSV](2026-08-20-registration-export-design.md) (`RegistrationExportMapper`, `EventFormLoader`)

## 1. Cel i kontekst

Wtyczka jest produktem instalowanym na wielu stronach i trzyma dane osobowe uczestników (email,
imię, odpowiedzi, nocleg, wysłane maile). Plan 6 to produkcyjne dopięcie; 6A obejmuje **cykl życia
danych i zgodność**: (A) czyszczenie stanu przy odinstalowaniu wtyczki oraz (B) obsługę WP Privacy
API (eksport i wymazywanie danych osobowych). Auto-update, release i CI to Plan 6B.

Dziś wtyczka nie ma `uninstall.php` (usunięcie zostawia tabele, opcje, meta, capy, crony) ani żadnych
haków prywatności (organizator nie może spełnić żądania dostępu/usunięcia z RODO). 6A domyka oba.

**Decyzje bazowe:** uninstall **za bramką** (domyślnie zachowuje dane zgłoszeń; pełny wipe tylko przy
włączonym ustawieniu/stałej). Wymazywanie prywatności przez **anonimizację** (wiersze zostają, status
i zajętość nietknięte).

## 2. Zakres

### v1 (Plan 6A)
- `uninstall.php` (root) — cienki guard delegujący do `Uninstaller`
- `Uninstaller` (`src/Persistence/`) — cała logika wipe za bramką (testowalna)
- `SettingsScreen` (`src/Admin/`) — strona ustawień z checkboxem bramki (WP Settings API)
- `PrivacyProvider` (`src/Privacy/`) — exporter + eraser + policy content
- Nowe metody repo: `RegistrationRepository::{findByEmailPaged, anonymizeById, anonymizeBookingByRegistration}`, `MailQueueRepository::anonymizeByRegistration`
- Rejestracja `SettingsScreen` i `PrivacyProvider` w bootstrapie

### Poza Planem 6A
- Auto-update z GitHub, release packaging, hardening CI → Plan 6B
- Wymaz jako twarde kasowanie wierszy → wybrano anonimizację
- Pełny wipe zawsze / brak bramki → wybrano bramkę (default zachowaj)
- Globalna strona ustawień z wieloma opcjami → tylko jeden checkbox (YAGNI)
- Eksport/wymaz maili bez `registration_id` (osierocone) → kolejka zawsze ma `registration_id` (4A)

### Świadomie pominięte (YAGNI)
- Panel „prawo do bycia zapomnianym" w adminie — WP Privacy API (Narzędzia → Eksport/Usuń dane) wystarcza
- Szyfrowanie danych w spoczynku — poza zakresem
- Konfigurowalny format anonimizacji — stały placeholder

## 3. Decyzje projektowe

| # | Decyzja | Wybór | Uzasadnienie |
|---|---------|-------|--------------|
| 1 | Dekompozycja Planu 6 | 6A dane+compliance, 6B dystrybucja | Różne światy; 6A testowalne i samowystarczalne |
| 2 | Agresywność uninstall | Za bramką (opcja LUB stała), default zachowaj | Usunięcie wtyczki (np. przy update na części hostów) nie może nieodwracalnie tracić zgłoszeń |
| 3 | Tryb wymazu prywatności | Anonimizacja (wiersze zostają) | Prawo do bycia zapomnianym bez psucia liczników/zajętości wstecz |
| 4 | Lokalizacja logiki wipe | `Uninstaller::run()`, `uninstall.php` cienki | `uninstall.php` nietestowalny; klasa testowalna w kontenerze |
| 5 | Bramka: opcja + stała | `evreg_delete_data_on_uninstall` LUB `EVREG_DELETE_DATA_ON_UNINSTALL` | Opcja dla usera (checkbox), stała dla ops/multisite (wp-config) |
| 6 | Klucz osoby (privacy) | `email` | Klucz WP Privacy API; `idx_event_email` istnieje |
| 7 | Mapowanie odpowiedzi w eksporcie | Reuse `RegistrationExportMapper` (5C) | Jedno źródło schema→wartości |

## 4. Architektura

```
uninstall.php                              root: guard + require autoload + Uninstaller::run()
src/Persistence/Uninstaller.php            run(): wipe za bramką (DROP tabel, opcje, meta, posty CPT,
                                            crony, capy, transienty)
src/Admin/SettingsScreen.php               submenu „Ustawienia", WP Settings API, checkbox bramki
src/Privacy/PrivacyProvider.php            exporter + eraser + wp_add_privacy_policy_content
src/Persistence/RegistrationRepository.php + findByEmailPaged(), anonymizeById(), anonymizeBookingByRegistration()
src/Persistence/MailQueueRepository.php    + anonymizeByRegistration()
event-registration.php / src/Plugin.php    rejestracja SettingsScreen::register + PrivacyProvider::register
```

Granice: logika wipe wyłącznie w `Uninstaller` (jedyne miejsce z DROP TABLE/`wp_delete_post` sprzątającym).
Logika prywatności w `PrivacyProvider` + metody repo (jedyne SQL). `uninstall.php` cienki. Zero zmian
w `src/Domain/**`. Reuse magic stringów (tabele, hooki cronów, capy) z istniejących klas — bez duplikacji.

## 5. Czyszczenie przy odinstalowaniu

### `uninstall.php` (root)
```php
defined( 'WP_UNINSTALL_PLUGIN' ) || exit;
if ( file_exists( __DIR__ . '/vendor/autoload.php' ) ) {
	require_once __DIR__ . '/vendor/autoload.php';
}
if ( class_exists( \EvReg\Persistence\Uninstaller::class ) ) {
	\EvReg\Persistence\Uninstaller::run();
}
```
Bez logiki. Brak autoloadu → no-op bezpieczny (wtyczka i tak nie działałaby bez vendora).

### `Uninstaller::run(): void`
```
if ( ! self::shouldDeleteData() ) { return; }        // DOMYŚLNIE nic nie kasuje

global $wpdb;
// 1. Tabele (nazwy z Migrations::table(...))
foreach ( registrations, accommodation_bookings, mail_queue, locks ) {
	$wpdb->query( "DROP TABLE IF EXISTS {$table}" );
}
// 2. Opcje
delete_option( 'evreg_db_version' );      // Migrations::VERSION_OPTION
delete_option( 'evreg_caps_version' );    // Capabilities::VERSION_OPTION
delete_option( 'evreg_delete_data_on_uninstall' );
// 3. Eventy CPT + meta (paginacja get_posts ids, force delete)
do {
	$ids = get_posts( post_type=evreg_event, post_status=any, fields=ids, numberposts=100 );
	foreach ( $ids as $id ) {
		foreach ( _evreg_schema/_evreg_types/_evreg_accommodation/_evreg_settings/_evreg_mail_templates ) {
			delete_post_meta( $id, $meta_key );
		}
		wp_delete_post( $id, true );
	}
} while ( $ids niepuste );
// 4. Crony
foreach ( evreg_expire_pending, evreg_dispatch_mail, evreg_dispatch_mail_now, evreg_purge_mail_queue ) {
	wp_clear_scheduled_hook( $hook );
}
// 5. Capabilities (z roli administrator)
$role = get_role( 'administrator' );
foreach ( Capabilities::CAPS as $cap ) { $role?->remove_cap( $cap ); }
// 6. Transienty rate-limit
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\_transient\_evreg\_rate\_%'
               OR option_name LIKE '\_transient\_timeout\_evreg\_rate\_%'" );
```

`shouldDeleteData(): bool`:
```
( defined( 'EVREG_DELETE_DATA_ON_UNINSTALL' ) && EVREG_DELETE_DATA_ON_UNINSTALL )
|| (bool) get_option( 'evreg_delete_data_on_uninstall', false );
```
Stała ma priorytet (override ops). `Uninstaller` czyta nazwy tabel/hooków/capów z istniejących
klas/stałych (`Migrations`, klasy cronów, `Capabilities::CAPS`) — nie hardkoduje na nowo.

### `SettingsScreen` (`src/Admin/`)
- Submenu „Ustawienia" pod `edit.php?post_type=evreg_event` (`SLUG='evreg-settings'`), cap `edit_evreg_events`.
- WP Settings API: `register_setting( 'evreg_settings', 'evreg_delete_data_on_uninstall', [ 'type'=>'boolean', 'sanitize_callback'=> fn => (int) (bool) $v, 'default'=>false ] )`; jedna sekcja + `add_settings_field` z checkboxem.
- Render: `<form action="options.php">` + `settings_fields('evreg_settings')` + `do_settings_sections` + `submit_button`. Nonce/zapis natywny WP.
- Opis ostrzegawczy przy checkboxie (i18n): nieodwracalne, dane zgłoszeń zostaną skasowane przy usunięciu wtyczki.
- Gdy `EVREG_DELETE_DATA_ON_UNINSTALL` zdefiniowana — checkbox `disabled` + notka „wymuszone stałą w wp-config".
- Rejestracja `SettingsScreen::register` (hooki `admin_menu` + `admin_init` dla `register_setting`).

## 6. WP Privacy API

### `PrivacyProvider` (`src/Privacy/`)
Rejestracja:
```
add_filter( 'wp_privacy_personal_data_exporters', registerExporter );
add_filter( 'wp_privacy_personal_data_erasers',   registerEraser );
add_action( 'admin_init', addPolicyContent );
```
`registerExporter`/`registerEraser` dokładają wpis z kluczem `'event-registration'`, `exporter_friendly_name`
/`eraser_friendly_name` (i18n) i callbackiem.

### Exporter — `export( string $email, int $page = 1 ): array`
Zwraca `[ 'data' => array<int,array{group_id,group_label,item_id,data}>, 'done' => bool ]`.
```
$per_page = 50; $offset = ( $page - 1 ) * $per_page;
$rows = RegistrationRepository::findByEmailPaged( $email, $per_page, $offset );
foreach ( $rows as $row ) {
	$schema  = EventFormLoader::load( event_id );
	$mapper  = new RegistrationExportMapper();
	$data[]  = pary name/value (i18n): Status, Typ(label), E-mail, Imię, Cena, Utworzono, Potwierdzono, Notatka
	           + odpowiedzi ( answerColumns/answerCells z json_decode data )
	           + nocleg ( accommodationCells z findAccommodationBooking )
	           + maile ( MailQueue po registration_id: recipient/subject/data wysyłki );
	item_id = "evreg-registration-{id}", group_id='evreg_registration', group_label=i18n 'Zgłoszenia (Event Registration)';
}
done = count( $rows ) < $per_page;
```
Reuse `RegistrationExportMapper` (5C) do odpowiedzi i noclegu — jedno źródło mapowania. Wartości surowe
(WP eksportuje jako HTML, escaping robi rdzeń WP).

### Eraser — `erase( string $email, int $page = 1 ): array`
Zwraca `[ 'items_removed'=>bool, 'items_retained'=>bool, 'messages'=>string[], 'done'=>bool ]`.
```
$rows = RegistrationRepository::findByEmailPaged( $email, $per_page=50, $offset );
foreach ( $rows as $row ) {
	RegistrationRepository::anonymizeById( id );                    // email→deleted-{id}@example.invalid, name/note/data/token→puste
	RegistrationRepository::anonymizeBookingByRegistration( id );   // roommate_pref→''
	MailQueueRepository::anonymizeByRegistration( id );             // recipient/subject/body/headers→puste
}
items_retained = ( $rows niepuste );                                // anonimizacja = retained, nie removed
items_removed  = false;
messages = [ i18n "Zanonimizowano dane N zgłoszeń." ] gdy N>0;
done = count( $rows ) < $per_page;
```
**Anonimizacja NIE zmienia statusu/typu/ceny/dat** — zajętość i liczniki spójne (nie zwalnia miejsc
wstecz). Idempotentna: po anonimizacji wiersze nie znajdą się po pierwotnym mailu (email→placeholder),
więc kolejne strony naturalnie `done`.

### Policy content
`wp_add_privacy_policy_content( 'Event Registration', wp_kses_post( wpautop( $tekst ) ) )` — krótki
i18n akapit: co zbieramy (email, imię, odpowiedzi formularza, wybór noclegu, wysłane potwierdzenia)
i że dane trwają do usunięcia zgłoszenia/wtyczki.

## 7. Repozytorium — nowe metody (jedyne SQL)

`RegistrationRepository`:
- `findByEmailPaged( string $email, int $limit, int $offset ): array<int,array<string,mixed>>` — `WHERE email = %s ORDER BY id ASC LIMIT %d OFFSET %d` (wszystkie eventy).
- `anonymizeById( int $id ): void` — `$wpdb->update` PII: `email='deleted-'.$id.'@example.invalid'`, `name=''`, `data='{}'`, `note=''`, `token=''`, `updated_at`. NIE dotyka status/type_key/price_total/dat cyklu.
- `anonymizeBookingByRegistration( int $id ): void` — `UPDATE bookings SET roommate_pref='' WHERE registration_id=%d`.

`MailQueueRepository`:
- `anonymizeByRegistration( int $id ): void` — `UPDATE mail_queue SET recipient='', subject='', body='', headers='' WHERE registration_id=%d`.

Wzór formatów/`$wpdb->update` z istniejących `updateNote`/`updateRegistration`. `findAccommodationBooking`/
`MailQueue` selekcja maili — reuse istniejących (exporter tylko czyta).

## 8. Bezpieczeństwo i poprawność

- `uninstall.php` odpala się wyłącznie w kontekście WP uninstall (`WP_UNINSTALL_PLUGIN`) — guard obowiązkowy.
- Wipe za bramką — domyślnie NIC nie kasuje; jedyna droga do DROP to świadome włączenie opcji/stałej.
- SettingsScreen: zapis przez natywny `options.php` (nonce/cap WP Settings API); cap `edit_evreg_events` na dostęp do strony; `sanitize_callback` rzutuje na 0/1.
- Privacy exporter/eraser wołane przez rdzeń WP (Narzędzia → Dane osobowe) po weryfikacji tożsamości/nonce przez WP — provider ufa wejściu rdzenia, ale traktuje `$email` jako parametr zapytania prepared.
- Anonimizacja przez prepared `$wpdb->update`/`prepare`. Placeholdery emaili w domenie `example.invalid` (RFC 6761 — nigdy realny adres).
- Reuse stałych: brak rozjazdu między `Uninstaller` a rzeczywistymi nazwami tabel/hooków/capów.

## 9. Testy

### Integracyjne (`tests/Integration/`, kontener)

`Uninstaller`:
- **Bramka OFF (default):** `run()` bez opcji/stałej → tabele, opcje, meta, posty, capy NIENARUSZONE.
- **Bramka ON (opcja):** `update_option('evreg_delete_data_on_uninstall', 1)` → `run()` → tabele nie istnieją (`SHOW TABLES`), opcje skasowane, eventy CPT + meta usunięte, capy zdjęte z administratora, transienty rate skasowane.
- **Bramka ON (stała):** zdefiniuj `EVREG_DELETE_DATA_ON_UNINSTALL=true` (w izolowanym procesie/teście) → wipe mimo braku opcji. (Jeśli redefinicja stałej trudna w suite — pokryj `shouldDeleteData` przez opcję, a gałąź stałej mniejszym testem/asercją logiczną.)
- **Crony:** po `run()` z bramką ON `wp_next_scheduled` dla 4 hooków = false.

`PrivacyProvider` exporter:
- Zgłoszenie z odpowiedziami+nocleg+mailem po danym emailu → `export()` zwraca grupę z polami tożsamości, odpowiedziami (labele przez mapper), noclegiem, mailami; `done=true` gdy <per_page.
- Paginacja: >50 zgłoszeń jednego emaila → strona 1 `done=false`, strona 2 domyka.
- Inny email → pusta `data`, `done=true`.

`PrivacyProvider` eraser:
- Po `erase()` wiersze zgłoszeń danego emaila mają email=placeholder, name/note/data/token puste; **status/type_key/price_total/daty NIEZMIENIONE**; booking `roommate_pref` pusty; mail_queue recipient/subject/body/headers puste; `items_retained=true`, `items_removed=false`, `messages` niepuste.
- Inny email nietknięty.
- Idempotencja: drugie `erase()` po pierwotnym mailu → 0 zgłoszeń, `done=true`.

`RegistrationRepository`/`MailQueueRepository`:
- `findByEmailPaged` — filtr po emailu, LIMIT/OFFSET, ASC.
- `anonymizeById`/`anonymizeBookingByRegistration`/`anonymizeByRegistration` — nadpisują tylko pola PII, resztę zostawiają (asercja przez findById/findAccommodationBooking + odczyt kolejki).

`SettingsScreen`:
- `register_setting` zarejestrowane; `sanitize_callback` rzutuje '' /'1'/'on' na 0/1 poprawnie.
- Render strony zawiera checkbox i formularz `options.php` (asercja HTML). Cap gating (brak capa → brak submenu — asercja przez `current_user_can` mock lub sprawdzenie hooka).

### Architektura
`DomainPurityTest` zielony (nowy kod w Persistence/Admin/Privacy, nie Domain). phpcs + phpstan poziom 6 czysto. `uninstall.php` — sprawdź czy phpcs go obejmuje (dołącz do `<file>` w `phpcs.xml.dist` jeśli trzeba; guard `WP_UNINSTALL_PLUGIN` zamiast `ABSPATH`).

## 10. Kolejność implementacji

1. Metody repo (`findByEmailPaged`, `anonymize*`) — fundament SQL, testowalne osobno.
2. `Uninstaller` + `uninstall.php` — wipe za bramką.
3. `SettingsScreen` — checkbox bramki (WP Settings API).
4. `PrivacyProvider` — exporter + eraser + policy, spina metody repo + mapper 5C.
5. Rejestracja w bootstrapie (`SettingsScreen`, `PrivacyProvider`).

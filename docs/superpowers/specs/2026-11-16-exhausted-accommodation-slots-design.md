# Wyczerpane sloty noclegowe — design

Data: 2026-11-16. Pozycja backlogu: #2 (z rozmowy o konfiguracji noclegów pod forum 18–20.12.2026).

## Intent — po co to

Dziś formularz publiczny renderuje **wszystkie** komórki inwentarza noclegowego niezależnie od obłożenia (`FormRenderer::renderAccommodation` nie zna zajętości — `EventFormLoader` ładuje wyłącznie konfigurację). Gdy slot jest pełny, `CapacityCalculator::decide` zwraca `Outcome::Accepted` + `accommodationGranted=false` + `reason='accommodation_full'`, a `ReservationService::reserve` **zapisuje zgłoszenie bez bookingu**. `SubmitHandler` mapuje wynik na zwykły `success('reserved')` — powód ginie po drodze. Efekt: człowiek wybiera konkretne łóżko, dostaje maila „dziękujemy, potwierdź zgłoszenie" i **nigdzie nie dowiaduje się, że noclegu nie ma**. Cena jest policzona poprawnie (B7), ale cisza pozostaje.

Organizator potrzebuje odwrotnie: po wyczerpaniu limitu opcja danego noclegu ma się wyłączyć, a przegrana wyścigu ma być powiedziana wprost.

## Cele

1. Formularz publiczny pokazuje wyczerpane sloty jako **wyłączone**, z dopiskiem „(brak miejsc)" — widoczne, że opcja istniała, ale jest zajęta.
2. Zaznaczenie „Osoba towarzysząca" wyłącza dodatkowo sloty z **jednym** wolnym miejscem (companion zdejmuje 2) — reaktywnie, bez przeładowania.
3. Przegrana wyścigu nie kończy się cichym przyjęciem: zgłoszenie **nie zapisuje się**, formularz wraca z wpisanymi danymi i błędem przy polu noclegu.
4. Promocja z listy rezerwowej, która nie dostała grantu noclegu, mówi o tym adminowi.
5. Arytmetyka wolnych miejsc żyje w czystej domenie (inwariant z B5).

## Nie-cele

- Ekran edycji zgłoszenia w adminie (`RegistrationEditForm`) **nie** zyskuje informacji o dostępności. `editAnswers` już twardo odrzuca (`AdminActionResult::accommodationFull()`) — admin dowie się po zapisie.
- Żaden nowy publiczny endpoint REST ani doczytywanie dostępności AJAX-em.
- Wielokrotny wybór nocy ze wspólną pulą per noc (backlog #1).
- Reguły „pokój dwuosobowy wymaga osoby towarzyszącej" (backlog #3).
- Zmiany w panelu przeglądu (`RegistrationsOverview`).

## Anty-cele — co uznamy za porażkę, nawet gdy „działa"

- **Cichy downgrade w jakiejkolwiek formie.** Jeśli po zmianie zostanie ścieżka, w której zgłoszenie z wybranym noclegiem zapisuje się bez bookingu i bez komunikatu, zmiana jest nieudana.
- **Zgubiony wybór przez `disabled`.** Wyłączony radio nie trafia do `$_POST`. Jeśli przy re-renderze wyłączymy slot, który jest zaznaczony, przeglądarka wyśle puste `[slot]` = „Bez noclegu" — ten sam cichy downgrade, tylko po stronie klienta. Pełny slot **nigdy** nie może być renderowany jako `checked`.
- Przesprzedaż slotu. Render to migawka; arbitrem zostaje serwer w transakcji.
- Złamanie inwariantu `lockEvent()` → pierwszy COUNT.

## Ograniczenia

- `src/Domain/**` — zero WordPressa i zero `$wpdb` (pilnuje `tests/Unit/Architecture/DomainPurityTest.php`).
- Domena nie zwraca komunikatów dla użytkownika, tylko kody.
- Wszystkie nowe stringi UI przez i18n, text domain `event-registration`; po zmianie `npm run i18n:pot`.
- `assets/public/form.js` to zwykły ES5-owy plik bez webpacka (handle `evreg-public`); żadnych zależności.
- phpcs czysto pod `phpcs.xml.dist`, PHPStan poziom 6 bez nowych ignore.
- Formularz działa serwerowo bez JS: wyłączenie slotu z zerem miejsc musi pochodzić z serwera, nie z JS. JS odpowiada **tylko** za reakcję na przełącznik osoby towarzyszącej.
- Pola schematu zostają w namespace `evreg_field[<klucz>]`; kontrolki `evreg_companion` / `evreg_companion_name` zostają poza nim.

## Rozważane drogi

Część „render zna obłożenie":

| Droga | Na czym polega | Dlaczego nie / tak |
|---|---|---|
| A. Renderer liczy sam | `Shortcode` wstrzykuje `OccupancySnapshot` do `FormRenderer`, renderer odejmuje zajęte od `capacity` z `$field->config` | Najmniej plików, ale arytmetyka miejsc w warstwie prezentacji — łamie inwariant B5, nietestowalna bez WP. Odrzucone. |
| **B. Czysty `SlotAvailability` w domenie** | Nowy obiekt bierze `AccommodationConfig` + `OccupancySnapshot`, zwraca `left()` / `isFull()`; renderer tylko pyta | **Wybrane.** Test jednostkowy bez WP, arytmetyka w domenie, obiekt do reużycia w przeglądzie. Koszt: +1 plik, +1 suita. |
| C. Dostępność doczytywana REST-em | Publiczny endpoint + JS | Odporne na cache całych stron, ale przeciwko decyzji z 3B („serwerowy HTML, formularz działa bez JS") i nowa publiczna powierzchnia. Odrzucone; ryzyko cache'u zanotowane niżej. |

Część „serwer przestaje cicho degradować":

| Droga | Dlaczego nie / tak |
|---|---|
| Zmiana kontraktu `CapacityCalculator::decide` (nowy `Outcome`) | Kontrakt „przyjęte, nocleg nieprzyznany" jest uczciwy i czytają go `RegistrationsOverview`, `editAnswers` i `promoteFromWaitlist`. Zmiana rozlałaby się na wszystkie. Odrzucone. |
| **Polityka w `ReservationService::reserve`** | **Wybrane.** Dokładnie wzór, który już jest w `editAnswers`: po `decide` sprawdź `null !== selection && ! granted` → `ROLLBACK` + dedykowany kod wyniku. Domena nietknięta. |
| Twarde odrzucenie bez odtworzenia danych | Prostsze, ale człowiek wypełnia formularz od nowa. Odrzucone w rozmowie. |
| Przełącznik per wydarzenie (przyjmij bez noclegu / odrzuć) | Elastyczne, ale +1 opcja w Ustawieniach i +1 ścieżka w testach bez znanego odbiorcy. Odrzucone w rozmowie. |

Decyzja partnera: **re-render formularza z błędem przy polu noclegu**, zgłoszenie nie zapisane.

## Projekt

### 1. `SlotAvailability` — czysta domena

Nowy plik `src/Domain/Accommodation/SlotAvailability.php`.

```php
final class SlotAvailability {
    /** @param array<string,int> $left Mapa slot "pakiet|pokój" => liczba wolnych miejsc. */
    private function __construct( private readonly array $left ) {}

    public static function fromSnapshot( AccommodationConfig $config, OccupancySnapshot $taken ): self;

    public function left( string $slot ): int;                    // nieznany slot => 0
    public function isFull( string $slot, int $seats = 1 ): bool;  // left < seats
}
```

`fromSnapshot` iteruje po `$config->capacities()` (mapa `slot => limit`) i liczy `max( 0, $limit - $taken->forSlot( $slot ) )`. Slot nieobecny w inwentarzu → `left() === 0` → pełny. To lustro `CapacityCalculator`, gdzie `null === $slot_limit` też znaczy „nieprzyznany".

Obiekt nie wie nic o osobie towarzyszącej — liczbę potrzebnych miejsc podaje wywołujący (`isFull( $slot, 2 )`).

### 2. Render: wyłączone sloty

`FormRenderer` dostaje konstruktor:

```php
public function __construct( private readonly ?SlotAvailability $availability = null ) {}
```

`null` = renderuj wszystko dostępne (zgodność w tył dla istniejących testów i wywołań). `Shortcode::renderForm` buduje dostępność z konfiguracji noclegów eventu i `RegistrationRepository::occupancy( $event_id )`, po czym podaje ją konstruktorowi renderera.

**Ile miejsc potrzebuje to zgłoszenie.** Renderer liczy `needed` serwerowo: `2`, gdy odtwarzany `SubmitResult` ma zaznaczone `evreg_companion`, inaczej `1`. Wartość czyta tam, gdzie już ją czyta `renderCompanion` (`$result->submittedValue( 'evreg_companion' )`) — bez zmian w sygnaturze konstruktora. Pierwszy render (brak `$result`) → `needed = 1`.

To jest konieczne, nie ozdobne: wyścig, dla którego istnieje cel 2, to companion wchodzący na slot z **dokładnie jednym** wolnym miejscem. `CapacityCalculator` żąda `acc_seats = 2`, odrzuca — a przy regule `left < 1` ten sam slot wróciłby na re-renderze jako **aktywny i bez adnotacji**, z komunikatem „zajęty", który nie opisuje prawdy. Bez JS człowiek wysyłałby ten sam formularz w pętli.

W `renderAccommodation`, dla każdego slotu z inwentarza:

- `$left = null === $this->availability ? null : $this->availability->left( $slot );`
- **niedostępny** (`null !== $left && $left < $needed`):
  - `disabled` na `<input>`,
  - dopisek do etykiety: ` (brak miejsc)` gdy `$left < 1`, ` (za mało miejsc)` gdy `0 < $left < $needed` — dwa osobne, tłumaczone stringi (dla slotu z jednym wolnym łóżkiem „brak miejsc" byłoby nieprawdą),
  - klasa `is-full` na wrapperze `.evreg-choice`,
  - **nigdy `checked`**, nawet gdy `$slot === $current` (patrz anty-cele).
- `data-evreg-seats-left="<n>"` na `<input>` zawsze, gdy `null !== $left`.

**Porzucony wybór musi być widoczny.** Odmowa renderowania pełnego slotu jako `checked` zostawia grupę radio bez żadnego zaznaczenia, a pusta grupa nie wysyła klucza `[slot]` → `extractAnswers` czyta `''` → `AccommodationSelection::fromArray` → `null` → „Bez noclegu". Przy `allow_none = true` (domyślnie) `AccommodationValidator` tego nie złapie — czyli dokładnie anty-cel, tylko po stronie klienta. Dlatego gdy renderer porzuca bieżący wybór:

- zaznacza jawnie radio „Bez noclegu" (gdy `allow_none`), żeby stan formularza był prawdziwy, oraz
- dokłada w `.evreg-accommodation` notkę „Twój wcześniejszy wybór noclegu jest już niedostępny." — **także wtedy, gdy nie ma błędu noclegu** (np. zwykły re-render po braku nazwiska), bo wtedy nic innego o porzuceniu nie powie.

Gdy **wszystkie** sloty są niedostępne (`left < needed` dla każdego), a `allow_none` jest wyłączone, formularz nie ma nic do wybrania. Renderer dokłada wtedy komunikat „Brak wolnych miejsc noclegowych." (moja decyzja — bez tego człowiek widzi listę samych wyszarzonych pozycji bez wyjaśnienia; przy `allow_none` włączonym komunikat jest zbędny, bo „Bez noclegu" zostaje aktywne).

Radio „Bez noclegu" nigdy nie jest wyłączane.

### 3. `form.js`: reakcja na osobę towarzyszącą

Nowa funkcja, wzór `applyCompanion`:

```js
function applySlotSeats( form ) {
    var checkbox = form.querySelector( 'input[name="evreg_companion"]' );
    var needed = checkbox && checkbox.checked ? 2 : 1;
    form.querySelectorAll( '[data-evreg-seats-left]' ).forEach( function ( el ) {
        var left = parseInt( el.getAttribute( 'data-evreg-seats-left' ), 10 );
        var full = ! isNaN( left ) && left < needed;
        el.disabled = full;
        if ( full && el.checked ) {
            el.checked = false;
            var none = form.querySelector( 'input[name="' + el.name + '"][value=""]' );
            if ( none ) { none.checked = true; }
        }
        var wrap = el.closest( '.evreg-choice' );
        if ( wrap ) { wrap.classList.toggle( 'is-full', full ); }
    } );
}
```

Przeniesienie zaznaczenia na „Bez noclegu" jest obowiązkowe z tego samego powodu co po stronie serwera: sama grupa radio bez zaznaczenia wysyła pusty `[slot]`, czyli cichy downgrade. Ruch kropki jest przy tym widoczny, więc notka tekstowa z JS nie jest potrzebna.

Woła się tam, gdzie dziś `applyCompanion`: na starcie i w handlerze `change` formularza.

**Wymóg sprzężenia:** programowe `el.checked = false` **nie** emituje zdarzenia `change`, więc widoczność pola współlokatora (`applyRoommate`, podpięta pod `change` kontenera `.evreg-accommodation`) nie przeliczy się sama i pole może zostać widoczne po odznaczeniu slotu. Po `applySlotSeats` trzeba przeliczyć `applyRoommate` dla kontenerów `.evreg-accommodation` wewnątrz formularza. Dokładne przewiązanie pętli zostawiam wykonawcy — warunek jest taki, że `.evreg-accommodation` **bez** `.evreg-form` (ekran edycji w adminie) dalej musi działać.

Przy wyłączeniu przez JS etykieta nie dostaje dopisku „(brak miejsc)" — tekst jest serwerowy. Wystarczy wyszarzenie klasą `is-full` (moja decyzja: dopisywanie tekstu z JS wymagałoby przekazania przetłumaczonego stringu do skryptu, co dla tego przypadku jest nieproporcjonalne).

Styl `.evreg-choice.is-full` w `assets/public/form.css` — wyszarzenie etykiety.

### 4. Serwer: koniec cichego downgrade'u

`ReservationService::reserve`, **po** sprawdzeniu `Outcome::Rejected` i **przed** `insertRegistration`:

```php
if ( Outcome::Accepted === $decision->outcome
    && null !== $request->selection
    && ! $decision->accommodationGranted ) {
    $wpdb->query( 'ROLLBACK' );
    return ReservationResult::accommodationFull();
}
```

Warunek `Outcome::Accepted ===` jest konieczny: przy pełnym limicie wydarzenia lub typu `decide` wraca wcześniej z `full()`, gdzie `accommodationGranted` też jest `false`. Bez tego warunku zapis na listę rezerwową zamieniłby się w błąd noclegu.

`ReservationResult::accommodationFull()` — nowa fabryka, kod `'accommodation_full'`, bez id/tokenu, `accommodationGranted=false`.

`SubmitHandler::process`, nowe ramię `match`:

```php
'accommodation_full' => SubmitResult::invalid(
    array( $key => 'accommodation_full' ),
    $assembled->rawAnswers()
),
```

gdzie `$key` to klucz pola typu `accommodation` w schemacie. Do jego znalezienia nowa metoda w czystej domenie: `FormSchema::accommodationFieldKey(): ?string` (iteruje sekcje i pola, zwraca klucz pierwszego pola `FieldType::Accommodation` albo `null`). Gdy `null` — stan niemożliwy przy niepustej selekcji — fallback na `SubmitResult::rejected()`.

**`rawAnswers()`, nie `values()`.** `AssembledSubmission::valid()` niesie dziś `$result->values()` z `Validatora`, gdzie wartość pola noclegu jest **obiektem** `AccommodationSelection`, a klucze `evreg_companion` / `evreg_companion_name` nie istnieją wcale (`assemble()` dokłada je do `$answers`, nie do `$values`). Podanie `values()` do `SubmitResult::invalid` dałoby w `FormRenderer::renderAccommodation` `is_array( $value ) === false` → `$current = ''` → **„Bez noclegu" wyrenderowane jako zaznaczone**, plus skasowany stan osoby towarzyszącej. To jest cichy downgrade z anty-celów, tylko w innym miejscu.

Dlatego `AssembledSubmission` dostaje trzecie pole: surowe `$answers` wystawione przez `rawAnswers()`, ustawiane na **obu** gałęziach (`invalid()` i `valid()`) — na gałęzi błędu to ta sama tablica, którą dziś zwraca `values()`, więc kontrakt dla ekranu edycji w adminie (`RegistrationEditForm` / `RegistrationsScreen`) się nie zmienia. To jest dokładnie kształt, który `FormRenderer` już umie odtworzyć: `array{package,room,roommate}` + klucze companion.

Komunikat w `FormRenderer::errorMessage` pod kluczem `accommodation_full`: **jeden** string pokrywający oba przypadki (slot zajęty do zera i slot bez miejsca dla dwóch osób) — w duchu „Wybrany nocleg nie ma już wolnych miejsc dla Twojego zgłoszenia. Wybierz inny albo »Bez noclegu«." Rozbijanie na dwa komunikaty wymagałoby przeniesienia powodu przez `CapacityDecision` → `ReservationResult` → `SubmitResult`, co nie zarabia na siebie (dokładne brzmienie: patrz „Zostawione wykonawcy").

`Shortcode::resultNotice` nie wymaga zmian — kod wyniku to `invalid`, który już mapuje się na „Popraw zaznaczone pola.", a szczegół nosi błąd przy polu.

Re-render tej samej strony pokaże wybrany slot już jako wyłączony „(brak miejsc)", bo dostępność liczy się świeżo przy każdym renderze.

### 5. `promoteFromWaitlist`: głośna promocja bez noclegu

Dzisiejsze kasowanie nieaktualnego bookingu zostaje — jest celowym zabezpieczeniem przed przesprzedażą (komentarz w kodzie). Zmienia się tylko wynik: gdy `null !== $selection && ! $decision->accommodationGranted`, metoda zwraca `AdminActionResult::promotedWithoutAccommodation()` zamiast `promoted()`. `RegistrationsScreen` dokłada komunikat do swojej mapy kodów.

Świadomie **nie** blokujemy promocji: odmowa zostawiłaby człowieka na liście rezerwowej mimo wolnego miejsca na wydarzeniu. Admin dowiaduje się i może albo zostawić, albo wejść w edycję odpowiedzi i zmienić nocleg.

### 6. i18n

Sześć nowych stringów: dopisek „(brak miejsc)", dopisek „(za mało miejsc)", notka „Twój wcześniejszy wybór noclegu jest już niedostępny.", komunikat „Brak wolnych miejsc noclegowych.", komunikat błędu przy polu noclegu, komunikat admina o promocji bez noclegu. Po implementacji `npm run i18n:pot`.

## Testy

- **Unit** (`tests/Unit/`): `SlotAvailability` — `left()` dla slotu z zapasem, z zerem, nieznanego; `isFull()` dla `seats=1` i `seats=2` przy jednym wolnym miejscu; `fromSnapshot` z pustą migawką. `FormSchema::accommodationFieldKey()` — schemat z polem noclegu i bez.
- **Integration** (`tests/Integration/`):
  - render: pełny slot ma `disabled` + dopisek „(brak miejsc)"; slot z jednym wolnym miejscem przy zaznaczonym companion ma `disabled` + dopisek „(za mało miejsc)", a przy niezaznaczonym **nie** ma `disabled`; pełny slot **nie** ma `checked`, choć jest bieżącą wartością, i wtedy „Bez noclegu" **jest** zaznaczone oraz widać notkę o porzuconym wyborze; atrybut `data-evreg-seats-left` obecny; wszystkie niedostępne + `allow_none=false` → komunikat; `FormRenderer` bez wstrzykniętej dostępności renderuje jak dziś.
  - `reserve` z pełnym slotem: wynik `accommodation_full`, w tabelach **zero** nowych wierszy zgłoszenia i bookingu.
  - `reserve` przy pełnym wydarzeniu z włączoną listą rezerwową i wybranym noclegiem: wynik `waitlisted` (regresja na warunek `Outcome::Accepted`).
  - `SubmitHandler::process` → `SubmitResult` o kodzie `invalid` z błędem pod kluczem pola noclegu, a odtworzone wartości **zachowują** wybrany slot (`array{package,room}`, nie obiekt) oraz stan `evreg_companion` / `evreg_companion_name` — test wprost na regresję z sekcji 4.
  - `promoteFromWaitlist` bez grantu → `promotedWithoutAccommodation`, booking skasowany, status `pending`.
- **Do przepisania:** `tests/Integration/Services/ReservationServiceTest.php:119-121` asertuje dzisiejsze ciche przyjęcie na pełnym slocie. Ten test wyraża zachowanie, które usuwamy — zmienia się na oczekiwanie `accommodation_full`.
- **E2E** (Playwright): pełny slot widoczny i nieklikalny; zaznaczenie osoby towarzyszącej wyłącza slot z jednym wolnym miejscem.
- **jest**: brak — `form.js` nie jest modułem `assets/admin/ops/*`, pokrywa go e2e.

## Ryzyka

- **Cache całych stron** (np. wtyczka cache'ująca) zamrozi wyrenderowaną dostępność. Poprawność trzyma serwer w transakcji; człowiek zobaczy błąd przy polu zamiast wyszarzonej opcji. Zanotowane, nie rozwiązywane (patrz droga C).
- **+1 zapytanie `occupancy()` na render formularza** (w środku trzy COUNT-y). Formularz nie jest ścieżką o wysokim ruchu; akceptowane.
- `allow_none=false` przy wszystkich slotach pełnych blokuje wysłanie formularza. To uczciwe odwzorowanie braku miejsc, złagodzone komunikatem z punktu 2.

## Decyzje podjęte po recenzji wykonawczej

Świeży recenzent znalazł trzy miejsca, w których pierwotna wersja tego dokumentu sama produkowała anty-cel. Rozstrzygnięcia (moje decyzje, nie ustalenia z rozmowy):

1. **Nośnik wpisanych danych na re-renderze** → `AssembledSubmission::rawAnswers()` ustawiane na obu gałęziach, zamiast `values()` (sekcja 4). Alternatywy — uczenie `FormRenderer` obsługi obiektu `AccommodationSelection` albo pogodzenie się z utratą danych — przegrały: pierwsza dubluje normalizację, druga łamie cel 3.
2. **Render zna potrzebną liczbę miejsc** (`needed`), reguła to `left < needed`, nie `left < 1` (sekcja 2). Bez tego companion na slocie z jednym łóżkiem dostaje aktywną opcję i komunikat, który kłamie — a bez JS wysyła ten sam formularz w pętli.
3. **Porzucony wybór przenosi zaznaczenie na „Bez noclegu" i jest ogłoszony notką**, serwerowo i w `form.js` (sekcje 2 i 3). Pusta grupa radio wysyła pusty `[slot]`, którego `AccommodationValidator` przy `allow_none = true` nie kwestionuje.

Dodatkowo, znalezione przy pisaniu planu: pusta selekcja w odtwarzanych odpowiedziach daje `$current === '|'` (puste `package` i `room` zlepione separatorem), więc radio „Bez noclegu" **dziś** nie zaznacza się przy re-renderze formularza z pustym noclegiem. Plan normalizuje `'|'` do `''` w `renderAccommodation` — ta sama rodzina błędu, jedna linia.

Rzeczy, które recenzent zgłosił jako swoje do rozstrzygnięcia i które zostają u wykonawcy: skąd `Shortcode` bierze `AccommodationConfig`, czy `FormSchema::accommodationFieldKey()` ma zastąpić prywatne `SubmissionAssembler::accommodationField()`, render pozycji o `capacity: 0`, kształt fixture'u e2e.

## Zostawione wykonawcy

- Dokładne brzmienie czterech nowych stringów PL (dopisek, komunikat braku miejsc, błąd przy polu, komunikat admina) — ton jak w istniejących mapach `FormRenderer::errorMessage` i `RegistrationsScreen`.
- Szczegóły CSS `.evreg-choice.is-full` (`opacity`/kolor) — zgodnie z resztą `form.css`.
- Przewiązanie pętli w `form.js` tak, by `applyRoommate` przeliczał się po programowym odznaczeniu slotu, przy zachowaniu działania kontenera `.evreg-accommodation` bez `.evreg-form`.
- Czy `SlotAvailability` dostanie dodatkowo `capacity()`/`taken()` — tylko jeśli testy tego wymagają; minimalne API wystarcza.

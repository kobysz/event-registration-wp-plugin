# Liczba osób w pokoju — design

Data: 2026-11-16. Pozycja backlogu: #3 (z rozmowy o konfiguracji noclegów pod forum 18–20.12.2026).

## Intent — po co to

Dziś flaga „osoba towarzysząca" jest **globalna dla noclegów** (`companion_enabled` w `_evreg_accommodation`), a pokój nie wie o sobie nic poza nazwą i tym, czy pytać o współlokatora. Skutek: pokój dwuosobowy da się wziąć samemu — zdejmuje wtedy jedno miejsce ze slotu i zostaje w połowie puste — a pokój jednoosobowy da się wziąć w dwójkę. Organizator nie ma czym tego wyrazić.

Dodatkowo kolejność na formularzu jest odwrotna do tego, jak człowiek myśli: checkbox towarzyszącej renderuje się **pod** listą noclegów, więc najpierw wybiera się pokój, a potem dopisuje osobę.

Zmiana: pokój dostaje liczbę osób. Z niej wynika wszystko — ile miejsc zdejmuje ze slotu, ile razy liczy się cena, i czy w ogóle da się go wybrać z towarzyszącą. Checkbox towarzyszącej idzie **nad** listę i steruje tym, które pokoje są w ogóle pokazane.

## Cele

1. Pokój ma ustawianą liczbę osób (1 albo 2) w zakładce Noclegi.
2. Liczba osób zdejmowanych ze slotu i mnożnik ceny biorą się z liczby osób w pokoju, nie z flagi towarzyszącej.
3. Zaznaczenie „Osoba towarzysząca" pokazuje pokoje dwuosobowe i chowa jednoosobowe; odznaczenie — odwrotnie. Serwerowo, z JS jako lustrem.
4. Checkbox „Osoba towarzysząca" renderuje się **nad** listą noclegów.
5. Niespójna para (dwuosobowy bez towarzyszącej, jednoosobowy z towarzyszącą) jest odrzucana z komunikatem przy polu noclegu — serwer jest arbitrem, nie samo chowanie w JS.
6. Istniejące wydarzenia nie zmieniają zachowania po aktualizacji.

## Nie-cele

- Pokoje na 3+ osób. Cała maszyneria „towarzyszącej" zna dokładnie jedną dodatkową osobę; powyżej dwóch trzeba by N−1 nazwisk i to jest inna funkcja.
- Filtrowanie `<select>` noclegu na ekranie edycji zgłoszenia w adminie. Walidacja **działa** tam automatycznie (ten sam `SubmissionAssembler`), więc niespójna para jest odrzucana przy zapisie z czytelnym komunikatem — zawężanie listy jest kosmetyką i czeka.
- Wspólna pula miejsc per pokój, niezależna od pakietu. To następna, większa zmiana (rodzina backlogu #1); ten design jej nie zamyka, ale celowo wprowadza pojęcie, którego ona będzie potrzebować.
- Zmiana semantyki `companion_counts_event` (+1 do limitu wydarzenia).
- Cena per pokój. Cena zostaje per osoba na komórce inwentarza i mnoży się przez liczbę osób.
- Nowa migracja bazy. Kolumna `seats` na bookingu istnieje od db v5 i przyjmuje 1 albo 2 bez zmian.
- **Panel przeglądu zgłoszeń (`RegistrationsOverview` / `OccupancyReport`) zostaje bez zmian i NIE dostaje mapy liczby osób.** `OccupancyReport::slots()` liczy `wolne = max( 0, limit − zajęte )` i nigdy nie pyta o liczbę miejsc, więc przekazany argument byłby martwy. Uświadomienie przeglądu o parach („jedno wolne łóżko w pokoju dwuosobowym = nie do sprzedania, pokaż 0") to osobna zmiana w czystej domenie wraz z własnymi testami.

## Anty-cele — co uznamy za porażkę, nawet gdy „działa"

- **Ukryty, ale zaznaczony radio.** `hidden` (w odróżnieniu od `disabled`) **nadal się wysyła**. Gdyby schowany pokój został zaznaczony, submit przeszedłby z pokojem niepasującym do stanu checkboxa. A gdyby schowanie zostawiło grupę bez zaznaczenia, pusty `[slot]` zostałby odczytany jako „Bez noclegu" bez wiedzy zgłaszającego się. To ta sama rodzina cichego downgrade'u, którą zlikwidowała poprzednia zmiana — nie wolno jej tu wpuścić z powrotem.
- **Zmiana zachowania istniejących wydarzeń po aktualizacji.** Pokój bez ustawionej liczby osób musi działać dokładnie jak dziś. Wtyczka stoi na kilku stronach.
- **Dwa źródła prawdy dla liczby miejsc.** Reguła „ustawiona liczba osób, a gdy nieustawiona to `companion ? 2 : 1`" musi istnieć w jednym miejscu, nie w trzech kopiach rozsianych po domenie.
- **Rozjazd ceny i pojemności.** Jeśli slot zdejmuje 2 miejsca, cena musi liczyć 2 — i odwrotnie. Jedna liczba karmi oba.
- Złamanie inwariantu `lockEvent()` → pierwszy COUNT.

## Ograniczenia

- `src/Domain/**` — zero WordPressa i zero `$wpdb` (pilnuje `tests/Unit/Architecture/DomainPurityTest.php`).
- Domena zwraca kody, nigdy komunikatów dla użytkownika.
- Cała arytmetyka miejsc i ceny w czystej domenie (inwariant z B5).
- Wszystkie stringi UI przez i18n, text domain `event-registration`; po zmianie `npm run i18n:pot`.
- `assets/public/form.js` — zwykły ES5 bez webpacka, bez zależności.
- Formularz działa serwerowo bez JS. Chowanie niepasujących pokojów pochodzi z serwera; JS tylko lustrzy.
- Logika mutacji admina w czystych modułach `assets/admin/ops/*` testowanych `jest`; komponenty cienkie.
- phpcs czysto pod `phpcs.xml.dist`, PHPStan poziom 6 bez nowych `@phpstan-ignore`.
- Pola schematu zostają w namespace `evreg_field[<klucz>]`; `evreg_companion` / `evreg_companion_name` zostają poza nim.

## Rozważane drogi

| Droga | Na czym polega | Dlaczego nie / tak |
|---|---|---|
| **A. Liczba osób na `RoomType`, do matematyki przez `CapacityLimits`** | Pokój niesie `occupancy`; mapa `slot → liczba osób` wchodzi do `CapacityLimits`; `CapacityCalculator` i `PriceCalculator` pytają o miejsca zamiast mnożyć przez flagę | **Wybrane.** Liczba osób to cecha pokoju, nie daty ani zgłoszenia. Trzy ścieżki transakcyjne i panel przeglądu budują `CapacityLimits` już dziś — dostają jeden argument więcej. |
| B. Liczba osób na komórce inwentarza (pakiet × pokój) | Ten sam pokój mógłby być raz jedno-, raz dwuosobowy | Odrzucone: bzdura w domenie (łóżek nie ubywa od daty) i jedna liczba per komórka zamiast per pokój rozdyma konfigurację. |
| C. Wywalić checkbox, wywnioskować towarzyszącą z wybranego pokoju | Wybór pokoju *jest* odpowiedzią; zero walidacji międzypolowej | Odrzucone: gubi „przyjeżdżam z kimś, ale noclegu nie potrzebujemy" — dziś towarzysząca jest niezależna od noclegu i potrafi dodać +1 do limitu wydarzenia bez łóżka. `companion` przestałby być odpowiedzią i stał się wyliczeniem, co rozlałoby się na CSV, maile, eksport prywatności i edycję w adminie. |

Decyzja partnera: **A**, z checkboxem nad listą i chowaniem (nie wyszarzaniem) niepasujących pokojów.

## Projekt

### 1. Model: liczba osób na pokoju

`src/Domain/Accommodation/RoomType.php` — nowe pole:

```php
public function __construct(
    public readonly string $key,
    public readonly string $label,
    public readonly bool $roommateField = false,
    public readonly int $occupancy = 0
) {}
```

`occupancy`: `0` = nieustawione (zachowanie jak dziś), `1`, `2`. `fromArray` czyta `(int) ( $data['occupancy'] ?? 0 )` i **rzuca `SchemaException`** dla wartości poza `{0,1,2}` — admin UI daje tylko trzy legalne wybory, więc rzut chroni przed ręcznie edytowaną metą albo obcym klientem REST. `toArray` dokłada `occupancy`.

### 2. Jedno źródło prawdy dla liczby miejsc

Nowy plik `src/Domain/Accommodation/RoomOccupancy.php`:

```php
final class RoomOccupancy {
    /** Ile miejsc zajmuje zgłoszenie w pokoju o danej liczbie osób. */
    public static function seats( int $occupancy, bool $companion ): int {
        return 0 === $occupancy ? ( $companion ? 2 : 1 ) : $occupancy;
    }
}
```

To **jedyne** miejsce, w którym żyje reguła zgodności w tył. Wszyscy czytelnicy delegują:

- `RoomType::seats( bool $companion ): int` → `RoomOccupancy::seats( $this->occupancy, $companion )`
- `AccommodationConfig::seatsFor( string $room_key, bool $companion ): int` → `RoomType::seats`, a dla nieznanego pokoju `RoomOccupancy::seats( 0, $companion )` (udokumentowany fallback na config-drift)
- `AccommodationConfig::slotOccupancies(): array<string,int>` → mapa `"pakiet|pokój" → occupancy pokoju` (surowa, `0` dla nieustawionych), do budowy limitów
- `CapacityLimits::seatsForSlot( string $slot, bool $companion ): int` → `RoomOccupancy::seats( $this->perSlotSeats[ $slot ] ?? 0, $companion )`

### 3. Pojemność i cena liczą z liczby osób

`CapacityLimits` dostaje szósty parametr konstruktora `private readonly array $perSlotSeats = array()` (domyślne `array()` nie łamie istniejących wywołań, w tym testowych) plus `seatsForSlot()` z punktu 2.

`CapacityCalculator::decide` — jedna linia:

```php
// było: $acc_seats = $companion ? 2 : 1;
$acc_seats = $limits->seatsForSlot( $slot, $companion );
```

`PriceCalculator::total` — **bez zmiany sygnatury** (dostaje już `?AccommodationConfig $config`):

```php
// było: $total += $item->price * ( $companion ? 2 : 1 );
$total += $item->price * $config->seatsFor( $selection->roomKey, $companion );
```

`ReservationService` — **trzy** miejsca budują `CapacityLimits` (`reserve`, `promoteFromWaitlist`, `editAnswers`), każde dostaje `$accommodation->slotOccupancies()` jako szósty argument. **Trzy** miejsca wstawiają booking (te same trzy metody) i każde liczy `$seats` przez `$accommodation->seatsFor( $selection->roomKey, $companion )` zamiast `$companion ? 2 : 1`. Policz je w kodzie przed zmianą, nie na pamięć — to jest nazwana w „Ryzykach" najłatwiejsza do przeoczenia część tej zmiany, a pominięcie jednego miejsca daje cichy rozjazd między pojemnością a ceną na tej jednej ścieżce.

`RegistrationsOverview` **nie** dostaje nowego argumentu (patrz Nie-cele). **Kolejność `lockEvent()` → `occupancy()` → `decide()` nietknięta we wszystkich trzech ścieżkach.**

### 4. Walidacja niespójnej pary

Reguła jest **międzypolowa**: łączy pole schematu (`evreg_field[nocleg]`) z kontrolką spoza schematu (`evreg_companion`). `FieldValidator::validate( Field, $raw )` nie widzi tej drugiej, a przerabianie interfejsu dotknęłoby wszystkich walidatorów.

**Sam sąd jest czysty i mieszka w domenie.** Nowa metoda na `RoomType`:

```php
/** Zwraca kod błędu albo null, gdy para pokój/towarzysząca jest spójna. */
public function companionMismatch( bool $companion ): ?string {
    if ( 0 === $this->occupancy ) {
        return null;                       // nieustawione = jak dziś, bez reguły
    }
    if ( $companion && 1 === $this->occupancy ) {
        return 'companion_not_allowed';
    }
    if ( ! $companion && 2 === $this->occupancy ) {
        return 'companion_required';
    }
    return null;
}
```

**Kiedy się odpala, zależy od powierzchni** — i to jest decyzja partnera, nie detal:

- **Formularz publiczny: zawsze.** Każda submisja to nowy wybór, więc nie ma „stanu przed".
- **Edycja w adminie: tylko gdy edycja faktycznie zmienia nocleg albo flagę towarzyszącej.** Bez tego oznaczenie pokoju jako dwuosobowego zablokowałoby **każdy** zapis starych zgłoszeń w tym pokoju — admin nie poprawiłby w nich numeru telefonu, dopóki nie zaznaczy towarzyszącej, co dociąga wymóg imienia i potencjalnie +1 do `global_cap`.

**Dlatego reguła NIE idzie do `SubmissionAssembler`.** Asembler jest wspólny dla obu powierzchni (Plan 5B) i jest bezstanowy względem zapisanego wiersza — gdyby wołał regułę bezwarunkowo, admin dostałby blokadę, której właśnie unikamy. Zamiast tego każdy wywołujący stosuje ją wtedy, kiedy jego powierzchnia tego wymaga:

- `SubmitHandler::process` — po udanym `assemble()`, przed `reserve()`: gdy `companionMismatch()` zwróci kod, zwróć `SubmitResult::invalid( array( $accommodationKey => $code ), $assembled->rawAnswers() )`. To **dokładnie** ten sam wzór co obsługa `accommodation_full` dołożona w poprzedniej zmianie — ten sam arm, to samo źródło odtworzonych odpowiedzi.
- `RegistrationsScreen::handle_edit` — po udanym `assemble()`, przed `editAnswers()`: zbuduj „stan przed" z danych, które ta metoda **już ma w ręku** (`$repository`, `$row`), czyli `$row['companion']` oraz slot z `$repository->findAccommodationBooking( $id )`. Regułę stosuj tylko gdy nowa para różni się od zapisanej. Błąd renderuj istniejącą gałęzią „niepoprawna walidacja" (`RegistrationEditForm::render( ..., $errors, $values )`), dokładając kod pod kluczem pola noclegu.

`companion_name_required` **zostaje** w `SubmissionAssembler` — ta reguła jest bezwarunkowa na obu powierzchniach, więc należy do wspólnego pipeline'u. Rozdział jest zamierzony: do asemblera idą reguły bezwarunkowe, do wywołujących te, których wyzwalacz zależy od powierzchni.

Odrzucona alternatywa: dołożyć `SubmissionAssembler::assemble()` opcjonalny trzeci argument ze „stanem przed" (null = waliduj zawsze). Trzyma całą walidację w jednym miejscu, ale rozszerza sygnaturę wspólnego szwu o parametr, który ma sens tylko dla jednego z dwóch wywołujących.

Guard: nie dokładaj kodu, gdy pole noclegu **ma już** błąd (np. `invalid_accommodation`) — jedno pole, jeden komunikat.

Komunikaty (mapy `errorMessage` w `FormRenderer` **i** `RegistrationEditForm` — admin pokazuje te same kody):

- `companion_required` — w duchu „Ten pokój jest dwuosobowy. Zaznacz osobę towarzyszącą albo wybierz pokój jednoosobowy."
- `companion_not_allowed` — w duchu „Ten pokój jest jednoosobowy. Odznacz osobę towarzyszącą albo wybierz pokój dwuosobowy."

### 5. Render: checkbox nad listą, niepasujące pokoje schowane

**Kolejność.** W `FormRenderer::renderField` blok `renderCompanion()` przenosi się z miejsca **po** zamknięciu `</div>` pola noclegu na miejsce **przed** otwarciem tego `<div>`. Nic poza kolejnością się w nim nie zmienia.

**Stan wyjściowy czyta serwer** z odtwarzanego `SubmitResult` (`submittedValue( 'evreg_companion' )`) — ten sam odczyt, którego już używają `renderCompanion` i `seatsNeeded`. Pierwszy render (brak `$result`) → towarzysząca odznaczona.

**Per wiersz slotu** renderer emituje `data-evreg-occupancy="<n>"` (`0` dla nieustawionych) i wylicza:

- `$rowSeats = RoomOccupancy::seats( $occupancy, $companion_on )` — zastępuje dzisiejsze globalne `$seats` z `seatsNeeded()` w wyliczaniu `closed` (`isFull( $slot, $rowSeats )`). Dzięki temu „brak miejsc" liczy się per pokój, nie per formularz.
- `$mismatch = 0 !== $occupancy && $occupancy !== ( $companion_on ? 2 : 1 )` → wiersz dostaje **`hidden` ORAZ `disabled`**.

**Dlaczego oba atrybuty.** `hidden` chowa, ale nie blokuje wysyłki; `disabled` blokuje wysyłkę, ale sam nie chowa. Bez `disabled` schowany-a-zaznaczony radio przeszedłby do `$_POST` (anty-cel). Bez `hidden` mielibyśmy wyszarzoną połowę listy, czyli to, co partner odrzucił.

**Pułapka CSS — `hidden` samo nie wystarczy.** `assets/public/form.css` ustawia `.evreg-choice { display: block; }` (Bootstrapowe `.form-check` też), a to bije regułę przeglądarki dla `[hidden]`. Dokładnie dlatego w tym pliku już istnieje `.evreg-section[hidden] { display: none; }`. Trzeba bliźniaczej reguły `.evreg-choice[hidden] { display: none; }`, a testy renderu muszą sprawdzać coś więcej niż samą obecność atrybutu — obecność atrybutu bez reguły CSS to wiersz, który dalej widać.

**Pole współlokatora przy pokoju dwuosobowym nie renderuje się.** Drugie łóżko zajmuje imiennie wskazana osoba towarzysząca, więc pytanie „Preferowana osoba w pokoju" traci sens. Warunek na render tego pola rozszerza się z „jakikolwiek pokój ma `roommate_field`" o „i nie jest to pokój z `occupancy === 2`". Flaga `roommate_field` na pokoju dwuosobowym staje się bezskuteczna — help text w adminie musi to powiedzieć, żeby organizator nie szukał pola, które nigdy nie wyjdzie.

**Porzucony wybór.** Jeśli bieżąca selekcja trafia na wiersz `mismatch` (albo `closed`), obowiązuje mechanizm z poprzedniej zmiany: wiersz **nigdy** nie renderuje się jako `checked`, zaznaczenie przenosi się jawnie na „Bez noclegu", a użytkownik dostaje notkę. Istniejąca notka „Twój wcześniejszy wybór noclegu jest już niedostępny." pokrywa oba powody — nie dodajemy drugiego stringu.

**Gdy filtr nie zostawia nic do wyboru.** Sala z samymi pokojami dwuosobowymi przy odznaczonym checkboxie (albo samymi jednoosobowymi przy zaznaczonym) zwija listę do „Bez noclegu", a przy `allow_none` wyłączonym — do niczego wybieralnego, bez wyjaśnienia. Istniejący komunikat „Brak wolnych miejsc noclegowych." jest o **zajętości** (`left < needed`), nie o niedopasowaniu, więc go nie przeciążamy. Dochodzi **jeden nowy string** w duchu „Dla tego wyboru nie ma pasujących pokojów — zmień zaznaczenie osoby towarzyszącej.", pokazywany gdy wszystkie wiersze są `mismatch`. Ostrzeganie organizatora w builderze o konfiguracji „wszystkie pokoje schowane przy wyłączonym checkboxie" jest poza zakresem.

`seatsNeeded()` przestaje być potrzebne jako globalna liczba, ale stan checkboxa nadal trzeba odczytać. Konkretny kształt (zostawić `seatsNeeded` i dołożyć `companionOn`, czy zamienić na jedno `companionSubmitted()`) zostawiam wykonawcy; wymóg: zacieśnienie z poprzedniej zmiany („gdy event nie pozwala na towarzyszącą, odtworzony POST nie może jej udawać") musi zostać.

### 6. `form.js`: lustro filtra

Nowa funkcja obok `applySlotSeats`, w tym samym przebiegu:

```
applyRoomOccupancy( form ):
    companion = checkbox evreg_companion zaznaczony
    dla każdego [data-evreg-occupancy]:
        occupancy = parseInt(...)
        mismatch = occupancy !== 0 && occupancy !== (companion ? 2 : 1)
        el.hidden = mismatch; el.disabled = mismatch
        wrapper .evreg-choice też dostaje hidden
        jeżeli mismatch && el.checked → odznacz i przenieś zaznaczenie na radio value=""
```

`applySlotSeats` przestaje liczyć jedno globalne `needed` i czyta je per wiersz: `needed = occupancy || (companion ? 2 : 1)` — lustro `RoomOccupancy::seats`, tak jak `isMet` jest lustrem `ConditionEngine`. Obie funkcje muszą uzgodnić `disabled`: slot bywa zamknięty z dwóch niezależnych powodów (brak miejsc albo niepasująca liczba osób), więc `disabled` ustawia suma tych powodów, nie ostatnia wygrywająca funkcja.

Po przeniesieniu zaznaczenia trzeba — jak w poprzedniej zmianie — przeliczyć `applyRoommate`, bo programowe `checked = false` nie emituje `change`.

### 7. Admin: pole przy pokoju

`assets/admin/tabs/AccommodationTab.jsx`, wiersz pokoju: `SelectControl` (nie liczba — legalne są trzy wartości) z opcjami „— nieustawione —" / „1 osoba" / „2 osoby". Help text mówi: nieustawione = jak dziś (towarzysząca opcjonalna), 1 = bez towarzyszącej, 2 = wymaga towarzyszącej, a przy 2 pole współlokatora się nie pokazuje.

**Blokada sprzeczności z globalnym przełącznikiem.** Konfiguracja noclegów ma globalne `companion_enabled`. Gdy jest **wyłączone**, `FormRenderer::renderCompanion` nie renderuje checkboxa, a `SubmissionAssembler::extractCompanion` twardo wymusza `companion = false` (inwariant B5: klientowi się nie ufa). Pokój z `occupancy === 2` byłby wtedy **trwale** `mismatch` — organizator konfiguruje pokoje dwuosobowe, a formularz nie oferuje żadnego, i do tego `companion_required` nie da się spełnić.

Rozwiązanie u źródła, bez osłabiania inwariantu B5:
- Opcja „2 osoby" w `SelectControl` jest **zablokowana** (`disabled`), dopóki `acc.companion_enabled` jest wyłączone, z wyjaśnieniem w help texcie.
- Konfiguracja już zapisana jako sprzeczna (`occupancy === 2` przy wyłączonym przełączniku) jest **traktowana jak „nieustawione"**, czyli zachowuje się jak dziś — plus ostrzeżenie w builderze przy tym pokoju. Czysta domena nie zmienia semantyki `RoomOccupancy`; to warstwa prezentacji/konfiguracji rozstrzyga, jak czytać sprzeczność. Decyzja, gdzie dokładnie ten odczyt siedzi (`AccommodationConfig::fromArray` zerujące `occupancy` przy wyłączonym przełączniku, czy filtr wyżej), zostaje wykonawcy — warunek: `RoomOccupancy::seats` pozostaje jedynym domem reguły fallbacku i nie uczy się o `companion_enabled`.

`assets/admin/ops/accommodationOps.js`: `addRoom` tworzy pokój z `occupancy: 0`; `updateRoom` jest generyczne i nie wymaga zmian. Testy `jest`: `addRoom` daje `occupancy: 0`, `updateRoom` ustawia `occupancy`, i — jeśli blokada sprzeczności wyląduje w ops, a nie w komponencie — osobny test na nią.

`EventConfigController::sanitize` jest generyczne: wartości nie-string i nie-tablica przechodzą gałęzią `else` nietknięte, więc int przeżywa bez zmian w kontrolerze.

### 8. Zgodność w tył i migracja

Brak zmian w bazie. `occupancy` nieobecne w zapisanej mecie → `0` → `RoomOccupancy::seats` zwraca dzisiejsze `companion ? 2 : 1`, wiersz nigdy nie jest `mismatch`, walidacja międzypolowa się nie odpala. Istniejące wydarzenia działają bit w bit jak przed aktualizacją; klucz pojawia się w `_evreg_accommodation` dopiero gdy organizator go ustawi.

Overlay tłumaczeń (B6) tłumaczy **labele** pokojów i pakietów — `occupancy` jest liczbą i go nie dotyczy.

### 9. Promocja z listy rezerwowej na pokój, który zmienił liczbę osób

Reguła z punktu 4 pilnuje formularza publicznego i edycji w adminie. `promoteFromWaitlist` nie przechodzi przez żadną z tych powierzchni: czyta zapisany wiersz i promuje. Jeśli organizator oznaczył pokój jako dwuosobowy **po** tym, jak ktoś zapisał się solo na listę rezerwową, nowa arytmetyka policzyłaby mu cenę ×2 i zapisała `seats = 2` przy `companion = 0` i bez imienia towarzyszącej — a CSV, placeholder `{osoba_towarzyszaca}` w mailu i eksport prywatności dalej mówiłyby „bez osoby towarzyszącej". Zgłoszenie zajęłoby dwa łóżka dla jednej osoby i zapłaciło za dwa.

Dlatego `promoteFromWaitlist` **odmawia** promocji, gdy `companionMismatch()` dla zapisanej pary zwróci kod — dokładnie wzorem istniejącego guardu na typ zgłoszenia zniknięty z konfiguracji, który w tej metodzie już jest i zwraca `invalid_status`. Nowy kod wyniku w `AdminActionResult` plus wpis w mapie komunikatów `RegistrationsScreen::render_notice`, mówiący adminowi **co** jest nie tak (pokój wymaga towarzyszącej / jej nie dopuszcza), żeby wiedział, że ma wejść w edycję odpowiedzi.

Świadomie nie promujemy „jak się da": cicha promocja z rozjechanymi miejscami i ceną jest dokładnie tym anty-celem, który ten design nazywa „rozjazdem ceny i pojemności".

**Uwaga na kolejność:** ten nowy klucz będzie dłuższy niż część istniejących w tablicy `$messages`, więc sniff wyrównania WordPressa zażąda przewyrównania całego bloku — przewyrównaj i puść `phpcbf`, nie dodawaj wyłączenia (ta sama pułapka wystąpiła w poprzedniej zmianie).

### 10. i18n

Nowe stringi: dwa komunikaty błędu walidacji, komunikat „nie ma pasujących pokojów" na formularzu, komunikat admina o odmowie promocji, etykieta pola w adminie, trzy etykiety opcji, help text, ostrzeżenie o sprzeczności z globalnym przełącznikiem. Po implementacji `npm run i18n:pot`.

## Testy

- **Unit** (`tests/Unit/`):
  - `RoomOccupancy::seats` — tablica przypadków: `(0,false)=1`, `(0,true)=2`, `(1,false)=1`, `(1,true)=1`, `(2,false)=2`, `(2,true)=2`.
  - `RoomType::fromArray` — czyta `occupancy`, domyślnie `0`, round-trip przez `toArray`, `SchemaException` dla `3` i dla `-1`.
  - `RoomType::companionMismatch` — tablica przypadków: `occupancy 0` zawsze `null`; `1` z towarzyszącą → `companion_not_allowed`, bez → `null`; `2` bez towarzyszącej → `companion_required`, z → `null`.
  - `AccommodationConfig::seatsFor` / `slotOccupancies` — w tym nieznany pokój i pokój bez ustawionej liczby.
  - `CapacityLimits::seatsForSlot` — slot z ustawioną liczbą, slot bez, slot nieobecny w mapie.
  - `CapacityCalculator` — dwuosobowy pokój zdejmuje 2 miejsca także gdy `companion=false` (config-drift); jednoosobowy zdejmuje 1 także gdy `companion=true`.
  - `PriceCalculator` — cena ×2 dla pokoju dwuosobowego bez towarzyszącej; ×1 dla jednoosobowego z towarzyszącą; nieustawiony pokój zachowuje dzisiejsze `companion ? 2 : 1`.
- **Integration** (`tests/Integration/`):
  - `SubmitHandler::process` (formularz publiczny): dwuosobowy bez towarzyszącej → `SubmitResult` o kodzie `invalid` z błędem `companion_required` pod kluczem pola noclegu i **odtworzonymi** odpowiedziami; jednoosobowy z towarzyszącą → `companion_not_allowed`; pokój bez ustawionej liczby → przechodzi w obu wariantach; w żadnym z odrzuconych przypadków nie powstaje wiersz zgłoszenia.
  - `handle_edit` (admin): zapis starego zgłoszenia w pokoju oznaczonym po fakcie jako dwuosobowy, **bez zmiany noclegu i flagi**, przechodzi (to jest ta decyzja partnera — test musi ją pilnować wprost); ten sam zapis ze **zmienioną** flagą albo zmienionym slotem na niespójną parę → błąd przy polu noclegu i brak zapisu.
  - Render: checkbox towarzyszącej wychodzi **przed** polem noclegu w HTML (asercja na kolejności); wiersz dwuosobowy ma `hidden` i `disabled` gdy checkbox pusty, a jednoosobowy gdy zaznaczony; `data-evreg-occupancy` obecne; wiersz niepasujący **nie** ma `checked`, choć jest bieżącą wartością, a wtedy „Bez noclegu" **jest** zaznaczone i widać notkę; pokój bez ustawionej liczby renderuje się w obu stanach checkboxa; pole współlokatora **nie** wychodzi dla pokoju z `occupancy === 2`, choć ma `roommate_field`; gdy wszystkie wiersze są `mismatch` — widać nowy komunikat o braku pasujących pokojów; `assets/public/form.css` zawiera regułę `.evreg-choice[hidden]` (inaczej atrybut nic nie chowa).
  - `reserve`: dwuosobowy pokój z towarzyszącą zajmuje 2 miejsca w slocie i zapisuje `seats=2` oraz cenę ×2 (dziś to samo wychodzi z flagi — test pilnuje, że teraz wychodzi z pokoju); dwuosobowy pokój na slocie z jednym wolnym miejscem → `accommodation_full` i zero zapisanych wierszy.
  - `editAnswers` honoruje liczbę osób pokoju (regresja na nowy argument `CapacityLimits` i na nowe liczenie `seats` przy wstawianiu bookingu).
  - `promoteFromWaitlist`: solowe zgłoszenie na liście rezerwowej w pokoju oznaczonym po fakcie jako dwuosobowy → **odmowa** z nowym kodem wyniku, status zostaje `waitlist`, cena i booking nietknięte; promocja na pokój spójny z zapisaną flagą przechodzi jak dotąd.
- **jest**: `accommodationOps` — `addRoom` daje `occupancy: 0`; `updateRoom` ustawia `occupancy`.
- **E2E** (Playwright): zaznaczenie towarzyszącej przełącza listę z pokojów jednoosobowych na dwuosobowe i odwrotnie; odznaczenie przy wybranym dwuosobowym przenosi zaznaczenie na „Bez noclegu".

## Ryzyka

- **Dwa niezależne powody zamknięcia slotu.** „Brak miejsc" i „niepasująca liczba osób" spotykają się na jednym atrybucie `disabled` w `form.js`. Jeśli jedna funkcja nadpisze decyzję drugiej, slot odblokuje się wbrew drugiemu powodowi. Dlatego `disabled` musi być sumą powodów, a nie wynikiem ostatniego przebiegu.
- **`CapacityLimits` zyskuje szósty parametr** — 3 miejsca produkcyjne (`reserve`, `promoteFromWaitlist`, `editAnswers`) i kilkanaście testowych. Domyślne `array()` trzyma wywołania bez zmian, ale każde produkcyjne trzeba faktycznie zaktualizować, inaczej liczba osób po cichu nie działa na tej ścieżce. Tak samo **trzy** miejsca wstawiające booking liczą `seats`. To jest najłatwiejsza do przeoczenia część tej zmiany — policz oba zestawy w kodzie, nie na pamięć.
- **Reguła spójności pary żyje w dwóch wywołujących, nie w jednym.** Formularz publiczny stosuje ją zawsze, admin tylko przy zmianie noclegu albo flagi. Łatwo dołożyć ją do jednego i zapomnieć o drugim — oba przypadki mają własny test integracyjny właśnie dlatego.
- **Hidden ≠ disabled.** Opisane w anty-celach; wymaga obu atrybutów i przeniesienia zaznaczenia.
- Cena pokoju dwuosobowego to nadal cena komórki ×2, czyli organizator wpisuje cenę **za osobę**. Jeśli myśli „za pokój", dostanie podwójnie. Pole w inwentarzu nazywa się dziś „Limit / cena za parę" — nazwa zostaje, warto pamiętać przy ewentualnym porządkowaniu etykiet.

## Decyzje podjęte po recenzji wykonawczej

Świeży recenzent przeczytał pierwszą wersję tego dokumentu i wskazał siedem miejsc, w których musiałby zgadywać. Rozstrzygnięcia:

**Partnera** (zmieniają to, co powstanie):
1. **Pokój „2 osoby" przy wyłączonym globalnym przełączniku towarzyszącej** → admin nie pozwala tego ustawić; już zapisana sprzeczność czytana jest jak „nieustawione" + ostrzeżenie w builderze. Inwariant B5 (serwer nie ufa klientowi) zostaje nietknięty — patrz punkt 7.
2. **Blokada edycji starych zgłoszeń** → reguła spójności pary odpala się w adminie tylko wtedy, gdy edycja faktycznie zmienia nocleg albo flagę towarzyszącej. Dlatego reguła nie siedzi w `SubmissionAssembler` — patrz punkt 4.

**Moje** (w służbie już zatwierdzonych celów):
3. **Promocja z listy rezerwowej sprzeczna z liczbą osób** → odmowa, wzorem istniejącego guardu na zniknięty typ zgłoszenia (punkt 9). Alternatywy — promować jak się da albo zostawić 1 miejsce — rozjeżdżają cenę z pojemnością, czyli trafiają w anty-cel.
4. **Pusty wynik filtra** → jeden nowy string, odrębny od komunikatu o zajętości (punkt 5). Przeciążanie „Brak wolnych miejsc noclegowych." skłamałoby o powodzie.
5. **Panel przeglądu** → bez zmian i bez martwego argumentu (Nie-cele). Uświadomienie go o parach to osobna zmiana.
6. **Pole współlokatora przy pokoju dwuosobowym** → nie renderuje się (punkt 5). Drugie łóżko ma imiennie wskazaną osobę.
7. **Reguła CSS `.evreg-choice[hidden]`** → obowiązkowa (punkt 5). Bez niej `hidden` nic nie chowa, bo `.evreg-choice { display: block; }` ją bije — recenzent złapał to, zanim kosztowało rundę poprawek.
8. **Miejsc wstawiających booking jest trzy, nie dwa** → poprawione w punkcie 3 i w Ryzykach.

## Zostawione wykonawcy

- Dokładne brzmienie dwóch komunikatów błędu i tekstów w adminie (ton jak w istniejących mapach `errorMessage` i jak help texty w `AccommodationTab`).
- Kształt odczytu stanu checkboxa w `FormRenderer` po tym, jak globalne `seatsNeeded()` przestaje wystarczać (zostawić i dołożyć, czy zamienić na `companionSubmitted()`) — przy zachowaniu zacieśnienia „event bez towarzyszącej ignoruje odtworzony POST".
- Czy `applyRoomOccupancy` i `applySlotSeats` zostają osobnymi funkcjami w `form.js`, czy scalają się w jedną pętlę — byle `disabled` był sumą powodów.
- Czy `RoomType::seats()` wystarczy, czy przydaje się też `RoomType::requiresCompanion()` / `forbidsCompanion()` jako nazwane predykaty dla walidacji — tylko jeśli czytelność na tym zyska.

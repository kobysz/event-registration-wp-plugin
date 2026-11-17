# Noclegi liczone miejscami na dobę — projekt

**Data:** 2026-11-17
**Status:** spec do zatwierdzenia

**Zastępuje:** `2026-11-17-per-night-accommodation-pools-design.md` (pule per
`doba × typ pokoju` z pakowaniem łóżek). Ten plik nie jest jego kolejną wersją — to inny
model, w którym wymiar „typ pokoju" **wypada z pojemności**, a wybór noclegu staje się
wielokrotnym wyborem dób.

## Intencja i dlaczego

Dwa niezależne fakty, które dziś kłamią.

**Formularz pyta o złą rzecz.** Uczestnik wybiera **jedną** pozycję z listy
`pakiet × pokój`, więc „dwie doby" musi istnieć jako osobny pakiet obok „doby 18/19"
i „doby 19/20". Pierwotny opis wydarzenia był inny i prostszy: *„Nocleg z osobą
towarzyszącą? tak/nie, potem do wyboru daty (może zaznaczyć dwie): 18.12./19.12.,
19.12./20.12."* To jest **wielokrotny wybór dób**, nie wybór pakietu. Pakiet był
kształtem wtyczki narzuconym na potrzebę.

**Pojemność należy do złego wymiaru.** Limit siedzi na parze `(pakiet × pokój)`, więc
każda kombinacja dób ma rozłączną pulę: 10 piątkowych jedynek trzeba ręcznie podzielić
między „tylko piątek" i „18–20", zgadując proporcje, i samemu pilnować, żeby suma się
trzymała. Rezerwacja dwudobowa nie zdejmuje nic z puli pojedynczej doby, choć fizycznie
zajmuje miejsce w obie noce. Tego **nie da się skonfigurować poprawnie** — to brak
w modelu, nie pomyłka w ustawieniach.

Zmiana: **doba jest jedynym wymiarem pojemności i jedyną rzeczą, którą wybiera
uczestnik.** Organizator wpisuje, ile **miejsc** ma na każdą dobę; rezerwacja zdejmuje
jedno miejsce z każdej zaznaczonej doby (dwa, gdy jest osoba towarzysząca). Jakie to
będą pokoje, rozstrzyga operator wydarzenia wedle własnych procedur i nie musi tego
nikomu publikować.

### Przykład prowadzący

Doby `18/19` i `19/20` grudnia 2026, miejsca: 72 i 65. Uczestnik zaznacza obie doby
i osobę towarzyszącą → zdejmuje **dwa miejsca z 18/19 i dwa z 19/20**, płaci
`(cena 18/19 + cena 19/20) × 2`. Uczestnik bez towarzyszącej zaznaczający jedną dobę
zdejmuje z niej jedno miejsce.

### Czego ten model celowo nie gwarantuje

Jedna liczba miejsc nie wie nic o pokojach, więc 72 miejsca wpuszczą 36 par, choć
dwójek fizycznie jest 31 — pięć par bez wspólnego pokoju. Dlatego doba ma **drugi,
opcjonalny limit: ile zgłoszeń z osobą towarzyszącą** przyjmuje (u zamawiającego 31).
Para liczy się do obu pul, singiel tylko do pierwszej. Pusty limit par = bez limitu,
z ryzykiem po stronie operatora.

To jest cała ochrona, jakiej ten model daje: dwie liczby na dobę. Gwarancji
„para zawsze dostanie pokój", którą dawało liczenie pokoi z pakowaniem łóżek,
**świadomie nie ma** — kosztowała wymiar typu pokoju w konfiguracji, w pojemności,
w cenie, w renderze i w lustrze JS.

## Cele

1. Doba jest jedynym wymiarem pojemności: **miejsca na dobę** + opcjonalny **limit par**.
2. Uczestnik **zaznacza doby** (wielokrotny wybór), a nie wybiera pakiet.
3. Rezerwacja zdejmuje swoje miejsca z **każdej** zaznaczonej doby; cena to suma cen
   zaznaczonych dób × liczba miejsc.
4. Wyczerpana doba jest widocznie zamknięta na każdej powierzchni naraz: render,
   `reserve`, `editAnswers`, `promoteFromWaitlist`, panel obłożenia.
5. Zaznaczenie zawierające dobę bez miejsc jest **odrzucane w całości**, z komunikatem
   wskazującym tę dobę — nigdy nie przyjmujemy części po cichu.
6. Istniejące eventy działają **bez jednej zmiany w zachowaniu**.

## Dwa tryby noclegu, przełączane na poziomie eventu

`_evreg_accommodation` zyskuje `mode`: `classic` (domyślne, także gdy klucz nie istnieje)
albo `nights`.

- **`classic`** — dzisiejszy model: pakiety, typy pokoi, inwentarz `(pakiet × pokój)`
  z pojemnością i ceną, osoba towarzysząca z regułami `occupancy`. **Kod tej ścieżki
  nie jest zmieniany.**
- **`nights`** — nowy model: doby z ceną i dwoma limitami, wielokrotny wybór, zero typów
  pokoi i zero pakietów.

Tryb jest przełącznikiem eventu, nie właściwością pakietu. Powód jest konkretny:
zgodność w tył staje się **brakiem zmian**, a nie warstwą, którą trzeba by testować
w każdej ścieżce transakcyjnej. Każde rozwidlenie w kodzie to jedno `if` na wejściu,
nie splot dwóch semantyk w jednym wyrażeniu.

Przełączenie trybu na evencie, który ma już rezerwacje, jest **blokowane** (admin nie
pozwala): zapisane bookingi klasyczne nie mają dób, a dobowe nie mają pakietu i pokoju,
więc pojemność po przełączeniu czytałaby pustkę. Event zmienia tryb, dopóki nie ma
zgłoszeń z noclegiem — i tylko wtedy.

## Model konfiguracji (tryb `nights`)

```php
array(
    'mode'           => 'nights',
    'nights'         => array(
        array(
            'key'           => 'n1819',
            'label'         => '18/19 grudnia',
            'price'         => 150.0,   // cena ZA MIEJSCE za tę dobę
            'seats'         => 72,      // miejsca razem; 0 = brak miejsc (zamknięte)
            'companion_cap' => 31,      // maks. zgłoszeń z towarzyszącą; '' = bez limitu
        ),
        array( 'key' => 'n1920', 'label' => '19/20 grudnia', 'price' => 150.0, 'seats' => 65, 'companion_cap' => 28 ),
    ),
    'roommate_field' => true,  // pytaj o preferowanego współlokatora (gdy brak towarzyszącej)
    // companion_enabled, companion_counts_event, allow_none — jak dziś
    // packages / rooms / inventory — nieużywane w tym trybie
)
```

**Dwie różne konwencje pustki, obie jawne, bo łatwo je pomylić:**

- `seats`: `0` znaczy **brak miejsc** (doba zamknięta). Tak samo jak dzisiejsza
  pojemność slotu — konwencji nie zmieniamy.
- `companion_cap`: **pusty string albo brak klucza** znaczy **bez limitu**; liczba
  (w tym `0`) znaczy limit, więc `0` zamyka dobę dla par, zostawiając ją singlom.

Walidacja (`SchemaException`, bo UI tego nie wyprodukuje): pusty lub zduplikowany klucz
doby, ujemne `seats`, `price` lub `companion_cap`.

## Co wybiera uczestnik

1. **Osoba towarzysząca** — checkbox + imię i nazwisko (dziś działa, bez zmian).
2. **Doby** — checkbox per doba, wielokrotny wybór. Zero zaznaczeń = bez noclegu
   (wymaga `allow_none`; gdy nocleg jest obowiązkowy, walidacja żąda choć jednej doby).
3. **Preferowany współlokator** — opcjonalne pole tekstowe, pokazywane tylko gdy
   towarzysząca NIE jest zaznaczona i `roommate_field` jest włączone. W tym trybie ma
   więcej sensu niż dziś: operator kwateruje ręcznie, więc preferencja jest dla niego
   realną wskazówką.

Typu pokoju uczestnik **nie wybiera** — stąd jedna cena za miejsce za dobę. To była
świadoma decyzja zamawiającego: różnicowanie ceny per typ pokoju wymagałoby wyboru,
którego w tym modelu nie ma.

### Zajętość i bramka

Dla rezerwacji potrzebującej `s` miejsc (`s = companion ? 2 : 1`) i zbioru zaznaczonych
dób: dla **każdej** doby musi zachodzić

```
zajęte_miejsca + s <= seats
oraz, gdy companion i companion_cap jest ustawiony:
zajęte_pary + 1 <= companion_cap
```

Pierwsza doba, która nie przechodzi, przerywa sprawdzanie i **odrzuca całe zaznaczenie**
(cel 5). Komunikat wskazuje tę dobę po jej etykiecie — inaczej człowiek widzi „brak
miejsc" i nie wie, czego odznaczyć.

Zajęte pary per doba = liczba wierszy z `seats = 2`; w tym trybie `seats` jest zawsze
1 albo 2, więc `seats = 2` ⟺ osoba towarzysząca. Osobnej kolumny nie potrzeba.

Limit globalny eventu i limit typu zgłoszenia działają **przed** bramką dobową,
bez zmian, wraz z dzisiejszą regułą `companion_counts_event`.

## Cena

`cena = Σ price(doba) × s` po zaznaczonych dobach. Bramkowanie ceny przyznaniem noclegu
(B7) zostaje: cena noclegu liczy się tylko dla dób faktycznie przyznanych — a skoro
zaznaczenie jest przyjmowane w całości albo odrzucane, przyznane są wszystkie albo żadna.

## Model bazy

`Migrations::DB_VERSION` → `7`. Jedna nowa tabela; istniejące **bez zmian**.

```sql
CREATE TABLE {prefix}evreg_booking_nights (
    id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
    registration_id bigint(20) unsigned NOT NULL,
    night_key varchar(64) NOT NULL,
    night_label varchar(191) NOT NULL DEFAULT '',
    seats tinyint unsigned NOT NULL DEFAULT 1,
    price decimal(10,2) NOT NULL DEFAULT 0,
    PRIMARY KEY  (id),
    UNIQUE KEY uniq_registration_night (registration_id, night_key),
    KEY idx_night (night_key)
) ENGINE=InnoDB
```

- **Wiersze, nie JSON:** zajętość musi dać się policzyć jednym `GROUP BY` pod lockiem
  eventu, tą samą drogą co dziś.
- **Klucz na `registration_id`, nie na bookingu.** W trybie `nights` nie ma wiersza
  w `evreg_accommodation_bookings` — nie ma pakietu ani pokoju, które by go wypełniły.
  Doby **są** rezerwacją noclegu. `UNIQUE(registration_id, night_key)` daje idempotencję
  zapisu.
- **`night_label` i `price` to snapshoty**, jak `package_label`/`room_label` od 0.1.14:
  zmiana nazwy albo ceny doby nie może przepisać historii ani przeliczyć zajętości w tył.
- Eraser prywatności **nie tyka** tych wierszy: nie ma w nich danych osobowych, a ich
  usunięcie rozjechałoby zajętość (ta sama zasada, dla której eraser zostawia status,
  typ, cenę i daty). Twarde usunięcie zgłoszenia je kasuje.
- `Uninstaller::TABLES` dostaje nową nazwę (cztery tabele → pięć).
- `roommate_pref` zostaje tam, gdzie jest dziś — w `evreg_accommodation_bookings` go nie
  będzie, więc w trybie `nights` wędruje na zgłoszenie (kolumna `roommate_pref` na
  `evreg_registrations`, `varchar(191) NULL`). To druga i ostatnia zmiana schematu.

## Domena

Czysto, bez WordPressa, w `src/Domain/Accommodation/` i `src/Domain/Capacity/`:

- **`Night`** (nowy): `key`, `label`, `price`, `seats`, `?int $companionCap`,
  `fromArray`, `toArray`.
- **`NightsConfig`** (nowy): lista dób + `mode`, `allowNone`, `companionEnabled`,
  `companionCountsEvent`, `roommateField`; `night( string $key ): ?Night`,
  `nightLabel()`, `prices()`, `seatCaps()`, `companionCaps()`. Osobny obiekt zamiast
  rozpychania `AccommodationConfig` — tamten obsługuje tryb `classic` i **nie jest
  zmieniany**.
- **`NightSelection`** (nowy): lista zaznaczonych kluczy dób, znormalizowana
  (unikalne, w kolejności konfiguracji, nieznane odfiltrowane), `isEmpty()`.
- **`NightCapacity`** (nowy, czysty): jedyny dom bramki —
  `blockingNight( NightsConfig $config, NightOccupancy $taken, NightSelection $selection, bool $companion ): ?string`
  zwraca klucz pierwszej doby bez miejsc albo `null`. Nazwa doby do komunikatu jest
  sprawą prezentacji, nie domeny.
- **`NightOccupancy`** (nowy): zajętość per doba, dwie liczby —
  `seatsFor( string $night ): int`, `pairsFor( string $night ): int`.
- **`NightPrice`** (nowy, czysty): `total( NightsConfig $config, NightSelection $selection, int $seats ): float`.

`CapacityCalculator`, `CapacityLimits`, `OccupancySnapshot`, `SlotAvailability`,
`RoomOccupancy`, `RoomType`, `Package`, `InventoryItem` i `PriceCalculator`
**zostają nietknięte** — służą trybowi `classic`.

## Ścieżki transakcyjne

`reserve`, `editAnswers`, `promoteFromWaitlist` w `ReservationService` dostają jedno
rozwidlenie na wejściu: `nights` albo `classic`. Gałąź `classic` to dzisiejszy kod.
Gałąź `nights`:

1. `lockEvent($event_id)` **przed** pierwszym odczytem zajętości (inwariant 3A, bez
   zmian),
2. odczyt zajętości dobowej (`NightOccupancy`) — w `editAnswers` i
   `promoteFromWaitlist` z wykluczeniem samego zgłoszenia,
3. limit globalny i limit typu jak dziś, potem `NightCapacity::blockingNight`,
4. przy przejściu: wstawienie wierszy dób (ze `seats` i snapshotem `price`/`label`)
   w tej samej transakcji; cena zgłoszenia z `NightPrice`,
5. przy blokadzie: `reserve` → ROLLBACK + `ReservationResult::accommodationFull()`
   z kluczem blokującej doby; `editAnswers` → odrzucenie edycji; `promoteFromWaitlist`
   → awans bez noclegu i `promoted_no_accommodation`.

Hooki cyklu życia dalej emitowane **wyłącznie po COMMIT**.

`editAnswers`: gdy zbiór dób i flaga towarzyszącej są **bez zmian**, wiersze dób,
`seats` i cena zostają nietknięte i bramka dobowa jest pomijana — ta sama reguła, którą
B-occupancy wprowadziło dla slotu, i z tego samego powodu: niezwiązana edycja
odpowiedzi nie może przeliczyć ceny według bieżącego cennika. Zmiana zbioru dób kasuje
wiersze i wstawia nowe **pod lockiem**.

## Render publiczny i lustro

Pole noclegu w trybie `nights` renderuje **checkboxy** zamiast radio:

- `name="evreg_field[<klucz>][nights][]"`, wartość = klucz doby. Pola formularza zostają
  pod namespace'em `evreg_field[...]` (inwariant 3B: WordPress czyta publiczne
  query-vars także z `$_POST`).
- Każdy wiersz niesie `data-evreg-night-seats-left` i `data-evreg-night-pairs-left`
  (puste = bez limitu par).
- Doba bez miejsc: `disabled` + dopisek `(brak miejsc)`. Doba z miejscami, ale
  wyczerpanym limitem par: `disabled` **tylko gdy towarzysząca jest zaznaczona**,
  z dopiskiem `(brak miejsc dla dwóch osób)`. Odznaczenie towarzyszącej otwiera ją
  z powrotem — to robi lustro w `form.js`.
- `disabled` + `checked` **nie jest** tu pułapką jak przy radio (checkbox nie wysyła
  wartości, a pusty zbiór to legalne „bez noclegu"), ale zaznaczenie z wyłączonej doby
  jest **jawnie zdejmowane** z notką, żeby nikt nie wysłał formularza wierząc, że ma
  tę dobę.
- Cena per doba nie jest pokazywana na formularzu — tak jak dziś nie są pokazywane ceny
  pozycji inwentarza. Poza zakresem.

Lustro w `assets/public/form.js`: nowa funkcja czytająca stan checkboxa towarzyszącej
i przełączająca doby (`seats_left < seats_needed` albo `pairs_left < 1` przy
towarzyszącej). Lustro `classic` (`seatsFor`, mismatch po `occupancy`) **zostaje bez
zmian** — obsługuje drugi tryb.

## Admin

- **Zakładka „Noclegi"**: na górze przełącznik trybu (`classic` / `nights`), zablokowany
  z komunikatem, gdy event ma już zgłoszenia z noclegiem.
- **Tryb `nights`**: lista dób (dodaj / usuń / klucz / etykieta / cena / miejsca /
  limit par), z jawnym podpisem przy limicie par („puste = bez limitu"). Sekcje
  pakietów, pokoi i inwentarza są wtedy ukryte, nie wyszarzone — w tym trybie nie
  znaczą nic.
- **Ostrzeżenie** przy usuwaniu doby, do której odwołują się rezerwacje.
- **Edycja zgłoszenia w adminie**: checkboxy dób, ta sama walidacja i ten sam
  `form.js`.
- **Detal zgłoszenia**: lista dób z zapisanych etykiet i cen.
- **Panel obłożenia**: tabela „Noclegi per doba" — miejsca zajęte / limit / wolne oraz
  pary / limit par. W trybie `classic` panel zostaje dzisiejszy.
- **Eksport CSV**: kolumna „Doby" (etykiety z wierszy, rozdzielone przecinkiem)
  zasilana bulk odczytem, bez N+1.
- **i18n**: etykiety dób tłumaczalne per język jak pakiety i pokoje (B6) — overlay
  `_evreg_i18n[lang].accommodation` zyskuje bucket `nights`. Tylko etykiety; klucze,
  ceny i limity nietknięte.
- **Mail**: placeholder `{noclegi}` z listą etykiet dób. `{nocleg}` w trybie `classic`
  bez zmian.

## Nie-cele

- Gwarancja, że para dostanie wspólny pokój (patrz „Czego ten model celowo nie
  gwarantuje"). Chroni limit par, nie arytmetyka pokoi.
- Wybór typu pokoju przez uczestnika i cena per typ pokoju w trybie `nights`.
- Przydział konkretnych pokoi, lista kwaterunkowa z numerami.
- Migracja eventu z trybu `classic` do `nights` z zachowaniem rezerwacji.
- Doby niecałkowite, godziny przyjazdu, doba jako zakres dat z kalendarzem. Doba to
  klucz i etykieta; porządek bierze się z kolejności w konfiguracji.
- Cena zależna od liczby zaznaczonych dób (rabat za dwie doby).
- Doby wspólne między wydarzeniami.

## Anty-cele

- **Przyjęcie części zaznaczenia po cichu.** Doba bez miejsc odrzuca całość
  z komunikatem. Ciche przyjęcie bez tego, o co ktoś prosił, kosztowało już wydanie
  (0.1.5) i osobną gałąź (B-slots).
- **Dotknięcie ścieżki `classic`.** Zero zmian w `AccommodationConfig`,
  `CapacityCalculator`, `PriceCalculator`, `RoomType`, `SlotAvailability` i w ich
  testach. Jeśli task to robi, jest źle rozcięty.
- **Jakikolwiek odczyt zajętości przed `lockEvent()`.**
- **Przeliczanie zapisanej rezerwacji z bieżącej konfiguracji** — etykiety i ceny są
  snapshotowane na wierszach dób.
- **Druga kopia arytmetyki.** `NightCapacity` i `NightPrice` to jedyne domy swoich
  reguł; `form.js` jest ich lustrem i niczym więcej.
- **Przełączenie trybu pod istniejącymi rezerwacjami.**
- **Zmiana namespace'u pól formularza.** `evreg_field[...]` zostaje (inwariant 3B).

## Ograniczenia

- `src/Domain/**` bez WordPressa i bez `$wpdb` (`DomainPurityTest`).
- PHPStan 6 bez obniżania, `phpcs` czysto, wszystkie stringi przez i18n.
- Mutacje konfiguracji w `assets/admin/ops/accommodationOps.js`, testowane jest.
- `dbDelta`, idempotentnie; `Migrations::DB_VERSION` 6 → 7.
- Domena zwraca kody (`klucz blokującej doby`), prezentacja robi z nich zdania.

## Podejścia

**1. Pule per `(doba × typ pokoju)` z pakowaniem łóżek** — poprzedni spec, trzy wersje.
Daje gwarancję „para dostanie pokój", ale ciągnie wymiar typu pokoju przez pojemność,
cenę, render, lustro i admina; trzy wersje projektu wywaliły się na jego arytmetyce.
Odrzucone po rozmowie zamawiającego z PM-em: operator i tak rozdziela pokoje sam i nie
chce publikować swojego zapasu.

**2. Miejsca na dobę, wielokrotny wybór dób — WYBRANE.** Pojemność to jedna liczba na
dobę plus opcjonalny limit par. Zero typów pokoi, zero pakietów, zero pakowania.
Wymaga nowego kształtu selekcji i wielokrotnego wyboru na formularzu, co dotyka renderu,
submitu, ceny, edycji w adminie, CSV i maila — ale każda z tych zmian jest dodaniem
drugiej gałęzi obok nietkniętej pierwszej, nie przeplotem dwóch semantyk.

**3. Miejsca na dobę przy zachowanym wyborze pakietu** (pakiet dalej jedną pozycją,
tylko limit przeniesiony na doby). Mniejsza zmiana formularza, ale zostawia pakiety na
kombinacje dób, czyli ten konfiguracyjny bałagan, od którego wyszliśmy, i rozmija się
z pierwotnym opisem wydarzenia. Odrzucone.

## Co zostaje builderowi

- Nazwy metod i klas wg konwencji repo; czy `NightOccupancy` to obiekt wartości, czy
  dwie mapy w jednym.
- Kolejność tasków i granice commitów.
- Czy pole dób w trybie `nights` jest nowym `FieldType`, czy tym samym
  `FieldType::Accommodation` renderowanym inaczej wedle trybu — z tym, że **zapisane
  schematy nie mogą wymagać zmiany**, a `SchemaAssembler` dalej scala cztery meta
  w jedną `FormSchema`.
- Kształt testów, pod warunkiem że pokrywają: przykład prowadzący; dwie doby, z których
  **druga** jest pełna (nie pierwsza); limit par wyczerpany przy wolnych miejscach;
  `companion_cap` równe `0` kontra puste; odrzucenie całości z kluczem blokującej doby;
  edycję niezmieniającą zbioru dób (zachowuje cenę i wiersze); awans z waitlisty do
  pełnej doby; blokadę przełączenia trybu pod istniejącymi rezerwacjami; `lockEvent`
  przed każdym odczytem zajętości; oraz **zestaw testów trybu `classic`
  przechodzący bez jednej zmiany**.
- Dopisek do `CLAUDE.md`: dwa tryby noclegu i przełącznik na poziomie eventu, doby jako
  jedyny wymiar pojemności w trybie `nights`, dwie konwencje pustki (`seats = 0`
  zamyka, `companion_cap` puste = bez limitu), snapshot etykiety i ceny na wierszu doby,
  tabela `booking_nights`, db v7. Wpis do `CHANGELOG.md` w `## [Unreleased]`.

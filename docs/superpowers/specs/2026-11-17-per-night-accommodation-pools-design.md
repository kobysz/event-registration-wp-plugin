# Pule noclegowe per doba — projekt

**Data:** 2026-11-17
**Status:** spec do zatwierdzenia

## Intencja i dlaczego

Dziś limit miejsc noclegowych jest przypisany do **pozycji inwentarza**, czyli pary
`(pakiet × pokój)`. `Package` nie wie nic o dobach — to tylko klucz i etykieta. Slot to
literalny string `"pakiet|pokój"`, a zajętość liczy jedno
`GROUP BY CONCAT(package_key, '|', room_type_key)`.

Skutek: każda kombinacja dób ma **rozłączną** pulę. Organizator, który fizycznie ma
10 jedynek na noc 18/19, musi ten stan ręcznie podzielić między pakiet „tylko 18/19"
i pakiet „18–20", z góry zgadując proporcje, i sam pilnować, żeby suma się trzymała.
Pule nie rozmawiają ze sobą, więc **nie da się tego skonfigurować poprawnie** — to brak
w modelu, nie pomyłka w ustawieniach. Rezerwacja na dwie doby nie zdejmuje nic z puli
pojedynczej doby, choć fizycznie zajmuje ten pokój w obie noce.

Zmiana: doba staje się pierwszoklasowa. Limit mieszka na parze `(doba × typ pokoju)`,
pakiet jest zbiorem dób, a rezerwacja zdejmuje jeden pokój z puli **każdej** doby swojego
pakietu.

### Przykład prowadzący (wydarzenie zamawiającego)

Doby: `18/19` i `19/20` grudnia 2026. Limity ustawiane osobno dla każdej doby:
jedynki i dwójki per doba. Pakiety: `{18/19}`, `{19/20}`, `{18/19, 19/20}` — każdy
dostępny w wariancie pokoju jedno- i dwuosobowego, i tam (jak dziś) siedzi cena.

Uczestnik wybiera „dwie doby + pokój jednoosobowy" → zdejmuje **jedną jedynkę z puli
18/19 oraz jedną jedynkę z puli 19/20**. Jeśli którakolwiek z tych dwóch pul jest
wyczerpana, pozycja jest zamknięta.

## Cele

1. Limit noclegów definiowany per `(doba × typ pokoju)`, niezależnie od pakietów.
2. Pakiet deklaruje listę dób; rezerwacja zjada jeden pokój z każdej z nich.
3. Dostępność pozycji `(pakiet × pokój)` = AND po dobach pakietu; „ile zostało" =
   minimum po dobach.
4. Wyczerpana doba zamyka pozycję **na każdej powierzchni naraz** — render publiczny,
   `reserve`, `editAnswers`, `promoteFromWaitlist` — bez cichego przyjęcia bez łóżka.
5. Zero regresji dla eventów skonfigurowanych po staremu.

## Nie-cele

- **Doby na formularzu publicznym** (uczestnik zaznacza doby, pakiet znika z UI). To
  osobna, większa zmiana (render, submit, `AccommodationSelection`, cena, admin, CSV,
  mail, e2e). Stoi na pulach dobowych, więc ten projekt jest jej warunkiem wstępnym —
  ale nie wchodzi tu. Osobna pozycja backlogu.
- Cena per doba ani rabat za wiele dób. Cena zostaje na pozycji `(pakiet × pokój)`,
  wpisywana jako pełna cena pakietu — jak dziś.
- Przydział konkretnych, fizycznych pokoi (lista kwaterunkowa, numery pokoi,
  dosypywanie do pokoju współlokatora). Pula to liczba, nie zbiór identyfikowalnych pokoi.
- Doby wspólne między wydarzeniami. Pule są per event, jak cała reszta konfiguracji.
- Pojęcie „bez limitu". `0` dalej znaczy „brak miejsc", jak teraz.

## Anty-cele (to by było porażką, nawet gdyby działało)

- **Dwa żywe limity na jedną pulę.** Gdyby dla pakietu z dobami nadal obowiązywał stary
  limit pozycji `(pakiet × pokój)`, wróciłoby ręczne dzielenie stanu — dokładnie ból,
  który ta zmiana usuwa.
- **Doliczanie dób z konfiguracji w momencie liczenia.** Zmiana nazwy albo usunięcie
  pakietu przeliczyłoby zajętość w tył i odcięło starym zgłoszeniom ich doby. Ta lekcja
  jest już opłacona w 0.1.14 (snapshot etykiet na bookingu).
- **Jakikolwiek odczyt zajętości przed `lockEvent()`.** Inwariant 3A obowiązuje bez
  zmian: lock wiersza `evreg_locks` poprzedza pierwszy COUNT, w każdej ze ścieżek.
- **Przyjęcie zgłoszenia bez noclegu, o który prosiło, bez powiedzenia tego wprost.**
  Zachowanie z B-slots zostaje: pełna pula → ROLLBACK i `ReservationResult::accommodationFull()`,
  a nie cichy zapis bez bookingu.
- **Mnożenie przez liczbę osób po stronie organizatora.** Jeśli pula liczyłaby miejsca,
  31 dwójek trzeba by wpisać jako 62. Pula liczy pokoje.

## Ograniczenia

- `src/Domain/**` bez WordPressa i bez `$wpdb` (pilnuje `DomainPurityTest`). Cała
  arytmetyka pul idzie w czystą domenę.
- PHPStan poziom 6 bez obniżania, `phpcs` czysto pod `phpcs.xml.dist`.
- Wszystkie stringi UI przez i18n, text domain `event-registration`.
- Logika mutacji konfiguracji w adminie w czystych modułach `assets/admin/ops/*`
  (tu: `accommodationOps.js`) testowanych `jest`. Komponenty cienkie.
- Migracja bazy przez `dbDelta`, idempotentna, bieżąca `Migrations::DB_VERSION = 6`
  → nowa wartość `7`.
- Companion zostaje bez zmian: pokój dwuosobowy wymaga osoby towarzyszącej,
  jednoosobowy jej zabrania (`RoomType::companionMismatch`), a `RoomOccupancy::seats`
  pozostaje jedynym domem reguły fallbacku dla **ceny**.

## Podejścia

Rozważone trzy.

**1. Zostawić jak jest, dzielić stan ręcznie w konfiguracji.** Zero kodu. Odrzucone:
model tego nie wyraża. Niezależnie od wpisanych liczb rezerwacja na dwie doby nie
zdejmie nic z puli pojedynczej doby, więc albo się przesprzedaje, albo trzyma martwy
zapas. Zamawiający nazwał ten ból wprost.

**2. Pule dobowe pod zachowanym wyborem pakietu — WYBRANE.** Doba zyskuje własny byt
i limit per `(doba × pokój)`; pakiet deklaruje swoje doby; uczestnik widzi na formularzu
to samo co dziś (pozycja = pakiet × pokój). Rusza konfigurację noclegów, liczenie
zajętości, dostępność, wiersz bookingu, admina (konfiguracja + panel obłożenia) oraz
jedną kolumnę eksportu. Formularz, cena i maile — bez zmian w zachowaniu.

**3. Doby wprost na formularzu** (wybór typu pokoju + zaznaczenie dób, pakiet znika z UI).
Koncepcyjnie najprostszy model dla uczestnika i zgodny z pierwotnym opisem wydarzenia,
ale rusza wszystko: render, submit, `AccommodationSelection`, kardynalność bookingu,
cenę, admina, edycję, CSV, mail, e2e.

Zamawiający wybrał **2**. Argument rozstrzygający: podejście 3 i tak stoi na pulach
dobowych, więc 2 jest jego warunkiem wstępnym — można je zrobić później, nie cofając
niczego. Koszt 2: pakiet nadal definiuje się na każdą kombinację dób (tu trzy).

## Model konfiguracji

Meta `_evreg_accommodation` zyskuje dwa klucze; istniejące zostają nietknięte.

```php
array(
    'nights'          => array(
        array( 'key' => 'n1819', 'label' => '18/19 grudnia' ),
        array( 'key' => 'n1920', 'label' => '19/20 grudnia' ),
    ),
    'rooms'           => array( /* bez zmian: key, label, occupancy */ ),
    'packages'        => array(
        // NOWE pole `nights` — lista kluczy dób. Pusta/brak = tryb zgodności (niżej).
        array( 'key' => 'p18', 'label' => 'Doba 18/19',  'nights' => array( 'n1819' ) ),
        array( 'key' => 'p19', 'label' => 'Doba 19/20',  'nights' => array( 'n1920' ) ),
        array( 'key' => 'p2',  'label' => 'Dwie doby',   'nights' => array( 'n1819', 'n1920' ) ),
    ),
    'inventory'       => array(
        // Bez zmian strukturalnie. `capacity` PRZESTAJE być limitem dla pakietu,
        // który ma doby — zostaje cena. Liczba jest ZACHOWANA (uśpiona), nie zerowana:
        // patrz „Uśpiona pojemność pozycji".
        array( 'package' => 'p2', 'room' => 'single', 'capacity' => 10, 'price' => 400.0 ),
    ),
    'night_inventory' => array(
        // NOWE: limit pokoi per doba × typ pokoju.
        array( 'night' => 'n1819', 'room' => 'single', 'capacity' => 10 ),
        array( 'night' => 'n1819', 'room' => 'double', 'capacity' => 31 ),
        array( 'night' => 'n1920', 'room' => 'single', 'capacity' => 22 ),
        array( 'night' => 'n1920', 'room' => 'double', 'capacity' => 43 ),
    ),
    // allow_none, companion_enabled, companion_counts_event — bez zmian.
)
```

**Walidacja w `AccommodationConfig::fromArray` (rzuca `SchemaException`, jak dziś):**
doba wymaga niepustego klucza; zduplikowany klucz doby; `packages[].nights` wskazujące
na nieznaną dobę; `night_inventory` wskazujące na nieznaną dobę albo nieznany pokój;
zduplikowana para `(doba, pokój)`; ujemna pojemność; **pozycja inwentarza łącząca pakiet
z dobami z pokojem, który ma `roommate_field`** (patrz „Pokoje sprzedawane na łóżka").

Czego walidacja **nie** odrzuca: niezerowej `capacity` na pozycji pakietu z dobami.
Ta liczba jest uśpiona, nie błędna — patrz „Uśpiona pojemność pozycji".

### Tryb zgodności

Pakiet, którego `nights` jest puste albo nieobecne, działa **dokładnie jak dziś**: limit
z `inventory[(pakiet, pokój)].capacity`, pula liczona w miejscach (`SUM(seats)`).
Istniejące instalacje nie zauważają zmiany i nie wymagają migracji danych. Jeden event
może mieszać pakiety z dobami i bez — decyzja jest per pakiet, nigdy globalna.

To jest jedyne miejsce, gdzie dwie semantyki współistnieją, i jest tak celowo: inaczej
zmiana wymagałaby przeliczenia stanu na wszystkich stronach, na których wtyczka już stoi.

### Jednostka puli

Pula dobowa liczy **pokoje**: jedna rezerwacja = jeden pokój z każdej swojej doby,
niezależnie od tego, czy śpi w nim jedna osoba czy dwie. `seats` przestaje napędzać pulę
dobową; zostaje przy cenie (mnożnik ×2 dla pokoju dwuosobowego) i przy głowie w limicie
wydarzenia (`companion_counts_event`). W trybie zgodności `seats` dalej napędza pulę —
bez zmian.

Konsekwencja dla komunikatów: dopisek „(za mało miejsc)" (pula miała 1 miejsce, a
towarzysząca potrzebuje 2) ma sens **tylko w trybie zgodności**. Przy pulach dobowych
pozycja jest albo otwarta, albo „(brak miejsc)" — nie istnieje stan „jest, ale za mało".

### Pokoje sprzedawane na łóżka

Pokój z włączonym `roommate_field` sprzedaje **łóżka, nie pokoje**: dwie osoby rejestrują
się osobno, każda zdejmuje jedno miejsce z puli i wpisuje preferencję współlokatora,
a wtyczka **nie paruje ich transakcyjnie** (`roommate_pref` to pole preferencji, nie
wiązanie). Pula licząca pokoje nie umie tego wyrazić: przy płaskim „jedna rezerwacja =
jeden pokój" dziesięć dwójek sprzedawanych na łóżka przyjęłoby dziesięć osób, nie
dwadzieścia — cicha przesprzedaż o połowę.

Dlatego **pokój z `roommate_field` nie może brać udziału w pulach dobowych**:
`AccommodationConfig::fromArray` rzuca `SchemaException`, a admin blokuje to połączenie
w momencie ustawiania. Pokoje bez tego pola działają normalnie, a eventy sprzedające
łóżka zostają w trybie zgodności — bez zmian w zachowaniu. Świadomy koszt: tej jednej
kombinacji po prostu nie da się ustawić, i to jest lepsze niż jednostka puli zależna
od typu pokoju.

### Uśpiona pojemność pozycji

Gdy pakiet ma doby, `inventory[(pakiet, pokój)].capacity` **przestaje być limitem, ale
zostaje zapisana** — admin jej nie zeruje ani nie usuwa przy zapisie. Powód: to liczba,
którą wpisał organizator, i odebranie jej przy włączeniu dób zniszczyłoby stan, do
którego może chcieć wrócić.

Stąd dwa wymagania:

- Admin wyszarza komórkę z notką „limit idzie z dób" — wartość jest widoczna, ale
  wprost oznaczona jako nieobowiązująca. Liczba nie może udawać, że działa.
- **Zdjęcie dób z pakietu reaktywuje tę liczbę**, więc admin musi w momencie przełączania
  powiedzieć wprost, jaki limit wróci do gry (łącznie z tym, że `0` znaczy „brak miejsc",
  czyli zamknięcie pozycji). Przełącznik, który cicho zamyka sprzedaż, byłby pułapką.

### Prawda o podaży i popycie

Nośny inwariant, bo jest warunkiem bramki we wszystkich trzech ścieżkach transakcyjnych:

> **Wiersze dób bookingu są prawdą o popycie. Konfiguracja jest prawdą o podaży i o tym,
> czym bramkować nowy popyt.**

Czyli: ile dana rezerwacja zjada — mówią jej zapisane wiersze dób (nigdy bieżąca
konfiguracja pakietu). Czy nowa rezerwacja albo zmiana slotu przechodzi przez pule
dobowe czy slotowe — mówi bieżąca konfiguracja pakietu (`usesNightPools`).

Dwa rozjazdy, które z tego wynikają, i co z nimi:

- **Rezerwacja bez wierszy dób w pakiecie, który dziś ma doby** — nie zjada nic z pul
  dobowych. To przesprzedaż i zamyka ją dosypanie dób (niżej), nie zmiana reguły liczenia.
- **Wiersze dób na pakiecie, któremu doby zdjęto** — dalej zjadają pule dobowe, choć
  nowe rezerwacje tego pakietu są bramkowane pulą slotową. Wiersze **zostają**: kasowanie
  historii jest gorsze od rozjazdu, a rozjazd jest widoczny w panelu obłożenia. Admin
  ostrzega przy zdejmowaniu dób, ile aktywnych rezerwacji nadal będzie liczonych dobowo.

### Dosypanie dób istniejącym rezerwacjom

Włączenie dób na pakiecie, który już ma aktywne rezerwacje, wymaga **jawnej akcji
w adminie** („przypisz doby istniejącym rezerwacjom"): dla każdej zajmującej miejsce
rezerwacji tego pakietu, która nie ma jeszcze wierszy dób, zapisuje je według dób
pakietu wraz ze snapshotem etykiet.

- To **jednorazowa materializacja**, nie liczenie z konfiguracji przy COUNT — anty-cel
  dotyczy ścieżki liczenia, nie jednorazowego zapisu historii. Po dosypaniu wiersze są
  zwykłą historią, odporną na dalsze zmiany nazw.
- Musi być **idempotentna** (`UNIQUE(booking_id, night_key)` + pomijanie rezerwacji,
  które wiersze już mają) i wykonywana pod lockiem eventu, bo zmienia popyt widziany
  przez pule.
- Akcja jest jawna, nie odpalana z zapisu konfiguracji: zapis configu nie może po cichu
  przepisywać rezerwacji.
- Dopóki dosypania nie zrobiono, admin **liczy i pokazuje** ile aktywnych rezerwacji nie
  jest widzianych przez pule dobowe.

## Model bazy

Nowa tabela potomna, `Migrations::DB_VERSION` → `7`:

```sql
CREATE TABLE {prefix}evreg_booking_nights (
    id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
    booking_id bigint(20) unsigned NOT NULL,
    registration_id bigint(20) unsigned NOT NULL,
    night_key varchar(64) NOT NULL,
    night_label varchar(191) NOT NULL DEFAULT '',
    room_type_key varchar(64) NOT NULL,
    PRIMARY KEY  (id),
    UNIQUE KEY uniq_booking_night (booking_id, night_key),
    KEY idx_registration (registration_id),
    KEY idx_night (night_key, room_type_key)
) ENGINE=InnoDB
```

- **Dlaczego osobne wiersze, a nie JSON na bookingu:** zajętość musi dać się policzyć
  jednym `GROUP BY` pod lockiem, tą samą drogą co dziś. JSON wymuszałby wczytanie
  wszystkich bookingów i liczenie w PHP, czyli wyjście z wzorca „liczymy w SQL pod lockiem".
- **Dlaczego `registration_id` jest zdenormalizowany** (booking jest 1:1 ze zgłoszeniem):
  ścieżka `occupancyExcluding(event, exclude_id)` i twarde kasowanie zgłoszenia filtrują
  po zgłoszeniu; bez tej kolumny każda z nich dokładałaby drugi JOIN. Kolumna jest
  wypełniana w tej samej transakcji co booking i nigdy nie żyje osobno.
- **`night_label` to snapshot**, dokładnie jak `package_label`/`room_label` od 0.1.14:
  zmiana nazwy doby w konfiguracji nie może przepisać historii. Etykieta jest pokazywana
  na detalu zgłoszenia (organizator musi wiedzieć, które noce ktoś śpi).
- `booking_id` kasowane twardo razem z bookingiem (`deleteRegistration` na `cancelled`).
  Eraser prywatności **nie tyka** tych wierszy: nie zawierają danych osobowych, a ich
  usunięcie rozjechałoby zajętość (ta sama zasada, dla której eraser zostawia status,
  typ, cenę i daty).
- `Uninstaller::TABLES` dostaje nową nazwę (dziś cztery tabele, będzie pięć).
  Bramka `evreg_delete_data_on_uninstall` bez zmian.

## Domena

Nowe i zmienione obiekty w `src/Domain/Accommodation/` oraz `src/Domain/Capacity/`:

- **`Night`** (nowy): `key`, `label`, `fromArray`, `toArray` — bliźniak `Package`.
- **`Package`**: nowe pole `nights` (`string[]`, domyślnie pusta tablica). Metoda
  `usesNightPools(): bool` = `array() !== $this->nights`.
- **`AccommodationConfig`**: `nights(): Night[]`, `nightLabel(string $key): string`
  (fallback na surowy klucz, jak `packageLabel`), `nightsForPackage(string $key): string[]`,
  `nightCapacities(): array<string,int>` kluczowane `"doba|pokój"`,
  `usesNightPools(string $package_key): bool`.
- **`OccupancySnapshot`**: nowa mapa `perNightRoom` (klucz `"doba|pokój"` → liczba pokoi)
  + akcesor `forNightRoom(string $key): int`. Konstruktor dostaje ją jako kolejny
  parametr z domyślną pustą tablicą — zgodność w tył wywołań.
- **`CapacityLimits`**: nowa mapa limitów dobowych + mapa `pakiet → doby`, oba jako
  parametry z domyślną pustą tablicą (tak jak `perSlotSeats` w B-occupancy).
- **`CapacityCalculator::decide`**: gdy pakiet selekcji ma doby, bramka noclegowa
  przestaje patrzeć na `forSlot`/`seatsForSlot`, a zamiast tego dla **każdej** doby
  pakietu sprawdza `forNightRoom("doba|pokój") + 1 > limit`. Brak limitu dla pary
  `(doba, pokój)` = brak miejsc (spójnie z dzisiejszym `null === $slot_limit`).
  Pierwsza wyczerpana doba kończy sprawdzanie i daje `accommodation_full`. Decyzje o
  limicie globalnym i limicie typu bez zmian, przed bramką noclegową, jak teraz.
- **`SlotAvailability`**: `fromSnapshot` czyta też pule dobowe; `left(string $slot)` dla
  pakietu z dobami = minimum `(limit - zajęte)` po dobach; `isFull($slot, $seats)` dla
  pakietu z dobami ignoruje `$seats` (pokój to pokój) i odpowiada po `left() < 1`.
  Tryb zgodności: obie metody bez zmian.

Cała ta arytmetyka jest czysta i testowalna bez WordPressa — tam mieszkają testy
jednostkowe przykładu prowadzącego (10/31 i 22/43, pakiet dwudobowy zdejmujący po jednym).

## Ścieżki transakcyjne

Trzy i tylko trzy miejsca liczą i zapisują zajętość, wszystkie w `ReservationService`:
`reserve`, `editAnswers`, `promoteFromWaitlist`. Każda:

1. `lockEvent($event_id)` **przed** pierwszym odczytem zajętości (bez zmian),
2. buduje `CapacityLimits` z pulami dobowymi i mapą `pakiet → doby`,
3. `decide(...)` jak dziś, już świadome dób,
4. przy przyznanym noclegu wstawia booking **oraz** wiersze dób w tej samej transakcji,
5. przy pełnej puli: `reserve` robi ROLLBACK i zwraca `accommodationFull`;
   `editAnswers` odrzuca edycję (`accommodationFull`); `promoteFromWaitlist` awansuje,
   ale zwraca `promoted_no_accommodation`.

`editAnswers` ma dodatkową regułę z B-occupancy, która musi objąć doby: edycja **nie
zmieniająca** ani slotu, ani flagi towarzyszącej zachowuje zapisane miejsca, cenę
**i zapisane wiersze dób** — nie przelicza ich i nie przechodzi bramki slotowej. Bez
tego niezwiązana edycja starego zgłoszenia przepisywałaby mu doby według bieżącej
konfiguracji pakietu, czyli dokładnie to, czego zakazuje anty-cel.

Zapisany booking jest czytany **pod lockiem** (`lockEvent` → `findAccommodationBooking`
→ `occupancy*`), bez zmian wobec dzisiejszego stanu.

## Repozytorium (jedyne SQL)

`RegistrationRepository`:

- `occupancy(event, statuses)` i `occupancyExcluding(event, statuses, exclude_id)`
  dostają dodatkowe zapytanie o pule dobowe:
  `SELECT CONCAT(n.night_key,'|',n.room_type_key) AS slot, COUNT(*) AS c
   FROM {booking_nights} n INNER JOIN {registrations} r ON n.registration_id = r.id
   WHERE r.event_id = %d AND r.status IN (...) GROUP BY slot`
  (wariant `Excluding` dokłada `AND r.id != %d`). Dotychczasowe zapytanie o pule
  slotowe **zostaje** — tryb zgodności z niego żyje.
- `insertAccommodationBooking` wstawia też wiersze dób (doby + ich etykiety przychodzą
  z warstwy wywołującej, która ma `AccommodationConfig`) — potrzebuje `insert_id`
  bookingu, żeby je powiązać.
- **Wiersze dób giną razem z bookingiem na KAŻDEJ ścieżce**, która dziś kasuje booking,
  nie tylko przy twardym usunięciu zgłoszenia: `cancel`, `editAnswers` (zmiana slotu),
  `promoteFromWaitlist` i `deleteRegistration`. Pominięcie choćby jednej zostawia popyt
  na pulach dobowych po rezerwacji, która już ich nie zajmuje — czyli trwale zaniżoną
  dostępność. Najprościej: kasowanie dób siedzi w tej samej metodzie repozytorium, co
  kasowanie bookingu, żeby nie dało się wywołać jednego bez drugiego.
- Metoda odczytu dób zapisanej rezerwacji (do detalu zgłoszenia i eksportu).
- Zapytanie dla dosypania dób: aktywne rezerwacje danego pakietu bez wierszy dób.
- Eraser prywatności **nie tyka** wierszy dób (brak danych osobowych; usunięcie
  rozjechałoby zajętość) — ta sama zasada, dla której zostawia status, typ, cenę i daty.

## Admin (React)

Zakładka „Noclegi" (`AccommodationTab.jsx`), logika w `accommodationOps.js`:

- **Lista dób** (dodaj / usuń / klucz / etykieta) — bliźniak istniejącej listy pakietów.
- **Pakiet dostaje wybór dób** (wielokrotny). Pakiet bez wybranych dób zostaje w trybie
  zgodności i musi to komunikować wprost, nie milczeniem.
- **Nowa macierz `doba × pokój`** z pojemnościami — bliźniak istniejącej macierzy
  inwentarza.
- **Istniejąca macierz `pakiet × pokój`** zostaje przy cenie; komórka pojemności jest
  wyszarzona z notką „limit idzie z dób", gdy pakiet ma doby. Liczba nie może zostać
  w konfiguracji i udawać, że działa.
- **Blokada połączenia „pokój z polem współlokatora + pakiet z dobami"** w momencie
  ustawiania, nie dopiero przy zapisie (serwer i tak odrzuci, ale admin nie może
  pozwolić dojść do stanu, którego nie da się zapisać).
- **Akcja „przypisz doby istniejącym rezerwacjom"** z liczbą rezerwacji, których
  dotyczy, i jawnym potwierdzeniem. Pokazywana, dopóki jakakolwiek aktywna rezerwacja
  pakietu z dobami nie ma wierszy dób.
- Ostrzeżenie o rozjeździe (jak dzisiejsze o zmianie klucza pakietu): usunięcie doby,
  do której odwołują się pakiety albo istniejące bookingi; zdjęcie dób z pakietu
  (z podaniem limitu, który wraca do gry, i liczby rezerwacji dalej liczonych dobowo).

### Panel obłożenia

`RegistrationsOverview::render_slots()` pokazuje dziś „Noclegi (miejsca)" z
`OccupancyReport::slots()`, które bierze `taken/capacity/free` wprost z
`InventoryItem::capacity`. Dla pakietu z dobami ta liczba jest uśpiona, więc panel
pokazywałby „wolne 0", podczas gdy formularz te pokoje sprzedaje. To jest **ta
powierzchnia, na którą organizator patrzy**, więc cel 4 obejmuje ją wprost:

- `OccupancyReport` dostaje czystą metodę zwracającą obłożenie per `(doba × pokój)`
  (`taken/capacity/free` w pokojach), zasilaną pulami dobowymi ze snapshotu.
  `report()` buduje dziś `CapacityLimits` bez map dobowych — musi je dostać.
- Admin renderuje nową tabelę „Noclegi per doba (pokoje)".
- Wiersze slotowe pakietów z dobami są oznaczone jako nieobowiązujące, zamiast
  pokazywać mylące zero.
- Liczba aktywnych rezerwacji niewidzianych przez pule dobowe (brak wierszy dób)
  jest pokazana tutaj, nie tylko w zakładce konfiguracji.

`EventConfigController::sanitize` jest generyczny, więc nowe klucze przechodzą bez
zmian — do potwierdzenia testem, nie założeniem.

## Wielojęzyczność

Etykiety dób są tłumaczalne per język jak pakiety i pokoje (B6): overlay
`_evreg_i18n[lang].accommodation` zyskuje bucket `nights: { key: label }`,
`ContentTranslator::translateAccommodation` nakłada go przed `SchemaAssembler`,
`i18nOps.langBucket` musi whitelistować `accommodation` (już whitelistuje) i nowy
bucket musi wejść do `translatableItems`. Tłumaczone są **tylko etykiety** — klucze,
pojemności i ceny nietknięte.

## Render publiczny

Lista pozycji `(pakiet × pokój)` wygląda jak dziś. Zmienia się tylko źródło sądu
„zamknięte":

- wyczerpana pozycja dostaje `disabled` + dopisek `(brak miejsc)` i **nigdy nie
  renderuje się jako `checked`** (inwariant B-slots: `disabled` nie wysyła wartości,
  więc zaznaczony-i-wyłączony radio dałby po submicie pusty `[slot]`, czyli ciche
  „Bez noclegu"),
- `data-evreg-seats-left` niesie `left()` liczony po dobach (w pokojach, nie miejscach),
  a wiersz dostaje dodatkowy atrybut mówiący, z której puli żyje — bez tego ta sama
  liczba znaczyłaby raz miejsca, raz pokoje, i nikt by tego nie zauważył.

**Pule dobowe NIE jadą do przeglądarki.** Dziś jedynym wejściem klienta w dostępność
jest checkbox osoby towarzyszącej, bo zmieniał liczbę potrzebnych miejsc. Przy pulach
dobowych nie zmienia nic (pokój to pokój), więc nie ma czego przeliczać: serwer wylicza
`left` i stan zamknięcia, a `form.js` dla takiego wiersza tylko je respektuje. Lustro
arytmetyki (`seatsFor`) zostaje nietknięte i dalej obsługuje tryb zgodności.

Powód jest też praktyczny: `evreg-public` to statyczny skrypt bez `wp_localize_script`
i bez testowanego modułu, więc wysyłanie tam map pakiet→doby i pojemności to nowy kanał
danych i nowy szew testowy — zbudowany pod coś, czego nikt nie czyta.

Nazwanie blokującej doby w komunikacie („brak miejsc w nocy 19/20") byłoby miłe, ale
to osobna decyzja produktowa — nie wchodzi w ten projekt.

## Eksport i detal zgłoszenia

Doby zapisane na rezerwacji są pokazywane organizatorowi w dwóch miejscach:

- **detal zgłoszenia** — obok pakietu i pokoju, z etykiet zapisanych na wierszach,
- **eksport CSV** — nowa kolumna „Doby" w `RegistrationExportMapper::accommodationCells`
  (czysta domena, więc i własne testy). To jest praktyczny artefakt tej potrzeby: lista
  kwaterunkowa dla hotelu powstaje z eksportu, nie z klikania po detalach.

Mail i szablony bez zmian — nie dochodzi żaden placeholder.

## Co zostaje builderowi

- Nazwy metod i klas trzymają konwencję repo (camelCase na obiektach wartości).
- Kolejność tasków i granice commitów.
- Czy mapa `pakiet → doby` w `CapacityLimits` to osobny obiekt wartości, czy tablica.
- Kształt testów, pod warunkiem że pokrywają: przykład prowadzący; pakiet dwudobowy
  blokowany przez **drugą** dobę (nie pierwszą); tryb zgodności bez regresji; edycję
  niezmieniającą slotu (zachowuje zapisane doby, miejsca i cenę); awans z waitlisty przy
  wyczerpanej dobie; odmowę konfiguracji „pole współlokatora + doby"; dosypanie dób
  uruchomione dwa razy pod rząd (idempotencja); kasowanie wierszy dób na każdej ścieżce
  kasującej booking (`cancel`, zmiana slotu w `editAnswers`, `promoteFromWaitlist`,
  `deleteRegistration`); panel obłożenia dla pakietu z dobami; oraz to, że `lockEvent`
  poprzedza każdy odczyt zajętości.
- Dopisek do `CLAUDE.md` z nośnymi regułami tej zmiany i wpis do `CHANGELOG.md`
  w `## [Unreleased]`.

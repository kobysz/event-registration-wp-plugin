# Pule noclegowe per doba — projekt

**Data:** 2026-11-17
**Status:** spec do zatwierdzenia

**Historia wersji** (trzy wcześniejsze były błędne; warto wiedzieć, na czym):
v1 liczyła pulę w pokojach, jedna rezerwacja = jeden pokój — gubiła dwójkę sprzedawaną
na łóżka. v2 liczyła pulę w łóżkach — para dostawała „dwa wolne łóżka w dwóch pokojach",
a każdy wariant sprzedaży miał własną pulę nad tym samym zapasem. v3 wprowadziła wariant
sprzedaży na ofercie i trzyczłonowy klucz slotu, który **nigdy nie trafiłby w zapisane
bookingi** (zapytanie liczące konkatenuje `pakiet|pokój`) — dla konfiguracji legacy
`occupancy = 2` zajętość czytałaby stałe zero, czyli cicha przesprzedaż. v4 (ta) liczy
pokoje z pakowaniem popytu i **nie dotyka ani klucza slotu, ani selekcji, ani tabeli
bookingów**: wariant sprzedaży wynika z checkboxa osoby towarzyszącej.

## Intencja i dlaczego

Dziś limit noclegów należy do **pozycji inwentarza**, czyli pary `(pakiet × pokój)`.
`Package` nie wie nic o dobach. Slot to string `"pakiet|pokój"`, a zajętość liczy jedno
`GROUP BY CONCAT(package_key, '|', room_type_key)`.

Skutek: każda kombinacja dób ma **rozłączną** pulę. Organizator, który fizycznie ma
10 jedynek na noc 18/19, musi ten stan ręcznie podzielić między pakiet „tylko 18/19"
i pakiet „18–20", zgadując proporcje z góry. Pule nie rozmawiają ze sobą, więc **nie da
się tego skonfigurować poprawnie** — to brak w modelu, nie pomyłka w ustawieniach.
Rezerwacja na dwie doby nie zdejmuje nic z puli pojedynczej doby, choć fizycznie zajmuje
ten pokój w obie noce.

Zmiana: doba staje się pierwszoklasowa. Limit mieszka na parze `(doba × typ pokoju)`,
jest wpisywany **w pokojach**, a rezerwacja zajmuje pokoje w każdej dobie swojego pakietu.

### Przykład prowadzący 1 — dwie doby

Doby `18/19` i `19/20`. Limity per doba: jedynki 10 i 22, dwójki 31 i 43. Pakiety:
`{18/19}`, `{19/20}`, `{18/19, 19/20}`. Uczestnik wybiera „dwie doby + pokój
jednoosobowy" → zdejmuje jeden pokój z puli 18/19 **oraz** jeden z puli 19/20. Pozycja
jest zamknięta, gdy wyczerpana jest którakolwiek z jej dób.

### Przykład prowadzący 2 — dwójki sprzedawane i na łóżka, i w całości

Hotel ma 31 pokoi dwuosobowych i nic więcej. Z tego samego zapasu, bez dzielenia go
z góry:

- uczestnik **bez** osoby towarzyszącej bierze **jedno łóżko**; drugie zostaje do
  sprzedania komuś innemu (hotel dokwateruje),
- uczestnik **z** osobą towarzyszącą zajmuje **oba łóżka**, czyli pokój na wyłączność.

Podział 31 pokoi między pary i singli robi **popyt**, nie konfiguracja. To wymusza dwie
rzeczy: limit należy do fizycznego typu pokoju, a popyt musi być liczony w pokojach
z pakowaniem samotnych łóżek.

## Cele

1. Limit noclegów per `(doba × typ pokoju)`, niezależny od pakietów, wpisywany
   **w pokojach**.
2. Pakiet deklaruje listę dób; rezerwacja zajmuje pokoje w każdej z nich.
3. Z jednego zapasu pokoi sprzedają się **jednocześnie** całe pokoje (pary) i pojedyncze
   łóżka (single), bez ręcznego dzielenia zapasu.
4. Popyt liczony tak, żeby **para zawsze dostała jeden pokój**: samotne łóżka pakowane
   po `beds`, para bierze pokój na wyłączność.
5. Dostępność pozycji = AND po dobach pakietu; „ile zostało" = minimum po dobach.
6. Wyczerpana doba zamyka pozycję **na każdej powierzchni naraz** — render, `reserve`,
   `editAnswers`, `promoteFromWaitlist`, panel obłożenia.
7. Zero regresji dla eventów skonfigurowanych po staremu, **bit w bit**.

## Nie-cele

- **Doby na formularzu publicznym** (uczestnik zaznacza doby, pakiet znika z UI). Osobna,
  większa zmiana; stoi na pulach dobowych, więc ten projekt jest jej warunkiem wstępnym.
- **Samotnik kupujący dwójkę na wyłączność za podwójną cenę**, jako osobno wyceniony
  wariant obok „miejsca w dwójce". Wymagałby wariantu sprzedaży na ofercie, a z nim
  trzyczłonowego klucza slotu, `sale` w selekcji, w JSON-ie odpowiedzi, w prefillu
  edycji i w porównaniu „czy slot się zmienił" — pięć miejsc i realne ryzyko rozjazdu
  z zapisanymi bookingami (to był błąd v3). Nikt o ten przypadek nie prosił. Jeśli
  kiedyś będzie potrzebny, wraca jako osobny projekt z własnym planem migracji.
- Cena per doba ani rabat za wiele dób.
- **Parowanie współlokatorów.** Wtyczka nie wiąże dwóch zgłoszeń w jeden fizyczny pokój.
  `roommate_pref` zostaje preferencją do ręcznego kwaterowania. Pula zakłada, że hotel
  dokwateruje optymalnie (dwa samotne łóżka trafiają do jednego pokoju) — to samo
  założenie, które organizator i tak realizuje ręcznie.
- Numery pokoi, lista kwaterunkowa z przydziałem.
- Doby wspólne między wydarzeniami.
- „Bez limitu". `0` dalej znaczy „brak miejsc".

## Anty-cele (porażka, nawet gdyby działało)

- **Ręczne dzielenie zapasu** — ani między pakietami (dziś), ani między wariantami
  sprzedaży (błąd v2).
- **Mnożenie przez liczbę łóżek po stronie organizatora.** Limit wpisuje się w pokojach.
- **Zgubienie drugiego łóżka** w dwójce bez towarzyszącej (błąd v1).
- **Para bez pokoju** — dwa wolne łóżka w dwóch różnych pokojach to nie miejsce dla pary
  (błąd v2).
- **Zmiana kształtu klucza slotu albo selekcji.** Zapisane bookingi i zapytanie
  `GROUP BY CONCAT(package_key, '|', room_type_key)` muszą dalej trafiać w ten sam klucz;
  rozjazd byłby cichy, bo zapytanie dalej by działało, tylko na pustym zbiorze (błąd v3).
- **Ciche podwojenie limitu** przy degradacji konfiguracji sprzecznej z wyłączoną osobą
  towarzyszącą.
- **Doliczanie dób z konfiguracji w momencie liczenia.** Zmiana nazwy albo usunięcie
  pakietu przeliczyłoby zajętość w tył. Lekcja opłacona w 0.1.14.
- **Jakikolwiek odczyt zajętości przed `lockEvent()`.** Inwariant 3A bez zmian.
- **Przyjęcie zgłoszenia bez noclegu, o który prosiło, bez powiedzenia tego wprost**
  (B-slots): pełna pula → ROLLBACK i `accommodationFull`.
- **Wyjątek z `AccommodationConfig::fromArray` na ścieżce renderu publicznego.** Metoda
  jest wołana z `FormRenderer`, `ReservationService`, panelu, eksportu i eksportera
  prywatności — rzucenie kładzie **cały formularz** („Rejestracja jest niedostępna")
  zamiast zamknąć jeden wiersz. Wyjątek zostaje tylko dla odniesień do nieistniejących
  kluczy, których UI nie umie wyprodukować.
- **Druga kopia arytmetyki.** `RoomOccupancy` (łóżka) i `NightOccupancy` (pokoje) mają
  po jednym domu w czystej domenie i po jednym lustrze w `form.js`.

## Ograniczenia

- `src/Domain/**` bez WordPressa i bez `$wpdb` (`DomainPurityTest`).
- PHPStan 6 bez obniżania, `phpcs` czysto, wszystkie stringi przez i18n.
- Mutacje konfiguracji w `assets/admin/ops/accommodationOps.js`, testowane jest.
- `dbDelta`, idempotentnie; `Migrations::DB_VERSION` 6 → 7.
- Lustro `form.js` musi dawać ten sam wynik co domena.

## Podejścia

**1. Zostawić jak jest, dzielić stan ręcznie.** Zero kodu, ale model tego nie wyraża:
rezerwacja dwudobowa nie zdejmuje nic z puli pojedynczej doby. Odrzucone.

**2. Pule dobowe pod zachowanym wyborem pakietu — WYBRANE.** Uczestnik widzi listę jak
dziś; zmienia się to, skąd bierze się sąd o dostępności.

**3. Doby wprost na formularzu** (wybór pokoju + zaznaczenie dób). Najprostsze
koncepcyjnie dla uczestnika, ale rusza render, submit, `AccommodationSelection`,
kardynalność bookingu, cenę, admina, CSV, mail i e2e. Odłożone: stoi na podejściu 2.

## Model pokoju

Dziś `occupancy` (0/1/2) skleja dwie rzeczy: ile łóżek ma pokój i czy musi być sprzedany
w całości. Rozdzielamy je na dwa jawne pola:

| pole | wartości | znaczenie |
|---|---|---|
| `beds` | `0` (nieustawione), `1`, `2` | ile łóżek ma pokój — fakt fizyczny |
| `whole_only` | bool | pokój sprzedawany tylko w całości (wymaga towarzyszącej) |
| `roommate_field` | bool | czy pytać o preferencję współlokatora (jak dziś) |

Wariant sprzedaży **nie jest** osobnym bytem w konfiguracji — wynika z checkboxa osoby
towarzyszącej. Dla `beds = 2`, `whole_only = false`: towarzysząca zaznaczona → dwa łóżka
i pokój na wyłączność; nie zaznaczona → jedno łóżko, drugie wolne.

**Ile łóżek zjada rezerwacja.** `RoomOccupancy::seats( int $beds, bool $whole_only,
bool $companion ): int` — jedyny dom reguły: `whole_only` i `beds > 0` → `beds`;
w każdym innym przypadku → `companion ? 2 : 1`. Delegują do niej `RoomType::seats`,
`AccommodationConfig::seatsFor`, `CapacityLimits::seatsForSlot` i lustro w `form.js`.

**Spójność pary pokój/towarzysząca** (`RoomType::companionMismatch`):
`whole_only` i `beds = 2` bez towarzyszącej → `companion_required`;
`beds = 1` z towarzyszącą → `companion_not_allowed`;
`beds = 0` → nigdy nie protestuje (zgodność w tył);
**`beds = 2`, `whole_only = false`, bez towarzyszącej → poprawne** (przykład 2).

`whole_only` jest bezprzedmiotowe przy `beds = 1` (cały pokój jednoosobowy to jego jedno
łóżko) i `fromArray` **normalizuje** je wtedy do `false`, zamiast rzucać; bez tego taki
pokój żądałby towarzyszącej i jednocześnie jej zabraniał, czyli byłby niewybieralny na
żaden stan checkboxa.

### Zgodność w tył

`RoomType::fromArray` czyta `beds`/`whole_only`, a gdy ich nie ma — legacy `occupancy`:

| stary config | nowy model | zachowanie |
|---|---|---|
| `occupancy = 2` | `beds = 2`, `whole_only = true` | bez zmian: wymaga towarzyszącej, 2 łóżka |
| `occupancy = 1` | `beds = 1`, `whole_only = false` | bez zmian: zabrania towarzyszącej, 1 łóżko |
| `occupancy = 0` | `beds = 0`, `whole_only = false` | bez zmian: nie protestuje, 1 albo 2 łóżka |

`toArray` zapisuje nowe klucze; odczyt `occupancy` zostaje na stałe, więc konfiguracje
na istniejących stronach nie wymagają migracji ani wejścia do edytora.

Dzisiejsza reguła z `AccommodationConfig::fromArray` — „przy wyłączonej osobie
towarzyszącej zeruj `occupancy`" — zostaje **w tym samym duchu i z tym samym skutkiem**:
`whole_only` jest wymuszane na `false`, a `beds` **zachowane**. W trybie zgodności daje
to dokładnie dzisiejszą arytmetykę (pula w miejscach, samotna rezerwacja zjada jedno
miejsce), bo pula zgodności nigdy nie przelicza pokoi na łóżka. Liczba łóżek musi
zostać, bo w pulach dobowych służy do pakowania.

**Znana granica:** event z pulami dobowymi, wyłączoną towarzyszącą i pokojami o `beds = 2`
upakuje po dwóch obcych do pokoju, czyli 31 pokoi przyjmie 62 osoby. To jest arytmetycznie
poprawne (tyle łóżek fizycznie jest) i dotyczy wyłącznie **nowych** konfiguracji — stare
nie mają dób. Organizator, który tego nie chce, ustawia `whole_only`, co wymaga
towarzyszącej; admin ostrzega o tej sprzeczności.

## Jednostka puli: limit w pokojach, popyt w pokojach z pakowaniem

Limit dobowy jest **w pokojach**. Popyt też, ale rezerwacje nie są równe:

- rezerwacja potrzebująca **`beds` łóżek** (para, albo pokój `whole_only`) zajmuje
  **pokój na wyłączność**,
- rezerwacja potrzebująca **mniej** (samotne łóżko) jest **pakowana**: `beds` takich
  rezerwacji dzieli jeden pokój.

Dla pary `(doba, pokój)` zajętość to dwie liczby:

```
pełne        = liczba rezerwacji z seats >= beds
luźne_łóżka  = suma seats rezerwacji z seats < beds
pokoje_zajęte = pełne + ceil( luźne_łóżka / beds )
```

Bramka dla rezerwacji potrzebującej `s` łóżek sprowadza się do **jednej** nierówności —
oba przypadki (`s >= beds` i `s < beds`) redukują się do tego samego:

```
s <= ( limit_pokoi - pełne ) * beds - luźne_łóżka
```

Prawa strona to **wolne łóżka** tej doby. Dlatego „czy zmieści się para" i „ile zostało
luźnych łóżek" to jedna liczba: dopóki para potrzebuje `beds` łóżek, nierówność
automatycznie wymaga całego wolnego pokoju. Stąd:

- `left( $slot )` = minimum po dobach pakietu z `max( 0, (limit - pełne) * beds - luźne )`,
- `isFull( $slot, $seats )` = `left( $slot ) < $seats` — **sygnatura i semantyka bez
  zmian**, a `data-evreg-seats-left` dalej niesie jedną liczbę i jest wiernym lustrem.

Komunikaty zachowują dzisiejsze znaczenie: `(brak miejsc)` przy `left = 0`,
`(za mało miejsc)` gdy `0 < left < seats` (towarzysząca potrzebuje dwóch łóżek, a został
jeden).

**Każdy pokój w puli dobowej musi mieć `beds > 0`** — bez liczby łóżek nie ma czym
pakować. Oferta łącząca pakiet z dobami z pokojem o `beds = 0` jest **zamknięta**:
pojemność traktowana jak `0`, więc wiersz renderuje się widocznie jako „brak miejsc",
`AccommodationValidator` odpowiada `accommodation_full` (nie `invalid_accommodation`),
a `fromArray` **nie rzuca**. Brakujący wiersz `(doba, pokój)` w `night_inventory` działa
identycznie — pojemność `0`. To jedyny nowy warunek zamknięcia i nie wprowadza nowego
pojęcia do kodu: zamknięcie już dziś wyraża się zerową pojemnością slotu.

## Cena

Bez zmian: `PriceCalculator::total` mnoży cenę pozycji przez `seats`. Cena to **cena za
łóżko** — samotne łóżko ×1, towarzysząca ×2, pokój `whole_only` ×`beds`. Tak jest dziś
i tak zostaje; admin ma to napisać wprost przy macierzy cen, bo przy dwójce
sprzedawanej na łóżka pomyłka „za pokój czy za osobę" jest kosztowna.

Bramkowanie ceny przyznaniem noclegu (B7) i zachowanie zapisanej ceny przy edycji
niezmieniającej slotu (B-occupancy) bez zmian.

## Model konfiguracji

```php
array(
    'nights'          => array(
        array( 'key' => 'n1819', 'label' => '18/19 grudnia' ),
        array( 'key' => 'n1920', 'label' => '19/20 grudnia' ),
    ),
    'rooms'           => array(
        array( 'key' => 'single', 'label' => 'Pokój 1-osobowy', 'beds' => 1 ),
        // Dwójka na łóżka: para bierze cały pokój, singiel jedno łóżko.
        array( 'key' => 'double', 'label' => 'Pokój 2-osobowy', 'beds' => 2, 'whole_only' => false, 'roommate_field' => true ),
    ),
    'packages'        => array(
        array( 'key' => 'p18', 'label' => 'Doba 18/19', 'nights' => array( 'n1819' ) ),
        array( 'key' => 'p19', 'label' => 'Doba 19/20', 'nights' => array( 'n1920' ) ),
        array( 'key' => 'p2',  'label' => 'Dwie doby',  'nights' => array( 'n1819', 'n1920' ) ),
    ),
    'inventory'       => array(
        // Bez zmian strukturalnie: klucz slotu zostaje "pakiet|pokój".
        // `capacity` jest uśpiona dla pakietu z dobami; `price` to cena za łóżko.
        array( 'package' => 'p2', 'room' => 'single', 'capacity' => 10, 'price' => 400.0 ),
        array( 'package' => 'p2', 'room' => 'double', 'capacity' => 20, 'price' => 300.0 ),
    ),
    'night_inventory' => array(
        // Limit POKOI per doba × typ pokoju.
        array( 'night' => 'n1819', 'room' => 'single', 'capacity' => 10 ),
        array( 'night' => 'n1819', 'room' => 'double', 'capacity' => 31 ),
        array( 'night' => 'n1920', 'room' => 'single', 'capacity' => 22 ),
        array( 'night' => 'n1920', 'room' => 'double', 'capacity' => 43 ),
    ),
    // allow_none, companion_enabled, companion_counts_event — bez zmian.
)
```

**Co NIE zmienia kształtu:** `InventoryItem::slotKey()` (dalej `"pakiet|pokój"`),
`AccommodationSelection`, JSON odpowiedzi (`{package, room, roommate}`), tabela
`evreg_accommodation_bookings`, zapytanie slotowe, prefill edycji, porównanie „czy slot
się zmienił". To świadomy warunek projektu, nie przypadek.

**Walidacja — `SchemaException` tylko dla odniesień, których UI nie umie wyprodukować**
(jak dziś): pusty/zduplikowany klucz doby, `packages[].nights` wskazujące na nieznaną
dobę, `night_inventory` na nieznaną dobę lub pokój, zduplikowana para `(doba, pokój)`,
ujemna pojemność, `beds` spoza `0..2`.

**Nie rzuca** (zamyka wiersz albo normalizuje, i zgłasza w adminie): oferta z dobami nad
pokojem o `beds = 0`; `whole_only` przy `beds = 1`; `whole_only` przy wyłączonej
towarzyszącej; niezerowa uśpiona `capacity`.

### Tryb zgodności

Pakiet bez dób działa jak dziś: limit z `inventory[...].capacity`, pula w miejscach
(`SUM(seats)` po slocie). Jeden event może mieszać pakiety z dobami i bez.

**Znana i przyjęta granica:** dwa pakiety nad tym samym pokojem, jeden z dobami i jeden
bez, sprzedają ten sam fizyczny zapas z dwóch niezależnych pul. Wtyczka tego nie wykryje;
admin ostrzega, gdy w jednym evencie istnieją oba rodzaje pakietów nad tym samym pokojem.

### Uśpiona pojemność oferty

Gdy pakiet ma doby, `capacity` oferty przestaje być limitem, ale **zostaje zapisana** —
admin jej nie zeruje. Wyszarza komórkę z notką „limit idzie z dób", a przy **zdejmowaniu**
dób mówi wprost, jaki limit wróci do gry (łącznie z tym, że `0` zamyka pozycję).

### Prawda o podaży i popycie

> **Wiersze dób bookingu są prawdą o popycie. Konfiguracja jest prawdą o podaży i o tym,
> czym bramkować nowy popyt.**

Ile rezerwacja zjada — mówią jej zapisane wiersze dób z zapisanym `seats`, nigdy bieżąca
konfiguracja. Czy nowa rezerwacja idzie przez pule dobowe czy slotowe — mówi bieżąca
konfiguracja pakietu.

- **Rezerwacja bez wierszy dób w pakiecie, który ma doby** — nie zjada nic z pul
  dobowych. Zamyka to dosypanie dób, nie zmiana reguły liczenia.
- **Wiersze dób na pakiecie, któremu doby zdjęto** — dalej zjadają pule dobowe. Wiersze
  zostają (kasowanie historii jest gorsze), rozjazd widoczny w panelu obłożenia, admin
  ostrzega przy zdejmowaniu dób.
- **Zapisane `seats` większe od bieżącego `beds`** (ktoś zmienił dwójkę na jedynkę pod
  istniejącymi parami) liczy się jako dokładnie jeden pokój — niedoszacowuje łóżek, ale
  nie pokoi, więc pula zostaje uczciwa.

### Dosypanie dób istniejącym rezerwacjom

Włączenie dób na pakiecie z aktywnymi rezerwacjami wymaga **jawnej akcji w adminie**
(„przypisz doby istniejącym rezerwacjom"): dla każdej zajmującej miejsce rezerwacji tego
pakietu zapisuje **brakujące** doby, per doba, nie per rezerwacja.

- Idempotentna na poziomie `(booking, doba)`. Inaczej pakiet, który **dostał drugą dobę**
  po dosypaniu, zostawiłby stare rezerwacje policzone tylko w pierwszej.
- Licznik „nie widziane przez pule dobowe" liczy rezerwacje z **niepełnym** pokryciem dób
  swojego pakietu, nie tylko te z zerowym — inaczej byłby ślepy na ten sam przypadek.
  Licznik zostaje nawet gdyby sama akcja wypadła z zakresu: to jedyna rzecz, która czyni
  rozjazd podaży i popytu widocznym.
- Pod lockiem eventu; jawna, nie odpalana z zapisu konfiguracji.
- `seats` dosypywanych wierszy bierze się z zapisanego bookingu, nie z konfiguracji.
- **Jeśli event nie zebrał jeszcze żadnej rezerwacji z noclegiem**, akcja jest na dziś
  martwa; zostaje w zakresie, bo wtyczka stoi na kilku stronach i każda, która kiedyś
  przełączy pakiet na doby, bez niej przesprzeda pokoje.

## Model bazy

`Migrations::DB_VERSION` → `7`. **Jedna nowa tabela, zero zmian w istniejących.**

```sql
CREATE TABLE {prefix}evreg_booking_nights (
    id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
    booking_id bigint(20) unsigned NOT NULL,
    registration_id bigint(20) unsigned NOT NULL,
    night_key varchar(64) NOT NULL,
    night_label varchar(191) NOT NULL DEFAULT '',
    room_type_key varchar(64) NOT NULL,
    seats tinyint unsigned NOT NULL DEFAULT 1,
    PRIMARY KEY  (id),
    UNIQUE KEY uniq_booking_night (booking_id, night_key),
    KEY idx_registration (registration_id),
    KEY idx_night (night_key, room_type_key)
) ENGINE=InnoDB
```

- **Osobne wiersze, nie JSON:** zajętość musi dać się policzyć jednym `GROUP BY` pod
  lockiem, tą samą drogą co dziś.
- **`seats` tutaj**, choć stoi na bookingu: zapytanie liczące rozdziela rezerwacje pełne
  od luźnych, więc wiersz musi być samowystarczalny, bez JOIN-a po liczbę łóżek.
- **`registration_id` zdenormalizowany** (booking jest 1:1 ze zgłoszeniem), bo
  `occupancyExcluding` i twarde kasowanie filtrują po zgłoszeniu.
- **`night_label` to snapshot**, jak `package_label`/`room_label` od 0.1.14.
- Eraser prywatności **nie tyka** tych wierszy (brak danych osobowych; usunięcie
  rozjechałoby zajętość) — jak zostawia status, typ, cenę i daty.
- `Uninstaller::TABLES` dostaje nową nazwę (cztery tabele → pięć).

## Domena

- **`Night`** (nowy): `key`, `label`, `fromArray`, `toArray` — bliźniak `Package`.
- **`Package`**: `nights` (`string[]`, domyślnie pusta) + `usesNightPools()`.
- **`RoomType`**: `occupancy` → `beds` + `whole_only`; `seats( bool $companion )`;
  `companionMismatch( bool $companion )` (sygnatury publiczne bez zmian).
- **`RoomOccupancy::seats( int $beds, bool $whole_only, bool $companion )`** — jedyny dom
  reguły łóżek.
- **`NightOccupancy`** (nowy, czysty): jedyny dom arytmetyki pokoi —
  `freeBeds( int $full, int $looseSeats, int $beds, int $limit ): int` (= prawa strona
  nierówności, clampowana do zera) oraz `roomsUsed(...)` dla panelu obłożenia.
- **`OccupancySnapshot`**: niesie **surowy histogram** `(doba|pokój|seats) => liczba`
  plus akcesor; rozbicie na `(pełne, luźne)` robi `NightOccupancy`, bo wymaga `beds`
  z konfiguracji. Repozytorium zostaje bez wiedzy o konfiguracji — nie dokładamy
  sprzężenia do jedynej klasy z SQL-em. Konstruktor dostaje histogram jako kolejny
  parametr z domyślną pustą tablicą.
- **`CapacityLimits`**: mapy `nightRoomCapacity` (w pokojach), `packageNights`,
  `roomBeds` — wszystkie z domyślną pustą tablicą (wzorem `perSlotSeats` z B-occupancy).
- **`CapacityCalculator::decide`**: gdy pakiet oferty ma doby, dla **każdej** doby woła
  `NightOccupancy`; pierwsza niepasująca kończy sprawdzanie i daje `accommodation_full`.
  Limit globalny i limit typu bez zmian, przed bramką noclegową.
- **`SlotAvailability`**: `fromSnapshot` czyta pule dobowe; `left()` i `isFull()` wg
  sekcji o jednostce puli. Tryb zgodności bez zmian.

## Ścieżki transakcyjne

`reserve`, `editAnswers`, `promoteFromWaitlist` w `ReservationService`. Każda:

1. `lockEvent($event_id)` **przed** pierwszym odczytem zajętości (bez zmian),
2. buduje `CapacityLimits` z mapami dobowymi, `packageNights` i `roomBeds`,
3. `decide(...)`, już świadome dób,
4. przy przyznanym noclegu wstawia booking **oraz** wiersze dób (z `seats`) w tej samej
   transakcji,
5. przy pełnej puli: `reserve` → ROLLBACK + `accommodationFull`; `editAnswers` →
   odrzucenie edycji; `promoteFromWaitlist` → awans z `promoted_no_accommodation`.

`editAnswers`: edycja **nie zmieniająca** ani slotu, ani flagi towarzyszącej zachowuje
zapisane miejsca, cenę **i wiersze dób** — nie przelicza ich i pomija bramkę slotową.
Porównanie „czy slot się zmienił" zostaje na dzisiejszym `package_key|room_type_key`,
bo klucz slotu nie zmienia kształtu.

Booking czytany **pod lockiem** (`lockEvent` → `findAccommodationBooking` → `occupancy*`).

## Repozytorium (jedyne SQL)

- `occupancy` / `occupancyExcluding` dostają zapytanie:
  `SELECT n.night_key, n.room_type_key, n.seats, COUNT(*) AS c
   FROM {booking_nights} n INNER JOIN {registrations} r ON n.registration_id = r.id
   WHERE r.event_id = %d AND r.status IN (...)
   GROUP BY n.night_key, n.room_type_key, n.seats`
  (wariant `Excluding` dokłada `AND r.id != %d`). Wynik to kilkanaście wierszy; rozbicie
  na pełne/luźne robi domena. Dotychczasowe zapytanie slotowe **zostaje** — tryb
  zgodności z niego żyje.
- `insertAccommodationBooking` wstawia też wiersze dób; potrzebuje `insert_id`.
- **Wiersze dób giną razem z bookingiem na KAŻDEJ ścieżce**, która go kasuje: `cancel`,
  `editAnswers` (zmiana slotu), `promoteFromWaitlist`, `deleteRegistration`. Kasowanie
  dób siedzi w tej samej metodzie repozytorium co kasowanie bookingu, żeby nie dało się
  wywołać jednego bez drugiego.
- Bulk odczyt dób dla wielu rezerwacji (wzorem `accommodationBookingsFor`), żeby eksport
  nie robił N+1.
- Zapytanie dla dosypania dób: aktywne rezerwacje pakietu z brakującymi dobami.

## Admin (React)

- **Pokój**: „Łóżka" (1 / 2 / nieustawione) + „Tylko cały pokój" zamiast dzisiejszej
  „Liczby osób". „Tylko cały pokój" wyłączone przy `beds = 1` i przy wyłączonej
  towarzyszącej (z komunikatem, nie milczeniem). „Preferowany współlokator" aktywne
  tylko przy `beds > 1` bez „tylko cały pokój".
- **Lista dób** (dodaj / usuń / klucz / etykieta) — bliźniak listy pakietów.
  **Usunięcie doby kaskaduje**: zdejmuje odniesienia z pakietów i osierocone wiersze
  `night_inventory`, wzorem `removePackage`/`removeRoom`, które już dziś czyszczą
  inwentarz. Bez kaskady admin mógłby zapisać config, który rzuca z `fromArray`, czyli
  zabić formularz jednym kliknięciem.
- **Pakiet**: wybór dób (wielokrotny); pakiet bez dób komunikuje tryb zgodności wprost.
- **Macierz `doba × pokój`**: limit w pokojach, obok informacja, ile to łóżek
  (`limit × beds`). Pokrywa wszystkie pokoje × doby; **dodanie doby zamyka każdy pokój,
  dopóki jej wiersz nie jest wypełniony** i musi to powiedzieć tak głośno, jak
  ostrzeżenie przy usuwaniu doby.
- **Macierz `pakiet × pokój`** zostaje przy cenie (za łóżko — napisać wprost); komórka
  pojemności wyszarzona z notką, gdy pakiet ma doby.
- **Akcja „przypisz doby istniejącym rezerwacjom"** z liczbą rezerwacji i jawnym
  potwierdzeniem.
- **Ostrzeżenia**: pokój bez łóżek w pakiecie z dobami; „tylko cały pokój" przy
  wyłączonej towarzyszącej; usunięcie doby; zdjęcie dób z pakietu; współistnienie
  pakietów z dobami i bez nad tym samym pokojem.

`EventConfigController::sanitize` jest generyczny i rekurencyjny, więc nowe klucze
przechodzą bez zmian — do potwierdzenia testem.

### Panel obłożenia

`RegistrationsOverview::render_slots()` bierze dziś `taken/capacity/free` wprost
z `InventoryItem::capacity`, która dla pakietu z dobami jest uśpiona — panel pokazywałby
„wolne 0", gdy formularz te pokoje sprzedaje. To powierzchnia, na którą patrzy
organizator, więc cel 6 obejmuje ją wprost:

- `OccupancyReport` dostaje czystą metodę per `(doba × pokój)`: limit w pokojach, pokoje
  zajęte (pełne + spakowane luźne), wolne pokoje i wolne łóżka.
- Admin renderuje tabelę „Noclegi per doba".
- Wiersze slotowe pakietów z dobami oznaczone jako nieobowiązujące, zamiast mylącego zera.
- Liczba rezerwacji z niepełnym pokryciem dób **wstrzykiwana** do raportu (nie odpytywana
  z czystej domeny) i pokazana tutaj.

## Wielojęzyczność

Etykiety dób tłumaczalne per język jak pakiety i pokoje (B6): overlay
`_evreg_i18n[lang].accommodation` zyskuje bucket `nights: { key: label }`,
`ContentTranslator::translateAccommodation` nakłada go przed `SchemaAssembler`, bucket
wchodzi do `translatableItems`. Tylko etykiety — klucze, pojemności, łóżka i ceny
nietknięte.

## Render publiczny i lustro

Lista ofert wygląda jak dziś, klucz slotu bez zmian. Zmienia się źródło sądu „zamknięte"
oraz **kontrakt DOM**, bo dzisiejsze jedno `data-evreg-occupancy` nie wyraża nowych
reguł: `beds = 2` bez „tylko cały pokój" i bez towarzyszącej musi być poprawne, a dziś
`occupancy = 2` znaczy niespójność.

- Wiersz emituje `data-evreg-beds` **i** `data-evreg-whole-only` (zamiast
  `data-evreg-occupancy`), plus `data-evreg-seats-left` z minimum po dobach.
- `form.js` dostaje przepisane **dwie** reguły: lustro `seats` (`RoomOccupancy`) i lustro
  niespójności (`companionMismatch`). To **zmiana w `form.js`** — wcześniejsza wersja
  tego speca twierdziła, że go nie ma, i była w tym miejscu zwyczajnie zła.
- Wyczerpana oferta dostaje `disabled` + dopisek i **nigdy nie renderuje się jako
  `checked`** (inwariant B-slots: `disabled` nie wysyła wartości, więc zaznaczony-
  i-wyłączony radio dałby po submicie pusty `[slot]`, czyli ciche „Bez noclegu").
- Inwariant lustra w `CLAUDE.md` przeredagowany na parę `beds`/`whole_only`.

## Eksport i detal zgłoszenia

- **Detal zgłoszenia**: doby obok pakietu i pokoju, z etykiet zapisanych na wierszach.
- **Eksport CSV**: nowa kolumna „Doby" w `RegistrationExportMapper::accommodationCells`
  (czysta domena, własne testy), zasilana bulk odczytem. Lista kwaterunkowa dla hotelu
  powstaje z eksportu, nie z klikania po detalach.

Mail i szablony bez zmian — żaden placeholder nie dochodzi.

## Co zostaje builderowi

- Nazwy metod i klas wg konwencji repo; kształt map w `CapacityLimits`.
- Kolejność tasków i granice commitów.
- Kształt testów, pod warunkiem że pokrywają:
  - oba przykłady prowadzące, w szczególności **31 dwójek obsługujących jednocześnie
    pary (cały pokój) i singli (łóżko) z jednej puli**;
  - **para nie dostaje dwóch luźnych łóżek z dwóch pokojów** (błąd v2);
  - **samotny w dwójce zdejmuje jedno łóżko, drugie dalej sprzedawalne** (błąd v1);
  - pakowanie: trzy samotne łóżka w dwójkach = dwa pokoje zajęte;
  - pakiet dwudobowy blokowany przez **drugą** dobę, nie pierwszą;
  - mapowanie legacy `occupancy` → `beds`/`whole_only` dla wszystkich trzech wartości,
    z zachowaniem dzisiejszej arytmetyki w trybie zgodności **bit w bit**;
  - slot `pakiet|pokój` trafiający w zapisane bookingi trybu zgodności;
  - `whole_only` przy wyłączonej towarzyszącej i przy `beds = 1` (normalizacja);
  - ofertę z dobami nad pokojem bez łóżek (zamknięta, **bez** wyjątku z `fromArray`);
  - zapisane `seats` większe od bieżącego `beds`;
  - dosypanie dób: dwa razy pod rząd oraz pakiet, który dostał drugą dobę po dosypaniu;
  - kasowanie wierszy dób na każdej ścieżce kasującej booking;
  - kaskadę przy usuwaniu doby (pakiety i `night_inventory`);
  - panel obłożenia dla pakietu z dobami;
  - `lockEvent` poprzedzający każdy odczyt zajętości.
- Dopisek do `CLAUDE.md`: pule dobowe, `beds` + `whole_only` zamiast `occupancy` (z
  **przeredagowaniem akapitu B-occupancy**), arytmetyka pakowania, inwariant
  podaży/popytu, nowy kontrakt DOM lustra. Wpis do `CHANGELOG.md` w `## [Unreleased]`.

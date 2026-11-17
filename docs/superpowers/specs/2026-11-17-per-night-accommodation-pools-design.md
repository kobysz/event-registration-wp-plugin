# Pule noclegowe per doba — projekt

**Data:** 2026-11-17
**Status:** spec do zatwierdzenia

**Historia wersji** (bo dwie pierwsze były błędne i warto wiedzieć, dlaczego):
v1 liczyła pulę w pokojach, jedna rezerwacja = jeden pokój — gubiła dwójkę sprzedawaną
na łóżka. v2 liczyła pulę w łóżkach — gubiła fakt, że para potrzebuje **jednego** pokoju,
a nie dwóch dowolnych łóżek, i trzymała osobne pule dla każdego wariantu sprzedaży tego
samego zapasu pokoi. v3 (ta) liczy **pokoje**, ale popyt pakuje: samotne łóżka po dwa
do pokoju, para zajmuje pokój w całości.

## Intencja i dlaczego

Dziś limit noclegów należy do **pozycji inwentarza**, czyli pary `(pakiet × pokój)`.
`Package` nie wie nic o dobach — to klucz i etykieta. Slot to string `"pakiet|pokój"`,
a zajętość liczy jedno `GROUP BY CONCAT(package_key, '|', room_type_key)`.

Skutek: każda kombinacja dób ma **rozłączną** pulę. Organizator, który fizycznie ma
10 jedynek na noc 18/19, musi ten stan ręcznie podzielić między pakiet „tylko 18/19"
i pakiet „18–20", zgadując proporcje z góry i sam pilnując, żeby suma się trzymała.
Pule nie rozmawiają ze sobą, więc **nie da się tego skonfigurować poprawnie** — to brak
w modelu, nie pomyłka w ustawieniach. Rezerwacja na dwie doby nie zdejmuje nic z puli
pojedynczej doby, choć fizycznie zajmuje ten pokój w obie noce.

Zmiana: doba staje się pierwszoklasowa. Limit mieszka na parze `(doba × typ pokoju)`
i jest wpisywany **w pokojach**, a rezerwacja zajmuje pokoje w każdej dobie swojego
pakietu.

### Przykład prowadzący 1 — dwie doby

Doby `18/19` i `19/20`. Limity per doba: jedynki 10 i 22, dwójki 31 i 43. Pakiety:
`{18/19}`, `{19/20}`, `{18/19, 19/20}`. Uczestnik wybiera „dwie doby + pokój
jednoosobowy" → zdejmuje jeden pokój z puli 18/19 **oraz** jeden z puli 19/20. Pozycja
jest zamknięta, gdy wyczerpana jest którakolwiek z jej dób.

### Przykład prowadzący 2 — dwójka na łóżka i dwójka w całości, z jednego zapasu

Hotel ma 31 pokoi dwuosobowych na noc 18/19 i nic więcej. Z tego samego zapasu mają
być sprzedawane **jednocześnie**:

- **miejsce w pokoju dwuosobowym** — uczestnik bez towarzyszącej bierze jedno łóżko,
  drugie zostaje do sprzedania komuś innemu (hotel dokwateruje),
- **cały pokój dwuosobowy** — uczestnik z osobą towarzyszącą zajmuje oba łóżka.

Bez dzielenia tych 31 pokoi z góry na dwie grupy. To wymusza dwie rzeczy, których
nie miały poprzednie wersje: limit należy do **fizycznego zapasu pokoi**, nie do
wariantu sprzedaży, a popyt musi być liczony w pokojach z pakowaniem samotnych łóżek.

## Cele

1. Limit noclegów per `(doba × typ pokoju)`, niezależny od pakietów, wpisywany
   **w pokojach**.
2. Pakiet deklaruje listę dób; rezerwacja zajmuje pokoje w każdej z nich.
3. Z jednego zapasu pokoi dają się sprzedawać **jednocześnie** całe pokoje i pojedyncze
   łóżka, bez ręcznego dzielenia zapasu.
4. Popyt liczony tak, żeby **para zawsze dostała jeden pokój**: samotne łóżka pakowane
   po `beds` do pokoju, rezerwacja potrzebująca całego pokoju bierze pokój.
5. Dostępność pozycji = AND po dobach pakietu; „ile zostało" = minimum po dobach.
6. Wyczerpana doba zamyka pozycję **na każdej powierzchni naraz** — render, `reserve`,
   `editAnswers`, `promoteFromWaitlist`, panel obłożenia.
7. Zero regresji dla eventów skonfigurowanych po staremu.

## Nie-cele

- **Doby na formularzu publicznym** (uczestnik zaznacza doby, pakiet znika z UI). Osobna,
  większa zmiana; stoi na pulach dobowych, więc ten projekt jest jej warunkiem wstępnym.
  Pozycja backlogu.
- Cena per doba ani rabat za wiele dób. Cena zostaje na pozycji oferty.
- **Parowanie współlokatorów.** Wtyczka nie wiąże dwóch zgłoszeń w jeden fizyczny pokój
  i nie pilnuje, kto z kim leży. `roommate_pref` zostaje preferencją do ręcznego
  kwaterowania. Pula wie tylko, ile pokoi jest zajętych — przy założeniu, że hotel
  dokwateruje optymalnie (dwa samotne łóżka trafiają do jednego pokoju). To samo
  założenie, które organizator i tak realizuje ręcznie.
- Numery konkretnych pokoi, lista kwaterunkowa z przydziałem.
- Doby wspólne między wydarzeniami.
- Pojęcie „bez limitu". `0` dalej znaczy „brak miejsc".

## Anty-cele (porażka, nawet gdyby działało)

- **Ręczne dzielenie zapasu.** Ani między pakietami (dziś), ani między wariantami
  sprzedaży (błąd v2). Limit jest jeden na `(doba × pokój)`.
- **Mnożenie przez liczbę łóżek po stronie organizatora.** Limit wpisuje się w pokojach.
- **Zgubienie drugiego łóżka** w dwójce sprzedawanej na łóżka (błąd v1).
- **Para bez pokoju.** Dwa wolne łóżka w dwóch różnych pokojach nie są miejscem dla pary
  (błąd v2) — to przesprzedaż widoczna dopiero w hotelu.
- **Ciche podwojenie limitu** przy degradacji konfiguracji sprzecznej z wyłączoną osobą
  towarzyszącą.
- **Doliczanie dób z konfiguracji w momencie liczenia.** Zmiana nazwy albo usunięcie
  pakietu przeliczyłoby zajętość w tył. Lekcja opłacona w 0.1.14.
- **Jakikolwiek odczyt zajętości przed `lockEvent()`.** Inwariant 3A bez zmian.
- **Przyjęcie zgłoszenia bez noclegu, o który prosiło, bez powiedzenia tego wprost**
  (B-slots): pełna pula → ROLLBACK i `accommodationFull`.
- **Wyjątek z `AccommodationConfig::fromArray` na ścieżce renderu publicznego.** Ta
  metoda jest wołana z `FormRenderer`, `ReservationService`, panelu, eksportu i
  eksportera prywatności — rzucenie kładzie **cały formularz** („Rejestracja jest
  niedostępna"), zamiast zamknąć jeden wiersz. Nowe sprzeczności konfiguracji zamykają
  wiersz i są widoczne w adminie; wyjątek zostaje tylko dla odniesień do nieistniejących
  kluczy, których UI nie umie wyprodukować.
- **Druga kopia arytmetyki.** `RoomOccupancy` zostaje jedynym domem reguły łóżek; nowa
  arytmetyka pokoi ma jeden dom w czystej domenie i jedno lustro w `form.js`.

## Ograniczenia

- `src/Domain/**` bez WordPressa i bez `$wpdb` (`DomainPurityTest`).
- PHPStan 6 bez obniżania, `phpcs` czysto, wszystkie stringi przez i18n.
- Mutacje konfiguracji w adminie w `assets/admin/ops/accommodationOps.js`, testowane jest.
- `dbDelta`, idempotentnie; `Migrations::DB_VERSION` 6 → 7.
- Lustro `form.js` musi dawać ten sam wynik co domena.

## Podejścia

**1. Zostawić jak jest, dzielić stan ręcznie.** Zero kodu, ale model tego nie wyraża:
rezerwacja dwudobowa nie zdejmuje nic z puli pojedynczej doby. Odrzucone.

**2. Pule dobowe pod zachowanym wyborem pakietu — WYBRANE.** Uczestnik widzi listę jak
dziś (pozycja = oferta pakietu), zmienia się to, skąd bierze się sąd o dostępności.

**3. Doby wprost na formularzu** (wybór pokoju + zaznaczenie dób). Koncepcyjnie
najprostsze dla uczestnika, ale rusza render, submit, `AccommodationSelection`,
kardynalność bookingu, cenę, admina, CSV, mail i e2e. Odłożone: stoi na podejściu 2.

## Model: zapas, oferta, doba

Trzy pojęcia, z czego dwa już istnieją. Rozdzielenie, którego brakowało, przebiega
między **fizycznym zapasem** a **wariantem sprzedaży**.

**Typ pokoju = fizyczny zapas.** Niesie tylko fakty o pokoju:

| pole | wartości | znaczenie |
|---|---|---|
| `key`, `label` | — | jak dziś |
| `beds` | `0` (nieustawione), `1`, `2` | ile łóżek ma pokój |
| `roommate_field` | bool | czy pytać o preferencję współlokatora |

**Pozycja inwentarza = oferta.** Para `(pakiet × pokój)` zyskuje wariant sprzedaży:

| pole | wartości | znaczenie |
|---|---|---|
| `package`, `room` | — | jak dziś |
| `sale` | `bed` (domyślne), `whole` | jedno łóżko czy cały pokój |
| `price` | — | cena **za łóżko**, jak dziś (patrz „Cena") |
| `capacity` | — | limit TYLKO w trybie zgodności (patrz „Uśpiona pojemność") |

`sale` siedzi na ofercie, nie na pokoju, bo to właśnie tu różnią się dwie rzeczy, które
muszą współistnieć nad jednym zapasem: wariant sprzedaży i cena. Ten sam pokój
dwuosobowy może więc w jednym pakiecie wystąpić dwa razy — raz jako „miejsce w pokoju
2-os." (`sale: bed`), raz jako „pokój 2-os. z osobą towarzyszącą" (`sale: whole`) — i oba
czerpią z tej samej puli 31 pokoi.

**Doba** to nowy, trywialny byt: `key` + `label`. Pakiet deklaruje listę dób.

### Ile łóżek zjada rezerwacja

`RoomOccupancy::seats( int $beds, string $sale, bool $companion ): int` — **jedyny dom
reguły**: `sale = whole` i `beds > 0` → `beds`; w każdym innym przypadku →
`companion ? 2 : 1`. Delegują do niej `RoomType::seats`,
`AccommodationConfig::seatsFor`, `CapacityLimits::seatsForSlot` i lustro w `form.js`.

### Spójność pary oferta/towarzysząca

`companionMismatch( RoomType $room, string $sale, bool $companion ): ?string`:

- `sale = whole` **i `beds = 2`** bez towarzyszącej → `companion_required`,
- `beds = 1` z towarzyszącą → `companion_not_allowed`,
- `beds = 0` → nigdy nie protestuje (zgodność w tył),
- `beds = 2`, `sale = bed`, bez towarzyszącej → **poprawne**; to przykład prowadzący 2.

`sale` jest bezprzedmiotowe dla `beds = 1` (cały pokój jednoosobowy to to samo co jego
jedno łóżko) i `fromArray` **normalizuje** je wtedy do `bed`, zamiast rzucać. Bez tej
normalizacji `beds = 1` + `sale = whole` byłoby pokojem niewybieralnym na żaden stan
checkboxa: żądałoby towarzyszącej i jednocześnie jej zabraniało.

Pole „preferowany współlokator" ma sens tylko przy `sale = bed` i `beds > 1`; admin
wyłącza je w pozostałych przypadkach, a renderer go tam nie pokazuje.

## Jednostka puli: limit w pokojach, popyt w pokojach z pakowaniem

Organizator wpisuje limit dobowy **w pokojach**. Popyt liczony jest też w pokojach, ale
rezerwacje nie są równe:

- rezerwacja potrzebująca **`beds` łóżek** (cały pokój albo łóżko + towarzysząca)
  zajmuje **jeden pokój na wyłączność**,
- rezerwacje potrzebujące **mniej** (samotne łóżko) są **pakowane**: `beds` takich
  rezerwacji dzieli jeden pokój.

Dla pary `(doba, pokój)` zajętość to więc **dwie liczby**, nie jedna:

```
pokoje_zajęte = pełne + ceil( luźne_łóżka / beds )

  pełne        = liczba rezerwacji z seats >= beds
  luźne_łóżka  = suma seats rezerwacji z seats < beds
```

Bramka dla nowej rezerwacji potrzebującej `s` łóżek:

```
s >= beds :  pełne + 1 + ceil( luźne_łóżka / beds )        <= limit_pokoi
s <  beds :  pełne     + ceil( (luźne_łóżka + s) / beds )  <= limit_pokoi
```

Oba warunki są dokładne przy optymalnym dokwaterowaniu, czyli przy tym, co hotel i tak
robi ręcznie. Konsekwencja, która zamyka błąd v2: para nigdy nie dostanie „dwóch wolnych
łóżek w dwóch pokojach" — jej wariant zawsze żąda pokoju na wyłączność.

Zwróć uwagę, że wariant sprzedaży **nie jest** potrzebny do liczenia: rozstrzyga
`seats` względem `beds`. Dlatego wiersz doby nie przechowuje `sale`.

**Każdy pokój biorący udział w puli dobowej musi mieć `beds > 0`** — bez liczby łóżek
nie ma czym pakować. Oferta łącząca pakiet z dobami z pokojem o `beds = 0` jest
**zamknięta** (traktowana jak brak pojemności) i zgłaszana w adminie; `fromArray` tu
nie rzuca (patrz anty-cele).

### Dostępność i komunikaty

`SlotAvailability` dla oferty z dobami liczy po dobach minimum i odpowiada na dwa
pytania osobno, bo mają różne odpowiedzi:

- czy zmieści się **pokój na wyłączność** (`pełne + 1 + ceil(luźne/beds) <= limit`),
- ile zostało **łóżek dla samotnych** (`(limit - pełne) * beds - luźne_łóżka`).

Stąd komunikaty: `(brak miejsc)`, gdy nic się nie zmieści, i `(za mało miejsc)`, gdy
wybrany wariant potrzebuje pokoju, a zostały tylko luźne łóżka. `isFull( $slot, $seats )`
zachowuje sygnaturę; `$seats` rozstrzyga, które z dwóch pytań zadać.

## Cena

Bez zmian w mechanice: `PriceCalculator::total` mnoży cenę oferty przez `seats`. Czyli
**cena to cena za łóżko**: samotne łóżko ×1, towarzysząca ×2, cały pokój ×`beds`. Tak
jest dziś i tak zostaje — ale admin musi to napisać wprost przy macierzy cen, bo przy
dwóch wariantach nad jednym zapasem pomyłka „za pokój czy za osobę" jest kosztowna.

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
        // Już tylko fakty fizyczne. `beds` zastępuje `occupancy` (czytane jako legacy).
        array( 'key' => 'single', 'label' => 'Pokój 1-osobowy', 'beds' => 1 ),
        array( 'key' => 'double', 'label' => 'Pokój 2-osobowy', 'beds' => 2, 'roommate_field' => true ),
    ),
    'packages'        => array(
        array( 'key' => 'p18', 'label' => 'Doba 18/19', 'nights' => array( 'n1819' ) ),
        array( 'key' => 'p19', 'label' => 'Doba 19/20', 'nights' => array( 'n1920' ) ),
        array( 'key' => 'p2',  'label' => 'Dwie doby',  'nights' => array( 'n1819', 'n1920' ) ),
    ),
    'inventory'       => array(
        // Ten sam pokój `double` dwa razy w jednym pakiecie: dwa warianty sprzedaży
        // nad JEDNYM zapasem 31 pokoi. Różnią się `sale` i ceną.
        array( 'package' => 'p2', 'room' => 'single', 'sale' => 'bed',   'price' => 400.0, 'capacity' => 10 ),
        array( 'package' => 'p2', 'room' => 'double', 'sale' => 'bed',   'price' => 300.0, 'capacity' => 20 ),
        array( 'package' => 'p2', 'room' => 'double', 'sale' => 'whole', 'price' => 300.0, 'capacity' => 20 ),
    ),
    'night_inventory' => array(
        // Limit POKOI per doba × typ pokoju. Jeden na zapas, nie na wariant.
        array( 'night' => 'n1819', 'room' => 'single', 'capacity' => 10 ),
        array( 'night' => 'n1819', 'room' => 'double', 'capacity' => 31 ),
        array( 'night' => 'n1920', 'room' => 'single', 'capacity' => 22 ),
        array( 'night' => 'n1920', 'room' => 'double', 'capacity' => 43 ),
    ),
    // allow_none, companion_enabled, companion_counts_event — bez zmian.
)
```

### Klucz slotu i zgodność w tył

Slot identyfikuje ofertę, a oferty różnią się teraz też wariantem sprzedaży. Dlatego:

- `InventoryItem::slotKey()` zwraca `"pakiet|pokój"` dla `sale = bed` (wartość domyślna)
  i `"pakiet|pokój|whole"` dla `sale = whole`.
- Powód jest twardy: zapisane bookingi i dotychczasowe zapytanie
  `GROUP BY CONCAT(package_key, '|', room_type_key)` **muszą dalej trafiać w ten sam
  klucz**. Gdyby domyślny slot zmienił kształt, tryb zgodności przestałby widzieć swoje
  rezerwacje — cicho, bo zapytanie dalej by działało, tylko na pustym zbiorze.
- Booking dostaje kolumnę `sale`, żeby edycja i panel wiedziały, który wariant został
  kupiony; stare wiersze czytane są jako `bed`.
- Zduplikowana oferta to teraz `(pakiet, pokój, sale)`, nie `(pakiet, pokój)`.

`RoomType::fromArray` czyta `beds`, a gdy go nie ma — legacy `occupancy`:

| stary config | nowy model | zachowanie |
|---|---|---|
| `occupancy = 2` | `beds = 2`, oferty `sale = whole` | bez zmian: wymaga towarzyszącej, 2 łóżka |
| `occupancy = 1` | `beds = 1`, oferty `sale = bed` | bez zmian: zabrania towarzyszącej, 1 łóżko |
| `occupancy = 0` | `beds = 0`, oferty `sale = bed` | bez zmian: nie protestuje, 1 albo 2 łóżka |

`toArray` zapisuje `beds`; odczyt `occupancy` zostaje na stałe, więc konfiguracje na
istniejących stronach nie wymagają migracji ani wejścia do edytora.

### Sprzeczność z wyłączoną osobą towarzyszącą

Dzisiejsza reguła („przy wyłączonej towarzyszącej zeruj `occupancy`") **zostaje
zastąpiona**, bo jej dosłowne przeniesienie podwajałoby limit: 31 pokoi × 2 łóżka
= 62 uczestników tam, gdzie dziś wychodzi 31.

Nowa postać: przy wyłączonej osobie towarzyszącej oferta `sale = whole` jest
**zamknięta** (niewybieralna, z komunikatem w adminie), a nie zamieniana na sprzedaż
łóżek. Nikt nie dostaje po cichu dokwaterowania obcych tam, gdzie konfigurował całe
pokoje. Liczba łóżek pokoju **zostaje** — jest potrzebna do pakowania.

Tryb zgodności (pakiet bez dób, legacy `occupancy = 2`) zachowuje się **dokładnie jak
dziś**, bit w bit: limit w miejscach, rezerwacja zjada jedno miejsce.

### Walidacja

`AccommodationConfig::fromArray` rzuca `SchemaException` tylko dla odniesień, których UI
nie umie wyprodukować, jak dziś: nieznany/duplikujący się klucz doby, `packages[].nights`
wskazujące na nieznaną dobę, `night_inventory` na nieznaną dobę lub pokój, zduplikowana
para `(doba, pokój)`, zduplikowana oferta `(pakiet, pokój, sale)`, ujemna pojemność lub
cena, `beds` spoza `0..2`, `sale` poza `bed`/`whole`.

**Nie rzuca** (zamyka wiersz lub normalizuje, i zgłasza w adminie): oferta z dobami nad
pokojem o `beds = 0`; `sale = whole` przy `beds = 1` (normalizacja do `bed`);
`sale = whole` przy wyłączonej towarzyszącej; niezerowa uśpiona `capacity`.

### Tryb zgodności

Pakiet bez dób działa jak dziś: limit z `inventory[...].capacity`, pula w miejscach
(`SUM(seats)` po slocie). Jeden event może mieszać pakiety z dobami i bez — decyzja jest
per pakiet.

**Znana i przyjęta granica:** dwa pakiety nad tym samym pokojem, jeden z dobami i jeden
bez, sprzedają ten sam fizyczny zapas z dwóch niezależnych pul. Wtyczka tego nie wykryje;
admin ostrzega, gdy w jednym evencie istnieją oba rodzaje pakietów nad tym samym pokojem.
Docelowo pakiety bez dób znikają z nowych konfiguracji.

### Uśpiona pojemność oferty

Gdy pakiet ma doby, `capacity` oferty **przestaje być limitem, ale zostaje zapisana** —
admin jej nie zeruje. Wyszarza komórkę z notką „limit idzie z dób", a przy **zdejmowaniu**
dób z pakietu mówi wprost, jaki limit wróci do gry (łącznie z tym, że `0` zamyka pozycję).

### Prawda o podaży i popycie

> **Wiersze dób bookingu są prawdą o popycie. Konfiguracja jest prawdą o podaży i o tym,
> czym bramkować nowy popyt.**

Ile rezerwacja zjada — mówią jej zapisane wiersze dób z zapisanym `seats`, nigdy bieżąca
konfiguracja. Czy nowa rezerwacja idzie przez pule dobowe czy slotowe — mówi bieżąca
konfiguracja pakietu.

Dwa rozjazdy i co z nimi:

- **Rezerwacja bez wierszy dób w pakiecie, który ma doby** — nie zjada nic z pul
  dobowych. Zamyka to dosypanie dób, nie zmiana reguły liczenia.
- **Wiersze dób na pakiecie, któremu doby zdjęto** — dalej zjadają pule dobowe. Wiersze
  zostają (kasowanie historii jest gorsze), rozjazd jest widoczny w panelu obłożenia,
  a admin ostrzega przy zdejmowaniu dób.

### Dosypanie dób istniejącym rezerwacjom

Włączenie dób na pakiecie z aktywnymi rezerwacjami wymaga **jawnej akcji w adminie**
(„przypisz doby istniejącym rezerwacjom"): dla każdej zajmującej miejsce rezerwacji tego
pakietu zapisuje **brakujące** doby — per doba, nie per rezerwacja.

- Idempotentna na poziomie `(booking, doba)`, nie „pomiń rezerwacje, które mają
  jakiekolwiek wiersze". Inaczej pakiet, który **dostał drugą dobę** po dosypaniu,
  zostawiłby stare rezerwacje policzone tylko w pierwszej.
- Licznik „nie widziane przez pule dobowe" liczy rezerwacje z **niepełnym** pokryciem dób
  swojego pakietu, nie tylko te z zerowym. Inaczej byłby ślepy na ten sam przypadek.
- Wykonywana pod lockiem eventu; jawna, nie odpalana z zapisu konfiguracji.
- `seats` dla dosypywanych wierszy bierze się z zapisanego bookingu, nie przelicza
  z bieżącej konfiguracji.
- **Jeśli event nie zdążył zebrać żadnej rezerwacji z noclegiem**, ta część jest martwa
  i może wypaść z zakresu (jeden task mniej) — do potwierdzenia przed planem.

## Model bazy

`Migrations::DB_VERSION` → `7`. Nowa tabela potomna + jedna kolumna na bookingu.

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

`evreg_accommodation_bookings` dostaje `sale varchar(10) NOT NULL DEFAULT 'bed'`.

- **Osobne wiersze, nie JSON:** zajętość musi dać się policzyć jednym `GROUP BY` pod
  lockiem, tą samą drogą co dziś.
- **`seats` jest też tutaj**, choć stoi na bookingu: zapytanie liczące pakuje łóżka
  i rozdziela rezerwacje pełne od luźnych, więc wiersz musi być samowystarczalny, bez
  JOIN-a po liczbę łóżek. Zapisywany raz, w tej samej transakcji co booking.
- **`registration_id` zdenormalizowany** (booking jest 1:1 ze zgłoszeniem), bo
  `occupancyExcluding` i twarde kasowanie filtrują po zgłoszeniu.
- **`night_label` to snapshot**, jak `package_label`/`room_label` od 0.1.14.
- Eraser prywatności **nie tyka** tych wierszy (brak danych osobowych; usunięcie
  rozjechałoby zajętość) — jak zostawia status, typ, cenę i daty.
- `Uninstaller::TABLES` dostaje nową nazwę (cztery tabele → pięć).

## Domena

- **`Night`** (nowy): `key`, `label`, `fromArray`, `toArray` — bliźniak `Package`.
- **`Package`**: `nights` (`string[]`, domyślnie pusta) + `usesNightPools()`.
- **`RoomType`**: `occupancy` → `beds`; `seats( string $sale, bool $companion )`;
  `companionMismatch( string $sale, bool $companion )`.
- **`InventoryItem`**: `sale` + `slotKey()` wg reguły wyżej.
- **`RoomOccupancy::seats( int $beds, string $sale, bool $companion )`** — jedyny dom
  reguły łóżek.
- **`NightOccupancy`** (nowy, czysty): jedyny dom arytmetyki pokoi —
  `roomsUsed( int $full, int $looseSeats, int $beds ): int` oraz
  `fits( int $full, int $looseSeats, int $beds, int $limit, int $seats ): bool`.
  Trzymane osobno od `RoomOccupancy`, bo to inna reguła (pakowanie), i obie mają być
  testowalne wprost.
- **`OccupancySnapshot`**: mapa `perNightRoom` z **parą** liczb (`full`, `looseSeats`)
  per `"doba|pokój"` + akcesor. Konstruktor dostaje ją jako kolejny parametr z domyślną
  pustą tablicą.
- **`CapacityLimits`**: mapy `nightRoomCapacity` (w pokojach), `packageNights`,
  `roomBeds` — wszystkie z domyślną pustą tablicą (wzorem `perSlotSeats` z B-occupancy).
- **`CapacityCalculator::decide`**: gdy pakiet oferty ma doby, dla **każdej** doby woła
  `NightOccupancy::fits(...)`; pierwsza niepasująca kończy sprawdzanie i daje
  `accommodation_full`. Limit globalny i limit typu bez zmian, przed bramką noclegową.
- **`SlotAvailability`**: `fromSnapshot` czyta pule dobowe; `left()` i `isFull()` wg
  sekcji „Dostępność i komunikaty". Tryb zgodności bez zmian.

## Ścieżki transakcyjne

`reserve`, `editAnswers`, `promoteFromWaitlist` w `ReservationService`. Każda:

1. `lockEvent($event_id)` **przed** pierwszym odczytem zajętości (bez zmian),
2. buduje `CapacityLimits` z mapami dobowymi, `packageNights` i `roomBeds`,
3. `decide(...)`, już świadome dób,
4. przy przyznanym noclegu wstawia booking (z `sale`) **oraz** wiersze dób (z `seats`)
   w tej samej transakcji,
5. przy pełnej puli: `reserve` → ROLLBACK + `accommodationFull`; `editAnswers` →
   odrzucenie edycji; `promoteFromWaitlist` → awans z `promoted_no_accommodation`.

`editAnswers`: edycja **nie zmieniająca** ani slotu, ani flagi towarzyszącej zachowuje
zapisane miejsca, cenę **i wiersze dób** — nie przelicza ich i pomija bramkę slotową.

Booking czytany **pod lockiem** (`lockEvent` → `findAccommodationBooking` → `occupancy*`).

## Repozytorium (jedyne SQL)

- `occupancy` / `occupancyExcluding` dostają zapytanie o pule dobowe, rozdzielające
  rezerwacje pełne od luźnych; `beds` do porównania przychodzi z konfiguracji, więc
  zapytanie zwraca `GROUP BY night_key, room_type_key, seats`, a agregacja w parę
  (`full`, `looseSeats`) odbywa się nad kilkunastowierszowym wynikiem. Dotychczasowe
  zapytanie slotowe **zostaje** — tryb zgodności z niego żyje.
- `insertAccommodationBooking` wstawia też wiersze dób; potrzebuje `insert_id`.
- **Wiersze dób giną razem z bookingiem na KAŻDEJ ścieżce**, która go kasuje: `cancel`,
  `editAnswers` (zmiana slotu), `promoteFromWaitlist`, `deleteRegistration`. Kasowanie
  dób siedzi w tej samej metodzie repozytorium co kasowanie bookingu, żeby nie dało się
  wywołać jednego bez drugiego.
- Bulk odczyt dób dla wielu rezerwacji (wzorem `accommodationBookingsFor`), żeby eksport
  nie robił N+1.
- Zapytanie dla dosypania dób: aktywne rezerwacje pakietu z brakującymi dobami.

## Admin (React)

- **Pokój**: „Łóżka" (1 / 2 / nieustawione) zamiast „Liczby osób"; „preferowany
  współlokator" aktywne tylko gdy pokój jest w jakiejkolwiek ofercie `sale = bed`
  i `beds > 1`.
- **Oferta**: macierz `pakiet × pokój` zyskuje wariant sprzedaży — ten sam pokój może
  wystąpić dwa razy z różną ceną. Przy cenie napisane wprost, że jest **za łóżko**.
  Komórka pojemności wyszarzona z notką, gdy pakiet ma doby.
- **Lista dób** (dodaj / usuń / klucz / etykieta) — bliźniak listy pakietów.
- **Pakiet**: wybór dób (wielokrotny). Pakiet bez dób komunikuje tryb zgodności wprost.
- **Macierz `doba × pokój`**: limit w pokojach, obok informacja, ile to łóżek
  (`limit × beds`). Macierz pokrywa wszystkie pokoje × doby; **dodanie doby zamyka
  każdy pokój, dopóki jej wiersz nie jest wypełniony** i musi to powiedzieć tak samo
  głośno, jak ostrzeżenie przy usuwaniu doby.
- **Akcja „przypisz doby istniejącym rezerwacjom"** z liczbą rezerwacji i jawnym
  potwierdzeniem.
- **Ostrzeżenia**: oferta z dobami nad pokojem bez łóżek; `sale = whole` przy wyłączonej
  towarzyszącej; usunięcie doby, do której odwołują się pakiety albo bookingi; zdjęcie
  dób z pakietu; współistnienie pakietów z dobami i bez nad tym samym pokojem.

`EventConfigController::sanitize` jest generyczny i rekurencyjny, więc nowe klucze
przechodzą bez zmian — do potwierdzenia testem.

### Panel obłożenia

`RegistrationsOverview::render_slots()` bierze dziś `taken/capacity/free` wprost
z `InventoryItem::capacity`, która dla pakietu z dobami jest uśpiona — panel pokazywałby
„wolne 0", gdy formularz te pokoje sprzedaje. To powierzchnia, na którą patrzy
organizator, więc cel 6 obejmuje ją wprost:

- `OccupancyReport` dostaje czystą metodę per `(doba × pokój)`: limit w pokojach, pokoje
  zajęte (pełne + spakowane luźne), wolne pokoje i wolne luźne łóżka.
- Admin renderuje tabelę „Noclegi per doba".
- Wiersze slotowe pakietów z dobami oznaczone jako nieobowiązujące, zamiast mylącego zera.
- Liczba rezerwacji z niepełnym pokryciem dób **wstrzykiwana** do raportu (nie odpytywana
  z czystej domeny) i pokazana tutaj.

## Wielojęzyczność

Etykiety dób tłumaczalne per język jak pakiety i pokoje (B6): overlay
`_evreg_i18n[lang].accommodation` zyskuje bucket `nights: { key: label }`,
`ContentTranslator::translateAccommodation` nakłada go przed `SchemaAssembler`, a bucket
wchodzi do `translatableItems`. Tylko etykiety — klucze, pojemności, łóżka i ceny
nietknięte.

## Render publiczny i lustro

Lista ofert wygląda jak dziś. Zmienia się źródło sądu „zamknięte" oraz **kontrakt DOM**,
bo dzisiejsze jedno `data-evreg-occupancy` nie wyraża nowych reguł:
`beds = 2, sale = bed` bez towarzyszącej musi być poprawne, a dziś `occupancy = 2` znaczy
niespójność.

- Wiersz oferty emituje `data-evreg-beds` **i** `data-evreg-sale` (zamiast
  `data-evreg-occupancy`), plus `data-evreg-seats-left` z minimum po dobach.
- `form.js` dostaje przepisane **dwie** reguły: lustro `seats` (`RoomOccupancy`) i lustro
  niespójności (`companionMismatch`). To **zmiana w `form.js`**, nie „bez zmian" —
  wcześniejsza wersja tego speca twierdziła inaczej i była w tym miejscu zwyczajnie zła.
- Wyczerpana oferta dostaje `disabled` + dopisek i **nigdy nie renderuje się jako
  `checked`** (inwariant B-slots: `disabled` nie wysyła wartości, więc zaznaczony-
  i-wyłączony radio dałby po submicie pusty `[slot]`, czyli ciche „Bez noclegu").
- Inwariant lustra w `CLAUDE.md` przeredagowany na parę `beds`/`sale`.

Nazwanie blokującej doby w komunikacie („brak miejsc w nocy 19/20") — osobna decyzja
produktowa, poza zakresem.

## Eksport i detal zgłoszenia

- **Detal zgłoszenia**: doby obok pakietu i pokoju, z etykiet zapisanych na wierszach;
  wariant sprzedaży widoczny.
- **Eksport CSV**: nowa kolumna „Doby" w `RegistrationExportMapper::accommodationCells`
  (czysta domena, własne testy), zasilana bulk odczytem. Lista kwaterunkowa dla hotelu
  powstaje z eksportu, nie z klikania po detalach.

Mail i szablony bez zmian — żaden placeholder nie dochodzi.

## Co zostaje builderowi

- Nazwy metod i klas wg konwencji repo; `sale` jako string czy enum (walidowane w jednym
  miejscu).
- Kolejność tasków i granice commitów; kształt map w `CapacityLimits`.
- Kształt testów, pod warunkiem że pokrywają:
  - oba przykłady prowadzące, w szczególności **31 dwójek sprzedawanych jednocześnie na
    całe pokoje i na łóżka z jednej puli**;
  - **para nie dostaje dwóch luźnych łóżek z dwóch pokojów** (błąd v2);
  - **samotny w dwójce zdejmuje jedno łóżko, drugie dalej sprzedawalne** (błąd v1);
  - pakowanie: trzy samotne łóżka w dwójkach = dwa pokoje zajęte;
  - pakiet dwudobowy blokowany przez **drugą** dobę, nie pierwszą;
  - mapowanie legacy `occupancy` → `beds` dla wszystkich trzech wartości, z zachowaniem
    dzisiejszej arytmetyki w trybie zgodności;
  - `sale = whole` przy wyłączonej towarzyszącej (zamknięte, nie podwojone);
  - normalizację `sale = whole` przy `beds = 1`;
  - ofertę z dobami nad pokojem bez łóżek (zamknięta, **bez** wyjątku z `fromArray`);
  - slot `pakiet|pokój` dla `sale = bed` trafiający w zapisane bookingi trybu zgodności;
  - dosypanie dób: dwa razy pod rząd oraz pakiet, który dostał drugą dobę po dosypaniu;
  - kasowanie wierszy dób na każdej ścieżce kasującej booking;
  - panel obłożenia dla pakietu z dobami;
  - `lockEvent` poprzedzający każdy odczyt zajętości.
- Dopisek do `CLAUDE.md`: pule dobowe, `beds` + `sale` zamiast `occupancy` (z
  **przeredagowaniem akapitu B-occupancy**), arytmetyka pakowania, inwariant
  podaży/popytu, nowy kontrakt DOM lustra. Wpis do `CHANGELOG.md` w `## [Unreleased]`.

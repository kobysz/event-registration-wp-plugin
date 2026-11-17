# Pule noclegowe per doba — projekt

**Data:** 2026-11-17
**Status:** spec do zatwierdzenia (wersja 2 — przebudowana po pytaniu o dwójkę
sprzedawaną na łóżka; wersja 1 liczyła pulę w pokojach i ten przypadek gubiła)

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
pakiet jest zbiorem dób, a rezerwacja zdejmuje swoje łóżka z puli **każdej** doby
swojego pakietu.

### Przykład prowadzący (wydarzenie zamawiającego)

Doby: `18/19` i `19/20` grudnia 2026. Limity ustawiane osobno dla każdej doby:
jedynki i dwójki per doba. Pakiety: `{18/19}`, `{19/20}`, `{18/19, 19/20}` — każdy
dostępny w wariancie pokoju jedno- i dwuosobowego, i tam (jak dziś) siedzi cena.

Uczestnik wybiera „dwie doby + pokój jednoosobowy" → zdejmuje **jedno łóżko z puli
18/19 oraz jedno z puli 19/20**. Jeśli którakolwiek z tych dwóch pul jest wyczerpana,
pozycja jest zamknięta.

### Drugi przypadek prowadzący: dwójka sprzedawana na łóżka

Hotel ma tylko pokoje dwuosobowe. Uczestnik bez osoby towarzyszącej ma móc wziąć
**jedno łóżko** w takim pokoju, a drugie zostaje do sprzedania komuś innemu.

To działa dziś, ale tylko przez niedopowiedzenie: pokój z nieustawioną „liczbą osób"
(`occupancy = 0`) zdejmuje jedno miejsce samotnie i dwa z towarzyszącą, a pula jest
liczona w miejscach, które organizator wpisuje ręcznie. Pokój nie deklaruje, ile ma
łóżek, więc pojemność nie da się przeliczyć z liczby pokoi. Pokój z `occupancy = 2`
tego przypadku **nie obsługuje wcale** — `companionMismatch` żąda wtedy towarzyszącej.

Ten projekt robi z tego jawny model (sekcja „Model pokoju").

## Cele

1. Limit noclegów definiowany per `(doba × typ pokoju)`, niezależnie od pakietów,
   **wpisywany w pokojach**.
2. Pakiet deklaruje listę dób; rezerwacja zjada swoje łóżka z każdej z nich.
3. Pokój dwuosobowy daje się sprzedać **albo jako cały pokój** (wymaga towarzyszącej),
   **albo na łóżka** (samotny uczestnik bierze jedno, drugie zostaje wolne) — jawnie,
   nie przez brak ustawienia.
4. Dostępność pozycji `(pakiet × pokój)` = AND po dobach pakietu; „ile zostało" =
   minimum po dobach.
5. Wyczerpana doba zamyka pozycję **na każdej powierzchni naraz** — render publiczny,
   `reserve`, `editAnswers`, `promoteFromWaitlist`, panel obłożenia — bez cichego
   przyjęcia bez łóżka.
6. Zero regresji dla eventów skonfigurowanych po staremu.

## Nie-cele

- **Doby na formularzu publicznym** (uczestnik zaznacza doby, pakiet znika z UI). To
  osobna, większa zmiana (render, submit, `AccommodationSelection`, cena, admin, CSV,
  mail, e2e). Stoi na pulach dobowych, więc ten projekt jest jej warunkiem wstępnym —
  ale nie wchodzi tu. Osobna pozycja backlogu.
- Cena per doba ani rabat za wiele dób. Cena zostaje na pozycji `(pakiet × pokój)`,
  wpisywana jako pełna cena pakietu **za jedno łóżko** — jak dziś (patrz „Cena").
- **Parowanie współlokatorów.** Wtyczka nie wiąże dwóch zgłoszeń w jeden fizyczny pokój
  i nie pilnuje, kto z kim leży. `roommate_pref` zostaje tym, czym jest: preferencją
  zapisaną do ręcznego kwaterowania. Pula wie tylko, ile łóżek jest wziętych.
- Przydział konkretnych, fizycznych pokoi (numery, lista kwaterunkowa).
- Doby wspólne między wydarzeniami. Pule są per event, jak cała reszta konfiguracji.
- Pojęcie „bez limitu". `0` dalej znaczy „brak miejsc", jak teraz.

## Anty-cele (to by było porażką, nawet gdyby działało)

- **Mnożenie przez liczbę łóżek po stronie organizatora.** Limit wpisuje się w pokojach.
  Gdyby trzeba było wpisać 62 zamiast 31 dwójek, zmiana nie byłaby warta zachodu.
- **Zgubienie drugiego łóżka w dwójce sprzedawanej na łóżka.** Samotny uczestnik zdejmuje
  jedno łóżko, nie cały pokój. To był błąd wersji 1 tego speca.
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
- **Druga kopia arytmetyki łóżek.** `RoomOccupancy` zostaje jedynym domem reguły; nowe
  warianty wchodzą do niej, nie obok niej.

## Ograniczenia

- `src/Domain/**` bez WordPressa i bez `$wpdb` (pilnuje `DomainPurityTest`). Cała
  arytmetyka pul idzie w czystą domenę.
- PHPStan poziom 6 bez obniżania, `phpcs` czysto pod `phpcs.xml.dist`.
- Wszystkie stringi UI przez i18n, text domain `event-registration`.
- Logika mutacji konfiguracji w adminie w czystych modułach `assets/admin/ops/*`
  (tu: `accommodationOps.js`) testowanych `jest`. Komponenty cienkie.
- Migracja bazy przez `dbDelta`, idempotentna, bieżąca `Migrations::DB_VERSION = 6`
  → nowa wartość `7`.
- Lustro w `assets/public/form.js` (`seatsFor`) musi dawać ten sam wynik co domena.

## Podejścia

Rozważone trzy.

**1. Zostawić jak jest, dzielić stan ręcznie w konfiguracji.** Zero kodu. Odrzucone:
model tego nie wyraża. Niezależnie od wpisanych liczb rezerwacja na dwie doby nie
zdejmie nic z puli pojedynczej doby, więc albo się przesprzedaje, albo trzyma martwy
zapas. Zamawiający nazwał ten ból wprost.

**2. Pule dobowe pod zachowanym wyborem pakietu — WYBRANE.** Doba zyskuje własny byt
i limit per `(doba × pokój)`; pakiet deklaruje swoje doby; uczestnik widzi na formularzu
to samo co dziś (pozycja = pakiet × pokój). Rusza konfigurację noclegów, model pokoju,
liczenie zajętości, dostępność, wiersz bookingu, admina (konfiguracja + panel obłożenia)
oraz jedną kolumnę eksportu. Formularz i maile — bez zmian w zachowaniu.

**3. Doby wprost na formularzu** (wybór typu pokoju + zaznaczenie dób, pakiet znika z UI).
Koncepcyjnie najprostszy model dla uczestnika i zgodny z pierwotnym opisem wydarzenia,
ale rusza wszystko: render, submit, `AccommodationSelection`, kardynalność bookingu,
cenę, admina, edycję, CSV, mail, e2e.

Zamawiający wybrał **2**. Argument rozstrzygający: podejście 3 i tak stoi na pulach
dobowych, więc 2 jest jego warunkiem wstępnym — można je zrobić później, nie cofając
niczego. Koszt 2: pakiet nadal definiuje się na każdą kombinację dób (tu trzy).

## Model pokoju

Dziś pole `occupancy` (0 = nieustawione, 1, 2) skleja dwie różne rzeczy: **ile łóżek ma
pokój** i **jak się go sprzedaje**. Stąd brak, który ujawnił drugi przypadek prowadzący:
`occupancy = 2` znaczy jednocześnie „dwa łóżka" i „wymaga towarzyszącej", więc dwójki na
łóżka nie da się wyrazić inaczej niż przez niewypełnienie pola.

Rozdzielamy to na dwa jawne pola typu pokoju:

| pole | wartości | znaczenie |
|---|---|---|
| `beds` | `0` (nieustawione), `1`, `2` | ile łóżek ma pokój — fakt fizyczny |
| `sale` | `whole`, `beds` | czy rezerwacja bierze cały pokój, czy jedno łóżko |

Reguły, wszystkie wyprowadzane z tej pary:

- **Ile łóżek zjada rezerwacja** (`RoomOccupancy::seats`, jedyny dom reguły):
  `sale = whole` i `beds > 0` → `beds`; w każdym innym przypadku →
  `companion ? 2 : 1`. Ostatni wariant obsługuje zarówno sprzedaż na łóżka, jak i
  pokój bez ustawionej liczby łóżek (zgodność w tył).
- **Spójność pary pokój/towarzysząca** (`RoomType::companionMismatch`):
  `sale = whole` bez towarzyszącej → `companion_required`;
  `beds = 1` z towarzyszącą → `companion_not_allowed` (dwie osoby nie zmieszczą się
  w jednym łóżku); `beds = 0` nie protestuje nigdy;
  **`beds = 2` + `sale = beds` bez towarzyszącej jest poprawne** — to jest przypadek,
  o który chodzi, i dziś jest odrzucany.
- **Pole „preferowany współlokator"** (`roommate_field`) zostaje ortogonalne: pyta
  tylko, z kim ktoś chciałby leżeć, i nie wpływa na arytmetykę. Ma sens przy
  `sale = beds`; przy `sale = whole` jest bezprzedmiotowe (drugie łóżko bierze
  towarzysząca) i admin je tam wyłącza, jak dziś wyłącza je dla `occupancy = 2`.

### Zgodność w tył i sprzeczność z towarzyszącą

`RoomType::fromArray` czyta `beds`, a gdy go nie ma — **legacy `occupancy`**, i wyprowadza
tryb sprzedaży:

| stary config | nowy model | zachowanie |
|---|---|---|
| `occupancy = 2` | `beds = 2`, `sale = whole` | bez zmian: wymaga towarzyszącej, zjada 2 łóżka |
| `occupancy = 1` | `beds = 1`, `sale = beds` | bez zmian: zabrania towarzyszącej, zjada 1 łóżko |
| `occupancy = 0` | `beds = 0`, `sale = beds` | bez zmian: nie protestuje, zjada 1 albo 2 łóżka |

`toArray` zapisuje już tylko `beds` + `sale`; admin od tej pory pisze nowe klucze.
Odczyt obu kluczy zostaje na stałe — konfiguracje na istniejących stronach nie wymagają
migracji ani wejścia do edytora.

Dzisiejsza reguła z `AccommodationConfig::fromArray` — „przy wyłączonej osobie
towarzyszącej zeruj `occupancy`" — **zostaje zastąpiona**, nie usunięta. Jej sens był:
pokój wymagający towarzyszącej jest trwale niewybieralny, gdy event towarzyszących nie
dopuszcza, więc sprzeczność trzeba rozstrzygnąć w jednym miejscu, żeby pojemność, cena,
walidacja i render widziały to samo. Nowa postać: przy wyłączonej towarzyszącej pokój
z `sale = whole` **spada do `sale = beds`, zachowując `beds`**. Kasowanie liczby łóżek
byłoby teraz szkodliwe — to ona przelicza limit z pokoi na łóżka.

## Jednostka puli: limit w pokojach, pula w łóżkach

Organizator wpisuje limit dobowy **w pokojach** (31 dwójek na noc 18/19). Pula trzyma go
wewnętrznie **w łóżkach**: `limit_pokoi × beds`. Rezerwacja zdejmuje `seats` łóżek
(1 samotnie, 2 z towarzyszącą, `beds` przy całym pokoju) z puli każdej swojej doby.

Dlatego **każdy pokój biorący udział w puli dobowej musi mieć `beds > 0`** —
bez liczby łóżek nie ma czym przeliczyć pokoi. `AccommodationConfig::fromArray` rzuca
`SchemaException`, gdy pozycja inwentarza łączy pakiet z dobami z pokojem o `beds = 0`,
a admin blokuje to w momencie ustawiania. To jedyny nowy wymóg konfiguracyjny i jest
jawny: „ile łóżek ma ten pokój" to pytanie, na które i tak trzeba odpowiedzieć, żeby
sprzedawać łóżka.

Tryb zgodności (pakiet bez dób) liczy jak dziś: pula slotowa w miejscach, limit z
`inventory[(pakiet, pokój)].capacity`, bez wymogu `beds`.

Konsekwencje, których wersja 1 tego speca błędnie się pozbywała:

- Dopisek **„(za mało miejsc)" zachowuje sens**: towarzysząca potrzebuje 2 łóżek,
  a w najciaśniejszej dobie zostało 1. `SlotAvailability::isFull( $slot, $seats )`
  zachowuje swój parametr i swoje znaczenie.
- **Checkbox towarzyszącej dalej wpływa na dostępność**, więc lustro w `form.js` zostaje
  potrzebne — i zostaje **nietknięte**: `seatsFor` już tam jest, zmienia się tylko
  liczba, którą serwer wkłada do `data-evreg-seats-left` (minimum po dobach, w łóżkach).
  Żadnych map pakiet→doby ani pojemności nie wysyłamy do przeglądarki; `evreg-public`
  to statyczny skrypt bez `wp_localize_script`, a nie ma czego tam liczyć poza tym, co
  już liczy.

## Cena

Bez zmian w mechanice: `PriceCalculator::total` mnoży cenę pozycji `(pakiet × pokój)`
przez `seats`. Czyli **cena wpisywana w macierzy inwentarza jest ceną za jedno łóżko**:
samotny uczestnik w dwójce na łóżka płaci ×1, zgłoszenie z towarzyszącą ×2, cały pokój
×`beds`. Tak działa dziś i tak zostaje — ale warto to w adminie napisać wprost przy
macierzy cen, bo przy dwójce na łóżka różnica „za pokój czy za osobę" jest kosztowna.

Bramkowanie ceny przyznaniem noclegu (B7) i zachowanie zapisanej ceny przy edycji
niezmieniającej slotu (B-occupancy) zostają bez zmian.

## Model konfiguracji

Meta `_evreg_accommodation` zyskuje dwa klucze na górnym poziomie i dwa na pokoju;
pozostałe zostają nietknięte.

```php
array(
    'nights'          => array(
        array( 'key' => 'n1819', 'label' => '18/19 grudnia' ),
        array( 'key' => 'n1920', 'label' => '19/20 grudnia' ),
    ),
    'rooms'           => array(
        // NOWE: beds + sale zastępują occupancy (czytane dalej jako legacy).
        array( 'key' => 'single', 'label' => 'Pokój 1-osobowy', 'beds' => 1, 'sale' => 'beds' ),
        array( 'key' => 'double', 'label' => 'Pokój 2-osobowy', 'beds' => 2, 'sale' => 'whole' ),
        array( 'key' => 'shared', 'label' => 'Miejsce w pokoju 2-osobowym', 'beds' => 2, 'sale' => 'beds', 'roommate_field' => true ),
    ),
    'packages'        => array(
        // NOWE pole `nights` — lista kluczy dób. Pusta/brak = tryb zgodności.
        array( 'key' => 'p18', 'label' => 'Doba 18/19', 'nights' => array( 'n1819' ) ),
        array( 'key' => 'p19', 'label' => 'Doba 19/20', 'nights' => array( 'n1920' ) ),
        array( 'key' => 'p2',  'label' => 'Dwie doby',  'nights' => array( 'n1819', 'n1920' ) ),
    ),
    'inventory'       => array(
        // Bez zmian strukturalnie. `capacity` PRZESTAJE być limitem dla pakietu
        // z dobami — zostaje cena (za łóżko). Liczba jest ZACHOWANA, nie zerowana.
        array( 'package' => 'p2', 'room' => 'single', 'capacity' => 10, 'price' => 400.0 ),
    ),
    'night_inventory' => array(
        // NOWE: limit POKOI per doba × typ pokoju.
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
zduplikowana para `(doba, pokój)`; ujemna pojemność; `beds` spoza `0..2`; `sale` poza
`whole`/`beds`; **pozycja inwentarza łącząca pakiet z dobami z pokojem o `beds = 0`**.

Czego walidacja **nie** odrzuca: niezerowej `capacity` na pozycji pakietu z dobami.
Ta liczba jest uśpiona, nie błędna — patrz „Uśpiona pojemność pozycji".

### Tryb zgodności

Pakiet, którego `nights` jest puste albo nieobecne, działa **dokładnie jak dziś**: limit
z `inventory[(pakiet, pokój)].capacity`, pula liczona w miejscach (`SUM(seats)`).
Istniejące instalacje nie zauważają zmiany i nie wymagają migracji danych. Jeden event
może mieszać pakiety z dobami i bez — decyzja jest per pakiet, nigdy globalna.

To jedyne miejsce, gdzie dwie semantyki pul współistnieją, i jest tak celowo: inaczej
zmiana wymagałaby przeliczenia stanu na wszystkich stronach, na których wtyczka już stoi.

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

Czyli: ile dana rezerwacja zjada — mówią jej zapisane wiersze dób wraz z zapisanym
`seats` (nigdy bieżąca konfiguracja pakietu ani pokoju). Czy nowa rezerwacja albo zmiana
slotu przechodzi przez pule dobowe czy slotowe — mówi bieżąca konfiguracja pakietu
(`usesNightPools`).

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
- Dopóki dosypania nie zrobiono, admin **liczy i pokazuje**, ile aktywnych rezerwacji nie
  jest widzianych przez pule dobowe.
- **Jeśli event nie zdążył zebrać żadnej rezerwacji z noclegiem**, ta część jest martwa
  i może wypaść z zakresu (jeden task mniej) — do potwierdzenia przed planem.

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
    seats tinyint unsigned NOT NULL DEFAULT 1,
    PRIMARY KEY  (id),
    UNIQUE KEY uniq_booking_night (booking_id, night_key),
    KEY idx_registration (registration_id),
    KEY idx_night (night_key, room_type_key)
) ENGINE=InnoDB
```

- **Dlaczego osobne wiersze, a nie JSON na bookingu:** zajętość musi dać się policzyć
  jednym `GROUP BY` pod lockiem, tą samą drogą co dziś. JSON wymuszałby wczytanie
  wszystkich bookingów i liczenie w PHP, czyli wyjście z wzorca „liczymy w SQL pod lockiem".
- **Dlaczego `seats` jest też tutaj**, choć stoi już na bookingu: pula dobowa liczy
  `SUM(seats)` po wierszach dób, a wiersz ma być samowystarczalny dla zapytania
  liczącego — bez JOIN-a do bookingu po liczbę łóżek. Wartość jest zapisywana raz,
  w tej samej transakcji, i nigdy nie rozjeżdża się z bookingiem (jedna ścieżka zapisu).
- **Dlaczego `registration_id` jest zdenormalizowany** (booking jest 1:1 ze zgłoszeniem):
  ścieżka `occupancyExcluding(event, exclude_id)` i twarde kasowanie zgłoszenia filtrują
  po zgłoszeniu; bez tej kolumny każda z nich dokładałaby drugi JOIN.
- **`night_label` to snapshot**, dokładnie jak `package_label`/`room_label` od 0.1.14:
  zmiana nazwy doby w konfiguracji nie może przepisać historii.
- Eraser prywatności **nie tyka** tych wierszy: nie zawierają danych osobowych, a ich
  usunięcie rozjechałoby zajętość (ta sama zasada, dla której eraser zostawia status,
  typ, cenę i daty).
- `Uninstaller::TABLES` dostaje nową nazwę (dziś cztery tabele, będzie pięć).
  Bramka `evreg_delete_data_on_uninstall` bez zmian.

## Domena

Nowe i zmienione obiekty w `src/Domain/Accommodation/` oraz `src/Domain/Capacity/`:

- **`Night`** (nowy): `key`, `label`, `fromArray`, `toArray` — bliźniak `Package`.
- **`Package`**: nowe pole `nights` (`string[]`, domyślnie pusta tablica) +
  `usesNightPools(): bool`.
- **`RoomType`**: `occupancy` ustępuje parze `beds` + `sale`; `seats()` i
  `companionMismatch()` wyprowadzają reguły z tej pary (tabele w „Model pokoju").
- **`RoomOccupancy::seats( int $beds, string $sale, bool $companion ): int`** — nadal
  **jedyny dom reguły**. Delegują do niej `RoomType::seats`,
  `AccommodationConfig::seatsFor`, `CapacityLimits::seatsForSlot` i lustro `seatsFor`
  w `form.js`.
- **`AccommodationConfig`**: `nights()`, `nightLabel()` (fallback na surowy klucz, jak
  `packageLabel`), `nightsForPackage()`, `usesNightPools( string $package_key )`,
  oraz pojemności dobowe **przeliczone na łóżka** (`limit_pokoi × beds`) jako mapa
  `"doba|pokój" => łóżka`. Przeliczenie siedzi tu, nie w kalkulatorze — kalkulator
  porównuje łóżka z łóżkami.
- **`OccupancySnapshot`**: nowa mapa `perNightRoom` (`"doba|pokój"` → zajęte łóżka)
  + akcesor. Konstruktor dostaje ją jako kolejny parametr z domyślną pustą tablicą.
- **`CapacityLimits`**: mapy pojemności dobowych (w łóżkach) i `pakiet → doby`, oba jako
  parametry z domyślną pustą tablicą (wzorem `perSlotSeats` z B-occupancy).
- **`CapacityCalculator::decide`**: gdy pakiet selekcji ma doby, bramka noclegowa
  dla **każdej** doby sprawdza `zajęte_łóżka + seats > pojemność_łóżek`. Brak pojemności
  dla pary `(doba, pokój)` = brak miejsc (spójnie z dzisiejszym `null === $slot_limit`).
  Pierwsza wyczerpana doba kończy sprawdzanie i daje `accommodation_full`. Limit globalny
  i limit typu bez zmian, przed bramką noclegową, jak teraz.
- **`SlotAvailability`**: `fromSnapshot` czyta też pule dobowe; `left( $slot )` dla
  pakietu z dobami = minimum `(pojemność - zajęte)` po dobach, w łóżkach;
  `isFull( $slot, $seats )` zachowuje parametr i znaczenie. Tryb zgodności bez zmian.

Cała ta arytmetyka jest czysta i testowalna bez WordPressa — tam mieszkają testy
jednostkowe obu przykładów prowadzących.

## Ścieżki transakcyjne

Trzy i tylko trzy miejsca liczą i zapisują zajętość, wszystkie w `ReservationService`:
`reserve`, `editAnswers`, `promoteFromWaitlist`. Każda:

1. `lockEvent($event_id)` **przed** pierwszym odczytem zajętości (bez zmian),
2. buduje `CapacityLimits` z pojemnościami dobowymi i mapą `pakiet → doby`,
3. `decide(...)` jak dziś, już świadome dób,
4. przy przyznanym noclegu wstawia booking **oraz** wiersze dób (z `seats`) w tej samej
   transakcji,
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
  `SELECT CONCAT(n.night_key,'|',n.room_type_key) AS slot, SUM(n.seats) AS c
   FROM {booking_nights} n INNER JOIN {registrations} r ON n.registration_id = r.id
   WHERE r.event_id = %d AND r.status IN (...) GROUP BY slot`
  (wariant `Excluding` dokłada `AND r.id != %d`). Dotychczasowe zapytanie o pule
  slotowe **zostaje** — tryb zgodności z niego żyje.
- `insertAccommodationBooking` wstawia też wiersze dób; potrzebuje `insert_id` bookingu.
- **Wiersze dób giną razem z bookingiem na KAŻDEJ ścieżce**, która dziś kasuje booking,
  nie tylko przy twardym usunięciu zgłoszenia: `cancel`, `editAnswers` (zmiana slotu),
  `promoteFromWaitlist` i `deleteRegistration`. Pominięcie choćby jednej zostawia popyt
  na pulach dobowych po rezerwacji, która już ich nie zajmuje — czyli trwale zaniżoną
  dostępność. Najprościej: kasowanie dób siedzi w tej samej metodzie repozytorium, co
  kasowanie bookingu, żeby nie dało się wywołać jednego bez drugiego.
- Metoda odczytu dób zapisanej rezerwacji (do detalu zgłoszenia i eksportu).
- Zapytanie dla dosypania dób: aktywne rezerwacje danego pakietu bez wierszy dób.

## Admin (React)

Zakładka „Noclegi" (`AccommodationTab.jsx`), logika w `accommodationOps.js`:

- **Pokój** dostaje dwa pola zamiast dzisiejszej „Liczby osób": **„Łóżka"** (1 / 2 /
  nieustawione) i **„Sprzedaż"** (cały pokój / na łóżka). „Cały pokój" jest wyłączone,
  gdy event nie dopuszcza osoby towarzyszącej (sprzeczność z „Zgodność w tył");
  „preferowany współlokator" wyłączone przy „cały pokój".
- **Lista dób** (dodaj / usuń / klucz / etykieta) — bliźniak listy pakietów.
- **Pakiet dostaje wybór dób** (wielokrotny). Pakiet bez wybranych dób zostaje w trybie
  zgodności i musi to komunikować wprost, nie milczeniem.
- **Nowa macierz `doba × pokój`** z limitem **w pokojach**, z widoczną informacją, ile to
  łóżek (`limit × beds`) — inaczej jednostka będzie zgadywana.
- **Blokada: pakiet z dobami × pokój bez ustawionych łóżek** w momencie ustawiania, nie
  dopiero przy zapisie.
- **Istniejąca macierz `pakiet × pokój`** zostaje przy cenie (za łóżko — napisać wprost);
  komórka pojemności wyszarzona z notką, gdy pakiet ma doby.
- **Akcja „przypisz doby istniejącym rezerwacjom"** z liczbą rezerwacji, których dotyczy,
  i jawnym potwierdzeniem.
- Ostrzeżenia o rozjeździe (jak dzisiejsze o zmianie klucza pakietu): usunięcie doby, do
  której odwołują się pakiety albo bookingi; zdjęcie dób z pakietu (z limitem, który
  wraca do gry, i liczbą rezerwacji dalej liczonych dobowo).

`EventConfigController::sanitize` jest generyczny i rekurencyjny, więc nowe klucze
przechodzą bez zmian — do potwierdzenia testem, nie założeniem.

### Panel obłożenia

`RegistrationsOverview::render_slots()` pokazuje dziś „Noclegi (miejsca)" z
`OccupancyReport::slots()`, które bierze `taken/capacity/free` wprost z
`InventoryItem::capacity`. Dla pakietu z dobami ta liczba jest uśpiona, więc panel
pokazywałby „wolne 0", podczas gdy formularz te pokoje sprzedaje. To jest **ta
powierzchnia, na którą patrzy organizator**, więc cel 5 obejmuje ją wprost:

- `OccupancyReport` dostaje czystą metodę zwracającą obłożenie per `(doba × pokój)`:
  limit w pokojach, pojemność i zajętość w łóżkach, wolne łóżka. `report()` buduje dziś
  `CapacityLimits` bez map dobowych — musi je dostać.
- Admin renderuje nową tabelę „Noclegi per doba".
- Wiersze slotowe pakietów z dobami są oznaczone jako nieobowiązujące, zamiast pokazywać
  mylące zero.
- Liczba aktywnych rezerwacji niewidzianych przez pule dobowe jest pokazana tutaj, nie
  tylko w zakładce konfiguracji.

## Wielojęzyczność

Etykiety dób są tłumaczalne per język jak pakiety i pokoje (B6): overlay
`_evreg_i18n[lang].accommodation` zyskuje bucket `nights: { key: label }`,
`ContentTranslator::translateAccommodation` nakłada go przed `SchemaAssembler`,
a nowy bucket musi wejść do `translatableItems` i do whitelisty `langBucket`
(`accommodation` już jest). Tłumaczone są **tylko etykiety** — klucze, pojemności, liczby
łóżek i ceny nietknięte.

## Render publiczny

Lista pozycji `(pakiet × pokój)` wygląda jak dziś. Zmienia się tylko źródło sądu
„zamknięte":

- wyczerpana pozycja dostaje `disabled` + dopisek `(brak miejsc)` / `(za mało miejsc)`
  i **nigdy nie renderuje się jako `checked`** (inwariant B-slots: `disabled` nie wysyła
  wartości, więc zaznaczony-i-wyłączony radio dałby po submicie pusty `[slot]`, czyli
  ciche „Bez noclegu"),
- `data-evreg-seats-left` niesie minimum po dobach, w łóżkach,
- `form.js` zostaje bez zmian: `seatsFor` liczy potrzebne łóżka z `beds`/`sale` i flagi
  towarzyszącej, dokładnie jak dziś z `occupancy`.

Nazwanie blokującej doby w komunikacie („brak miejsc w nocy 19/20") byłoby miłe, ale
to osobna decyzja produktowa — nie wchodzi w ten projekt.

## Eksport i detal zgłoszenia

Doby zapisane na rezerwacji są pokazywane organizatorowi w dwóch miejscach:

- **detal zgłoszenia** — obok pakietu i pokoju, z etykiet zapisanych na wierszach,
- **eksport CSV** — nowa kolumna „Doby" w `RegistrationExportMapper::accommodationCells`
  (czysta domena, więc i własne testy). To praktyczny artefakt tej potrzeby: lista
  kwaterunkowa dla hotelu powstaje z eksportu, nie z klikania po detalach.

Mail i szablony bez zmian — nie dochodzi żaden placeholder.

## Co zostaje builderowi

- Nazwy metod i klas trzymają konwencję repo (camelCase na obiektach wartości).
  `sale` jako string vs enum — decyzja buildera, ale wartości walidowane w jednym miejscu.
- Kolejność tasków i granice commitów.
- Czy mapa `pakiet → doby` w `CapacityLimits` to osobny obiekt wartości, czy tablica.
- Kształt testów, pod warunkiem że pokrywają: oba przykłady prowadzące (w szczególności
  **samotny uczestnik w dwójce na łóżka zdejmujący jedno łóżko, z drugim dalej
  sprzedawalnym**); pakiet dwudobowy blokowany przez **drugą** dobę (nie pierwszą);
  towarzyszącą odbijaną przez dobę z jednym wolnym łóżkiem; mapowanie legacy `occupancy`
  → `beds`/`sale` dla wszystkich trzech wartości; sprzeczność „cały pokój" przy
  wyłączonej towarzyszącej; odmowę „pakiet z dobami × pokój bez łóżek"; tryb zgodności
  bez regresji; edycję niezmieniającą slotu; awans z waitlisty przy wyczerpanej dobie;
  dosypanie dób uruchomione dwa razy pod rząd (idempotencja); kasowanie wierszy dób na
  każdej ścieżce kasującej booking; panel obłożenia dla pakietu z dobami; oraz to, że
  `lockEvent` poprzedza każdy odczyt zajętości.
- Dopisek do `CLAUDE.md`: pule dobowe, para `beds`/`sale` zastępująca `occupancy`
  (wraz z **przeredagowaniem akapitu B-occupancy**, który opisuje dzisiejsze zerowanie),
  inwariant podaży/popytu. Wpis do `CHANGELOG.md` w `## [Unreleased]`.

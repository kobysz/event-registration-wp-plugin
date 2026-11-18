# Ekran „Dodaj zgłoszenie" w panelu — projekt

**Data:** 2026-11-18
**Rozmiar:** projekt
**Backlog:** B32
**Status:** zatwierdzony przez partnera (2026-11-18), po sprawdzeniu przez niezależnego buildera

## Intencja i powód

Część zgłoszeń nie przychodzi formularzem. Ludzie dzwonią albo piszą maila — często osoby
starsze i znajomi organizatora — a organizator wie, że będą, i chce mieć **komplet w bazie
i prawidłowe stany**: poprawne liczniki miejsc, poprawne ceny, tych ludzi na listach
i w eksportach. Dziś robi to, udając uczestnika na formularzu publicznym.

To obejście jest nie tylko niewygodne, ale **wprost przeciwskuteczne wobec celu**. Zgłoszenie
z formularza powstaje jako `pending` z terminem ważności; cron `Cron\ExpirePending` po terminie
kasuje takie zgłoszenia, zwalnia miejsce i wysyła maila o wygaśnięciu. Potwierdzenie następuje
przez link w mailu, którego osoba zgłoszona telefonicznie nigdy nie kliknie — bo albo maila nie
dostała, albo nie ma gdzie kliknąć. Czyli ludzie, co do których organizator ma pewność, po kilku
dniach sami znikają z liczników, a wtyczka wysyła im zawiadomienie o czymś, czego nie robili.

Wpis z panelu musi więc powstawać **od razu jako potwierdzony**, bez terminu ważności i bez
poczty, chyba że organizator świadomie poprosi o potwierdzenie.

## Cele

- Organizator wpisuje uczestnika z panelu, w kontekście listy zgłoszeń, bez udawania go na
  formularzu publicznym.
- Zgłoszenie powstaje jako potwierdzone, bez terminu ważności, więc nie wygasa.
- Liczniki, ceny, nocleg, doby i osoba towarzysząca liczą się dokładnie tak samo jak przy
  zgłoszeniu z formularza.
- Domyślnie nie wychodzi żaden mail; organizator może go wysłać świadomie, przełącznikiem.

## Nie-cele

- Masowy import (CSV, wklejanie listy). Jedna osoba na raz.
- Edycja istniejącego zgłoszenia — to jest już zrobione (`editAnswers`).
- Zmiana czegokolwiek w ścieżce publicznej. Jej zachowanie musi zostać bajt w bajt.
- Nowy ekran poza menu zgłoszeń. Ekran żyje przy liście, bo tam organizator pracuje.
- Naprawa braku straży nieznanego typu na ścieżce PUBLICZNEJ (patrz 7a) — wpis do backlogu.
- Pokazywanie znacznika wpisu ręcznego na liście zgłoszeń i w eksporcie CSV (patrz 1a).

## Anty-cele

Rzeczy, które oznaczają porażkę, nawet gdyby funkcja działała:

- **Czwarta kopia reguł pojemności.** Ścieżek transakcyjnych są trzy (`reserve`, `editAnswers`,
  `promoteFromWaitlist`) i każda musi samodzielnie pamiętać o tych samych regułach: limity
  z flagą towarzyszącej, miejsca liczone po pokoju a nie po fladze, cena noclegu bramkowana
  przyznaniem, wiersze dób. Historia tego repo to w dużej mierze łatanie miejsc, w których
  jedna z nich o czymś zapomniała. Nowa ścieżka zapisu byłaby czwartym takim miejscem.
- **Panel kłamiący o pojemności.** Wpis ponad limit „bo organizator wie lepiej" niszczy dokładnie
  ten stan, dla którego ta funkcja powstaje.
- **Cichy mail.** Mail wychodzący bez świadomej decyzji organizatora, do osoby, która o niego
  nie prosiła.
- **Rozluźnienie walidacji „bo to admin".** Jedyny wyjątek jest nazwany niżej i jest jeden.

## Ograniczenia

- Uprawnienie: `Admin\Capabilities::CAP` (`edit_evreg_events`), jak reszta ekranów zgłoszeń.
- `src/Domain/**` bez WordPressa i bez `$wpdb`.
- Domena zwraca kody, nigdy komunikaty; tłumaczenie w prezentacji.
- Hooki cyklu życia wyłącznie po COMMIT.
- Kolejność `lockEvent()` przed pierwszym COUNT jest nienaruszalna.
- Wszystkie stringi przez i18n, text domain `event-registration`.
- PHPStan 6, `phpcs` czysto.

## Podejścia

**A. Osobny ekran + nowa metoda `addManually()` w serwisie.** Własna transakcja
lock→count→decide→zapis. Odrzucone: to jest wprost anty-cel „czwarta kopia reguł".

**B. Osobny ekran, zapis przez istniejące `reserve()` z opisem trybu wpisu — WYBRANE.**
Ten sam ekran i handler co w A, ale `reserve()` dostaje opcjonalny parametr mówiący, jak ma
zapisać. Domyślna wartość to dzisiejsze zachowanie, więc ścieżka publiczna się nie zmienia,
a arytmetyka miejsc, cen, noclegów i dób jest wspólna **z definicji, a nie przez dyscyplinę**.
Koszt: `reserve()` służy dwóm panom i każdy czytelnik musi sprawdzić tryb. Cena płacona
w jednym miejscu i widoczna w sygnaturze.

**C. Tryb „zapisuję w imieniu" na formularzu publicznym.** Najtańsze — formularz już umie
warunki widoczności, doby i osobę towarzyszącą. Odrzucone: odpada praca w kontekście listy
zgłoszeń i wybór wydarzenia z panelu, a formularz publiczny przestaje być powierzchnią wyłącznie
uczestnika — każda przyszła zmiana na nim musiałaby odtąd rozważać przypadek organizatora.

Partner wybrał **B**.

## Decyzje

### 1. Opis trybu wpisu

Nowy czysty obiekt wartości `Domain\Registration\ManualEntry` z jednym polem: czy wysłać
potwierdzenie (`notify`, domyślnie `false`). `ReservationService::reserve()` i jego bliźniak
`reserveNights()` dostają opcjonalny ostatni parametr `?ManualEntry $manual = null`.
**`null` = ścieżka publiczna, zero zmian.** Obecność obiektu zmienia dokładnie cztery rzeczy
i nic poza nimi:

1. pomija blokadę duplikatu adresu (`activeRegistrationExists`),
2. przy wyniku przyjętym zapisuje status `confirmed` zamiast `pending`,
3. nie ustawia `expires_at`,
4. emituje zdarzenie cyklu życia tylko gdy `notify`, i **inne niż ścieżka publiczna** (patrz 3).

Tryb musi przejść przez OBIE gałęzie — klasyczną i dobową. Pominięcie jednej daje wpis, który
w trybie dób wygasa.

### 1a. Trwały znacznik wpisu ręcznego (kolumna, db v8)

`ManualEntry` z punktu 1 jest parametrem wywołania i umiera wraz z nim. To za mało, bo wpis,
który trafił na listę rezerwową, wraca później przez `promoteFromWaitlist` — a ta ustawia
`markPending()` ze świeżym terminem 48 h i emituje `evreg_registration_reserved`, czyli szablon
„potwierdź swoje zgłoszenie" z linkiem. Osoba zgłoszona telefonicznie wracałaby więc dokładnie
w problem, dla którego ta funkcja powstaje, i wygasała po 48 h. Dzisiejsza tabela nie ma czym
odróżnić wpisu ręcznego od publicznego.

Dlatego: nowa kolumna na `evreg_registrations` oznaczająca wpis ręczny, migracja
`Migrations::DB_VERSION` z 7 na 8, zapis w `insertRegistration()`, odczyt tam, gdzie promocja
podejmuje decyzję. **`promoteFromWaitlist` i `promoteFromWaitlistNights` promują wpis ręczny
od razu na `confirmed`**, bez `expires_at` i bez maila z linkiem — dokładnie jak wpis ręczny
przyjęty od razu. Promocja wpisu publicznego nie zmienia się w niczym.

Decyzja partnera, świadomie kupiona za rozmiar: to jedyny wariant bez znanej pułapki.

Znacznik pokazuje się na **detalu zgłoszenia**, jednym wierszem, żeby promocja nie była magią
bez wytłumaczenia. Na liście zgłoszeń ani w eksporcie CSV nie — to kolumna o pochodzeniu wpisu,
nie o uczestniku, a obie te powierzchnie mają już swoją szerokość. Eksport można dołożyć
później, jeśli okaże się potrzebny.

### 2. Status i znacznik potwierdzenia

Wynik przyjęty → `confirmed`, `expires_at` NULL. Wynik na liście rezerwowej → `waitlist`,
dokładnie jak dziś (lista rezerwowa to prawidłowy stan, nie porażka).

`confirmed_at` musi zostać ustawione, bo detal zgłoszenia i eksport je pokazują, a
`insertRegistration()` tej kolumny nie zapisuje. Rozstrzygnięcie: po wstawieniu wiersza, wewnątrz
tej samej transakcji, zawołać `markConfirmed( $id )` — jedyne miejsce, które zna konwencję
„potwierdzone znaczy status + `confirmed_at` + `updated_at`". Rozszerzanie `insertRegistration()`
o kolejną kolumnę rozsypałoby tę konwencję na dwa miejsca.

### 3. Poczta

Domyślnie żadna — ani do uczestnika, ani do organizatorów. Przy włączonym przełączniku
wpis trafiający na listę rezerwową emituje `evreg_registration_waitlisted`, a `Subscriber`
dokłada do tego kopię dla adresów z `notify_emails`; organizator dostanie więc powiadomienie
o zgłoszeniu, które sam przed chwilą wpisał. **Ta asymetria zostaje przyjęta świadomie:**
stłumienie samej kopii wymagałoby nowego zdarzenia albo przepchnięcia stanu do `Subscriber`
(hooki niosą tylko `$id` i `$event_id`), czyli poszerzenia kontraktu z punktu 1 o piątą rzecz,
a szkoda jest jednym zbędnym mailem do organizatora, nie do uczestnika.

Przy włączonym przełączniku dla wpisu przyjętego emitowane jest `evreg_registration_confirmed`,
**nie** `evreg_registration_reserved`. Powód: „reserved" to szablon „potwierdź swoje zgłoszenie"
z linkiem potwierdzającym, a to zgłoszenie jest już potwierdzone — link prowadziłby donikąd.
Właściwy szablon to „zgłoszenie potwierdzone". Wpis trafiający na listę rezerwową z włączonym
przełącznikiem emituje `evreg_registration_waitlisted`, bo ten szablon mówi prawdę.

To świadomie łamie symetrię z `confirmManually()`, które celowo NIE emituje `confirmed`, żeby
nie wysłać maila. Tam brak maila jest regułą; tutaj mail jest wyborem organizatora.

### 4. Pojemność i nocleg bez wyjątków

Limit globalny, limit typu, pojemność slotu i dób, arytmetyka osoby towarzyszącej, bramkowanie
ceny noclegu przyznaniem — wszystko jak na ścieżce publicznej, bo to ten sam kod. Pełny typ przy
włączonej liście rezerwowej daje wpis na listę; pełny bez listy daje odmowę. Wyczerpany nocleg
daje `accommodationFull` i błąd przy polu noclegu, tak samo jak publicznie.

### 5. Reguła spójności pokoju i osoby towarzyszącej obowiązuje zawsze

`handle_edit` stosuje `companionRoomMismatch` warunkowo (tylko gdy edycja rusza slot albo flagę),
bo inaczej oznaczenie pokoju jako dwuosobowego zablokowałoby każdy zapis zastanych zgłoszeń.
Przy DODAWANIU tego problemu nie ma — nie ma czego zastać — więc reguła obowiązuje bezwarunkowo,
jak w `SubmitHandler`.

### 6. Jedyny wyjątek w walidacji: puste pole e-mail

W panelu wolno zostawić puste **wyłącznie** pole e-mail, nawet gdy autor oznaczył je jako
wymagane. Wszystkie inne pola walidują się dokładnie tak, jak autor ustawił. Powód: wymagalność
tego pola bierze się z potrzeby wysłania potwierdzenia, a przy wpisie ręcznym potwierdzenia
domyślnie nie wysyłamy; osoba zgłoszona telefonicznie często adresu nie ma. Decyzja partnera.

Konsekwencje, świadomie przyjęte: zgłoszenie bez adresu nie zostanie znalezione przez eksport
danych osobowych po mailu i nie da się do niego nic wysłać później. Blokada duplikatu i tak
nie działa w tym trybie, więc pusty adres nie koliduje z niczym.

Mechanizm: `Validator::validate()` dostaje trzeci parametr — listę kluczy pól, dla których
pomija sprawdzenie `required`; domyślnie pusta, czyli zero zmian. `SubmissionAssembler::assemble()`
dostaje flagę `$emailOptional` (domyślnie `false`) i gdy jest włączona, podaje walidatorowi klucz
pola e-mail. **Pole e-mail to pierwsze pole typu `email` w kolejności schematu**, niezależnie od
wartości — to NIE jest ta sama reguła co `extractEmail()`, która bierze pierwsze NIEPUSTE. Przy
jednym polu e-mail, czyli w praktyce zawsze, obie wskazują to samo.

### 7. Powierzchnia ekranu

Trasa `?page=evreg-registrations&action=add` przy istniejącym ekranie zgłoszeń, przycisk „Dodaj
zgłoszenie" na liście obok „Eksportuj CSV", niosący bieżący filtr wydarzenia. Bez wybranego
wydarzenia ekran pokazuje najpierw listę wydarzeń do wyboru — formularz zależy od schematu, więc
bez wydarzenia nie ma czego renderować.

Formularz to ten sam renderer co przy edycji (`Admin\RegistrationEditForm`), z pustymi
wartościami. Dziś jego `render()` zaszywa w sobie ramę edycji: nonce `evreg_edit_<id>`, ukryte
pole `registration`, cel `admin-post` i etykietę przycisku. Rama wydziela się do dwóch cienkich
wejść (`renderEdit` / `renderAdd`) nad wspólnym prywatnym renderowaniem wierszy. Rama dodawania
niesie dodatkowo ukryte pole z ID wydarzenia i przełącznik „wyślij potwierdzenie" (domyślnie
wyłączony).

Handler `admin-post` jest lustrem `handle_edit`: nonce → uprawnienie → schemat i tryb dób
(niepoprawne → `config_error`) → `SubmissionAssembler` z włączoną opcjonalnością e-maila →
błędy walidacji renderowane w miejscu, bez przekierowania → `reserve()` z `ManualEntry` →
PRG na detal nowego zgłoszenia.

Mapowanie wyniku na komunikat: przyjęte → „dodano"; lista rezerwowa → „dodano na listę
rezerwową"; odmowa pojemności → „brak miejsc"; wyczerpany nocleg → błąd przy polu noclegu
i ponowne wyrenderowanie formularza z surowymi odpowiedziami (tak jak robi to `SubmitHandler`).

### 7a. Pusty typ zgłoszenia jest odrzucany

`reserve()` i `reserveNights()` jako jedyne ścieżki transakcyjne **nie mają** straży nieznanego
typu — `editAnswers` i `promoteFromWaitlist` zwracają w takim razie `invalidStatus()`. Pole
`__type` nie jest domyślnie wymagane, a renderer admina stawia „— brak —" jako pierwszą opcję,
więc pusty typ jest o jedno kliknięcie i dałby wiersz **potwierdzony, darmowy i bez typu**,
liczony wyłącznie do limitu globalnego. To wprost anty-cel „panel kłamiący o pojemności".

Straż staje w **handlerze ekranu dodawania**, nie w `reserve()` — wejście do `reserve()` musi
zostać bajt w bajt takie samo dla ścieżki publicznej, a tryb wpisu ma zmieniać dokładnie te
rzeczy, które wymienia punkt 1, i nic więcej. Pusty albo nieznany typ daje błąd walidacji przy
polu typu i ponowny render.

Ta sama dziura istnieje dziś na ścieżce publicznej i **nie jest naprawiana w tym projekcie** —
wpis do backlogu.

### 7b. Przełącznik maila przy pustym adresie

`MailQueue::enqueue` odrzuca pustego adresata, zwracając `false` bez słowa. Bez reguły
organizator zaznaczyłby „wyślij potwierdzenie", zobaczył „dodano" i nie dostał ani maila, ani
informacji, że maila nie będzie. Dlatego włączony przełącznik przy pustym polu e-mail jest
**błędem walidacji**: nie ma na jaki adres wysłać. Organizator albo wpisuje adres, albo odznacza
przełącznik.

### 7c. Duplikat adresu: informacja, nie blokada

Blokada „jeden adres na wydarzenie" zostaje pominięta bezwarunkowo (punkt 1), bo rodzina
zapisywana na adres jednego z rodziców ma przejść. Przy NIEPUSTYM adresie, który ma już aktywne
zgłoszenie na to wydarzenie, zapis mimo to następuje, ale komunikat o powodzeniu mówi, że ten
adres ma już inne aktywne zgłoszenie. Informacja, nie pytanie i nie blokada — organizator wpisuje
świadomie i nie ma go przed czym chronić, ale pomyłka też ma prawo być widoczna.

### 7d. Komunikat o wyczerpanym noclegu w trybie dób

W trybie dób `ReservationResult::accommodationFull` niesie klucz blokującej doby, a `SubmitHandler`
zamienia go na kod `night_full:<klucz>`, który rozumie wyłącznie `FormRenderer::errorMessage()`.
`RegistrationEditForm::errorMessage()` takiego wpisu nie ma i wypisałby „Nieprawidłowa wartość."
Ekran dodawania musi dołożyć to mapowanie do renderera admina, inaczej dziedziczy bezużyteczny
komunikat.

### 8. Język zgłoszenia

Kolumna `lang` wypełnia się jak dziś, z `CurrentLanguage::get()`, czyli w panelu zwykle językiem
panelu albo pustką. Przy włączonym przełączniku mail rozstrzygnie szablon tym językiem. Nie ma
tu lepszej odpowiedzi bez pytania organizatora o język uczestnika, a to nie-cel.

## Zostawione budującemu

- Nazwy tras, stałych akcji i kodów komunikatów, w konwencji istniejących w `RegistrationsScreen`.
- Układ ekranu wyboru wydarzenia (lista czy rozwijane pole) i etykiety.
- Czy `renderAdd`/`renderEdit` dzielą prywatną metodę ramy, czy tylko renderowanie wierszy.
- Dokładne brzmienie komunikatów po polsku.

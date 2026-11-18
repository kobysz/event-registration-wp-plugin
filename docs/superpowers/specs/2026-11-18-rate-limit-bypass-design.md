# Zwolnienie organizatora z limitu submisji — projekt

**Data:** 2026-11-18
**Rozmiar:** mała zmiana
**Status:** zatwierdzony przez partnera (2026-11-18)

## Intencja i powód

Organizator rejestruje kilka osób z jednej przeglądarki przez publiczny formularz i po
kilku zgłoszeniach wpada w limit antyspamowy: 10 prób na godzinę na IP
(`SubmitHandler::RATE_LIMIT`). Dostaje przy tym komunikat „Nie udało się wysłać
zgłoszenia. Spróbuj ponownie." — ten sam, który obsługuje trzy zupełnie inne powody
odrzucenia. Komunikat nie mówi ani że to blokada czasowa, ani jak długo trwa, więc
organizator klika dalej, nie wiedząc, że trzeba czekać (zablokowane próby nie są
liczone, więc blokada schodzi najpóźniej godzinę po ostatniej przyjętej próbie), a całość
wygląda na zepsuty formularz. Zgłoszone z produkcji; niereprodukowalne u nas, bo limit utrzymuje się
godzinę i nie zostawia śladu w logach.

Dwie rzeczy robią z tego realny problem, a nie tylko ciasny próg:

1. **Licznik rośnie przy każdej próbie, także odrzuconej przez walidację.**
   `recordAttempt()` leci przed `process()`, bezwarunkowo. Organizator, który przy
   trzech z pięciu osób pomyli e-mail albo przegapi pole, wyczerpuje limit po trzech–
   czterech realnie zapisanych zgłoszeniach.
2. **Kubełek jest wspólny dla wszystkich powodów odrzucenia.** Blokada, zły nonce,
   honeypot i okno 3 s zwracają jeden kod `spam`, więc nie da się ich rozróżnić ani w
   komunikacie, ani przy diagnozie.

Rozpatrzone i **odrzucone**: przestać liczyć próby, które nie przeszły walidacji. Ruch
botów to dokładnie ten ruch, który nie przechodzi walidacji — bot wypełnia śmieciem i
wysyła. Przestać go liczyć znaczy rozbroić licznik z jedynej rzeczy, do której służy.
Liczymy wszystkie próby.

## Cele

- Użytkownik z uprawnieniem do zarządzania eventami nie wchodzi w limit submisji.
- Kto w limit wejdzie, dowiaduje się, że to blokada czasowa i co z nią zrobić.
- Próg daje się zmienić na stronie bez wydawania nowej wersji wtyczki.

## Nie-cele

- Osobny ekran „dodaj zgłoszenie" w panelu administracyjnym. Rozwiązałby ten przypadek
  u źródła, ale to własny plan, nie poprawka. Osobny wpis w backlogu.
- Obsługa zaufanego proxy przy odczycie IP (patrz Ryzyko znane).
- Dotykanie trzech pozostałych ścieżek antyspamowych (nonce, honeypot, okno 3 s).

## Anty-cele

Rzeczy, które oznaczają porażkę, nawet gdyby poprawka działała:

- **Zwolnienie dostępne bez logowania.** Cokolwiek, co da się ustawić z przeglądarki
  (ciasteczko, pole w POST, nagłówek), jest zwolnieniem dla bota.
- **Zwolniony użytkownik dolicza się do licznika.** W biurze za jednym NAT-em
  organizator zużywałby limit uczestnikom. Zwolniony nie jest sprawdzany ANI nie
  inkrementuje licznika.
- **Komunikat ujawniający pozostałe mechanizmy antyspamowe.** Honeypot, nonce i okno
  3 s zostają pod wspólnym, mglistym komunikatem.

## Ograniczenia

- Nonce obowiązuje każdego, w tym zwolnionych. Zwolnienie dotyczy wyłącznie limitu.
- Uprawnienie to `Capabilities::CAP` (`edit_evreg_events`) — to samo, którym chodzą
  ekrany „Zgłoszenia" i „Kolejka maili". Bez nowego capa.
- `isRateLimited()` i `recordAttempt()` zostają publicznymi, czystymi prymitywami
  licznika (ma je test `SubmitRateLimitTest`). Zwolnienie wchodzi w `handle()`, nie w
  środek prymitywów.
- Wszystkie stringi przez i18n, text domain `event-registration`; `.pot` regenerowany.

## Podejścia

**A. Zwolnienie po uprawnieniu — wybrane.** Bot nie ma konta z uprawnieniem do eventów,
więc zwolnienie jest nieosiągalne z zewnątrz, a limit zostaje pełnoprawny dla świata.
Celuje dokładnie w zgłoszony przypadek. Koszt: nie pomaga organizatorowi, który wpisuje
anonimowo — partner potwierdził, że klient pracuje zalogowany, a filtr (niżej) daje
wyjście, gdyby jego konto nie miało tego uprawnienia.

**B. Samo podniesienie progu.** Najprostsze, ale tylko odsuwa problem: przy większej
imprezie organizator trafi w nowy próg, a my znów nie będziemy wiedzieć dlaczego.
Wchodzi jako dodatek do A, nie zamiast.

**C. Osobny ekran „dodaj zgłoszenie" w panelu.** Najlepsze docelowo — ekrany admina nie
podlegają temu limitowi, a organizator dostaje narzędzie do swojej roboty zamiast
formularza dla uczestnika. Odrzucone na teraz jako nieproporcjonalne do naprawy; do
backlogu.

## Decyzje

1. **Zwolnienie po uprawnieniu.** W `SubmitHandler::handle()`, przed sprawdzeniem
   limitu, wchodzi warunek na `current_user_can( Capabilities::CAP )`. Zwolniony
   przeskakuje `isRateLimited()` **oraz** `recordAttempt()`.

2. **Filtr `evreg_bypass_rate_limit`.** Bool, domyślnie wynik sprawdzenia uprawnienia.
   Pozwala zwolnić konkretne konto bez wydawania wersji, gdyby klient pracował na roli
   bez `edit_evreg_events`. Filtr może tylko ZWALNIAĆ albo nie — nie służy do
   zaostrzania.

3. **Osobny kod wyniku `rate_limited`.** `SubmitResult::rateLimited()` plus wpis w mapie
   `Shortcode::resultNotice()` z treścią mówiącą, że to blokada czasowa i że mija po
   godzinie, z odesłaniem do organizatora. Nonce, honeypot i okno 3 s zostają na
   `spam`.

4. **Próg 10 → 30 na godzinę, z filtrem `evreg_rate_limit`.** 10 jest za ciasne na
   człowieka walczącego z walidacją; pierwszą linią obrony są nonce, honeypot i okno
   3 s, nie licznik. Filtr istnieje, bo właśnie przekonaliśmy się, że źle dobrany próg
   kosztuje wydanie wersji.

5. **Blokada nadal czyści formularz — punkt wycofany.** Pierwotnie proponowałem, by
   `rate_limited` niósł wpisane odpowiedzi do ponownego renderu. Odtworzenie kształtu,
   którego oczekuje `FormRenderer`, wymaga `extractAnswers()`, czyli złożenia schematu
   (`EventFormLoader::load` + `nights`) — a to znaczy wykonywanie pracy na
   odrzucanym żądaniu, czyli dokładnie to, czemu limiter ma zapobiegać. Po tej
   poprawce w limit wchodzi albo bot (któremu zachowanie treści nic nie daje), albo
   uczestnik za wspólnym IP, i tak zablokowany na godzinę. Korzyść nie równoważy
   kosztu. Odrzucenie zostaje tanie: bail bez odczytu konfiguracji.

## Zostawione budującemu

- Kształt prywatnego helpera zwolnienia w `SubmitHandler` (nazwa, umiejscowienie).
- Dokładne brzmienie komunikatu po polsku, w rejestrze pozostałych komunikatów w
  `Shortcode::resultNotice()`; sens: zbyt wiele prób z tego urządzenia, odczekaj
  godzinę, albo skontaktuj się z organizatorem.
- Czy filtry dostają argumenty kontekstowe poza wartością domyślną.

## Ryzyko znane, poza zakresem

`rateKey()` czyta `REMOTE_ADDR` bez obsługi proxy. Jeśli strona stoi za Cloudflare lub
nginx proxy bez `mod_remoteip`, `REMOTE_ADDR` jest adresem edge'a dla wszystkich i cały
ruch dzieli jeden kubełek — po tej poprawce organizator przestanie się blokować, ale
wszyscy uczestnicy razem dostaną 30 zgłoszeń na godzinę na całą stronę. Diagnoza to
jedno zapytanie na produkcji (liczba wierszy `%evreg_rate_%` w `options`); jeśli
kubełek jest sklejony, potrzebna osobna poprawka na zaufane proxy. Wpis w backlogu.

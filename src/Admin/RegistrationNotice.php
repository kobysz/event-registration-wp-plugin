<?php
/**
 * Komunikat wyniku akcji panelu zgłoszeń: kod z PRG → notice admina.
 *
 * @package EvReg
 */

declare( strict_types=1 );

namespace EvReg\Admin;

use EvReg\Domain\Accommodation\NightsConfig;

defined( 'ABSPATH' ) || exit;

/**
 * Komunikat wyniku akcji panelu zgłoszeń.
 *
 * Jedna mapa kod → zdanie dla organizatora, wołana przez cztery powierzchnie panelu:
 * listę, detal, ekran edycji (po PRG, przez render()) i ekran ręcznego dodawania, który
 * odmowę pokazuje INLINE, więc zdanie bierze przez text(). Handlery produkują kody,
 * ten plik je nazywa — i nazywa je identycznie niezależnie od drogi.
 */
final class RegistrationNotice {

	/**
	 * Renderuje komunikat wyniku akcji z parametru evreg_msg.
	 *
	 * @param NightsConfig|null $nights Konfiguracja trybu „doby” rozstrzygnięta u wejścia ekranu;
	 *                                  null = tryb klasyczny albo ekran bez kontekstu eventu.
	 */
	public static function render( ?NightsConfig $nights = null ): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! isset( $_GET['evreg_msg'] ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$code     = sanitize_text_field( wp_unslash( (string) $_GET['evreg_msg'] ) );
		$messages = self::messages();

		if ( ! isset( $messages[ $code ] ) ) {
			return;
		}

		$text = $messages[ $code ][1];

		// Tryb „doby”: odmowa niesie KLUCZ doby — identyfikator wewnętrzny, którego
		// organizator nie ma czytać. Zamieniamy go na zdanie o konkretnej dobie.
		//
		// Dwa kody, bo dwa różne fakty i dwie różne rady. accommodation_full mówi o dobie
		// PEŁNEJ: ona jest w konfiguracji, ma etykietę i da się ją na formularzu odznaczyć.
		// accommodation_gone mówi o dobie USUNIĘTEJ z konfiguracji: tamtej rady nie wolno
		// jej dać, bo doby nie ma w formularzu i nie da się odznaczyć czegoś, czego nie
		// widać; nie ma też etykiety (nightLabel() degraduje wtedy do klucza), więc klucz
		// jest tu jedynym i właściwym identyfikatorem — po nim operator znajdzie dobę
		// w konfiguracji wydarzenia.
		if ( in_array( $code, array( 'accommodation_full', 'accommodation_gone' ), true ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- odczyt kodu wyniku z PRG, widok read-only.
			$night = isset( $_GET['evreg_night'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['evreg_night'] ) ) : '';

			if ( '' !== $night && 'accommodation_gone' === $code ) {
				$text = sprintf(
					/* translators: %s: klucz doby noclegowej usuniętej z konfiguracji. */
					__( 'Doba o kluczu „%s” jest zapisana w tym zgłoszeniu, ale nie ma jej już w konfiguracji wydarzenia — edycja nie została zapisana, więc nocleg, miejsce i cena zgłoszenia pozostają bez zmian. Przywróć tę dobę w ustawieniach noclegów wydarzenia, albo — jeśli nocleg ma przepaść — odznacz w tym formularzu wszystkie doby i zapisz ponownie.', 'event-registration' ),
					$night
				);
			} elseif ( '' !== $night && null !== $nights ) {
				$text = sprintf(
					/* translators: %s: etykieta doby noclegowej. */
					__( 'Doba „%s” nie ma już tyle wolnych miejsc. Odznacz ją albo wybierz inne doby.', 'event-registration' ),
					$nights->nightLabel( $night )
				);
			}
		}

		// Drugie aktywne zgłoszenie na ten sam adres to INFORMACJA, nie odmowa: zgłoszenie
		// jest zapisane, a organizator ma powody użyć adresu drugi raz (rodzina na jednej
		// skrzynce). Flaga, nie osobne kody, bo dotyczy obu wyników dodania, a zdanie
		// o duplikacie jest jedno.
		if ( in_array( $code, array( 'added', 'added_waitlist' ), true ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- odczyt flagi wyniku z PRG, widok read-only.
			$duplicate = isset( $_GET['evreg_dup'] ) && '1' === sanitize_text_field( wp_unslash( (string) $_GET['evreg_dup'] ) );

			if ( $duplicate ) {
				$text .= ' ' . __( 'Uwaga: ten sam adres e-mail ma już inne aktywne zgłoszenie na to wydarzenie.', 'event-registration' );
			}
		}

		printf(
			'<div class="notice notice-%s is-dismissible"><p>%s</p></div>',
			esc_attr( $messages[ $code ][0] ),
			esc_html( $text )
		);
	}

	/**
	 * Zwraca zdanie przypisane kodowi wyniku, bez dekoracji zależnych od adresu.
	 *
	 * Dla powierzchni, które komunikat pokazują INLINE, a nie po PRG: handler ręcznego
	 * dodawania wraca na formularz z tym, co organizator wpisał, więc kodu nie ma w adresie
	 * i render() nie ma skąd go wziąć. Zdanie zostaje jedno — dwie kopie rozjechałyby się
	 * i ten sam wynik opowiadałby dwie historie, zależnie od tego, czy był PRG.
	 *
	 * @param string $code Kod wyniku akcji.
	 *
	 * @return string Zdanie dla organizatora albo '' dla kodu nieznanego.
	 */
	public static function text( string $code ): string {
		$messages = self::messages();

		return isset( $messages[ $code ] ) ? $messages[ $code ][1] : '';
	}

	/**
	 * Mapa kod wyniku → [poziom notice, zdanie dla organizatora].
	 *
	 * Jedno źródło dla render() (po PRG) i text() (inline). Funkcja, nie stała klasy,
	 * bo zdania idą przez __() — tłumaczenie musi zostać policzone po załadowaniu
	 * katalogu, a nie w czasie kompilacji pliku.
	 *
	 * @return array<string,array{0:string,1:string}>
	 */
	private static function messages(): array {
		return array(
			'confirmed'                 => array( 'success', __( 'Zgłoszenie potwierdzone.', 'event-registration' ) ),
			'cancelled'                 => array( 'success', __( 'Zgłoszenie anulowane, miejsce zwolnione.', 'event-registration' ) ),
			'promoted'                  => array( 'success', __( 'Awansowano z listy rezerwowej — wysłano prośbę o potwierdzenie.', 'event-registration' ) ),
			// Dwa kody awansu, bo awans robi dwie różne rzeczy. Zgłoszenie publiczne wraca
			// jako oczekujące i dostaje mail z prośbą o potwierdzenie; wpis ręczny wraca
			// POTWIERDZONY i nie dostaje nic, więc to samo zdanie byłoby o nim nieprawdą
			// (patrz RegistrationsScreen::handle_promote). promoted_no_accommodation jest
			// wspólny — o mailu nie mówi, więc jest prawdziwy dla obu pochodzeń.
			'promoted_manual'           => array( 'success', __( 'Awansowano z listy rezerwowej — zgłoszenie wpisane ręcznie jest od razu potwierdzone, więc nie wysłano żadnego e-maila.', 'event-registration' ) ),
			'promoted_no_accommodation' => array( 'warning', __( 'Awansowano z listy rezerwowej, ale wybrany nocleg był już pełny — zgłoszenie nie ma przyznanego noclegu.', 'event-registration' ) ),
			'occupancy_mismatch'        => array( 'error', __( 'Nie można awansować: wybrany pokój ma dziś inną liczbę osób niż zapisano w zgłoszeniu. Popraw nocleg albo osobę towarzyszącą w edycji odpowiedzi.', 'event-registration' ) ),
			'deleted'                   => array( 'success', __( 'Zgłoszenie trwale usunięte.', 'event-registration' ) ),
			'note'                      => array( 'success', __( 'Notatka zapisana.', 'event-registration' ) ),
			'edited'                    => array( 'success', __( 'Odpowiedzi zgłoszenia zaktualizowane.', 'event-registration' ) ),
			'rejected'                  => array( 'error', __( 'Brak wolnych miejsc — nie można awansować.', 'event-registration' ) ),
			'invalid_status'            => array( 'error', __( 'Akcja niedozwolona dla tego statusu.', 'event-registration' ) ),
			'not_found'                 => array( 'error', __( 'Nie znaleziono zgłoszenia.', 'event-registration' ) ),
			'capacity_full'             => array( 'error', __( 'Brak wolnych miejsc dla wybranego typu zgłoszenia.', 'event-registration' ) ),
			'accommodation_full'        => array( 'error', __( 'Brak wolnych miejsc dla wybranego noclegu.', 'event-registration' ) ),
			'accommodation_gone'        => array( 'error', __( 'Doba zapisana w tym zgłoszeniu nie istnieje już w konfiguracji wydarzenia, więc edycja nie została zapisana.', 'event-registration' ) ),
			// Trzy różne przyczyny jeden kod: niepoprawny inwentarz noclegów, schemat,
			// którego nie udało się złożyć, i żądanie, którego asembler nie zbudował.
			// Zdanie nie może więc wskazywać palcem na noclegi — wskazuje konfigurację
			// formularza, a noclegi nazywa tylko jako najczęstszą przyczynę.
			'config_error'              => array( 'error', __( 'Konfiguracja formularza tego wydarzenia jest niepoprawna — popraw ją w ustawieniach wydarzenia (najczęściej chodzi o noclegi).', 'event-registration' ) ),
			'export_no_event'           => array( 'error', __( 'Wybierz event, aby wyeksportować zgłoszenia.', 'event-registration' ) ),
			// Komunikaty ręcznego dodawania milczą o mailu: o tym, czy cokolwiek poszło,
			// decyduje przełącznik wysyłki na formularzu, a nie wynik zapisu. Zdanie
			// o wysłanym (albo niewysłanym) potwierdzeniu byłoby prawdziwe tylko dla
			// jednego ustawienia przełącznika.
			'added'                     => array( 'success', __( 'Zgłoszenie dodane ręcznie i potwierdzone.', 'event-registration' ) ),
			'added_waitlist'            => array( 'warning', __( 'Zgłoszenie dodane ręcznie na listę rezerwową — nie było wolnego miejsca, więc zajmie je dopiero po awansie.', 'event-registration' ) ),
			'add_no_event'              => array( 'error', __( 'Nie znaleziono wydarzenia, do którego miało trafić zgłoszenie — zacznij od wyboru wydarzenia.', 'event-registration' ) ),
			'add_notify_no_email'       => array( 'error', __( 'Zgłoszenie nie zostało zapisane: wysyłka potwierdzenia jest włączona, a formularz tego wydarzenia nie ma pola e-mail, więc nie ma na jaki adres wysłać. Odznacz wysyłkę albo dodaj do formularza pole e-mail.', 'event-registration' ) ),
			// Awaria zapisu po naszej stronie, nie pomyłka organizatora: treść jak na
			// formularzu publicznym (Shortcode::resultNotice), bez rady „skontaktuj się
			// z organizatorem" — tu organizator właśnie to czyta.
			'add_failed'                => array( 'error', __( 'Nie udało się zapisać zgłoszenia z powodu błędu po naszej stronie. Nic nie zostało zapisane — spróbuj ponownie za chwilę.', 'event-registration' ) ),
		);
	}
}

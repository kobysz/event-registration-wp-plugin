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
 * Jedna mapa kod → zdanie dla organizatora, wołana przez trzy powierzchnie panelu:
 * listę, detal i ekran edycji. Handlery produkują kody (PRG), ten plik je nazywa.
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
		$messages = array(
			'confirmed'                 => array( 'success', __( 'Zgłoszenie potwierdzone.', 'event-registration' ) ),
			'cancelled'                 => array( 'success', __( 'Zgłoszenie anulowane, miejsce zwolnione.', 'event-registration' ) ),
			'promoted'                  => array( 'success', __( 'Awansowano z listy rezerwowej — wysłano prośbę o potwierdzenie.', 'event-registration' ) ),
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
			'config_error'              => array( 'error', __( 'Konfiguracja noclegów tego wydarzenia jest niepoprawna — popraw ją w ustawieniach wydarzenia.', 'event-registration' ) ),
			'export_no_event'           => array( 'error', __( 'Wybierz event, aby wyeksportować zgłoszenia.', 'event-registration' ) ),
		);

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

		printf(
			'<div class="notice notice-%s is-dismissible"><p>%s</p></div>',
			esc_attr( $messages[ $code ][0] ),
			esc_html( $text )
		);
	}
}

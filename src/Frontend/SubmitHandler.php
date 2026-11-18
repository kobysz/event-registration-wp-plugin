<?php
/**
 * Obsługa submisji publicznego formularza.
 *
 * @package EvReg
 */

declare( strict_types=1 );

namespace EvReg\Frontend;

use EvReg\Admin\Capabilities;
use EvReg\Domain\Accommodation\AccommodationConfig;
use EvReg\Domain\Schema\FormSchema;
use EvReg\Domain\Schema\SchemaException;
use EvReg\Services\ReservationRequest;
use EvReg\Services\ReservationService;

defined( 'ABSPATH' ) || exit;

/**
 * Obsługa submisji publicznego formularza (POST-to-self).
 */
final class SubmitHandler {

	private const MIN_FILL_SECONDS = 3;

	/**
	 * Domyślny próg dozwolonych prób submisji na IP w oknie czasowym.
	 *
	 * Wartość efektywną zwraca `rateLimit()` (filtr `evreg_rate_limit`).
	 */
	public const RATE_LIMIT = 30;

	private const RATE_WINDOW = HOUR_IN_SECONDS;

	/**
	 * Magazyn wyników submisji bieżącego żądania, per event.
	 *
	 * @var array<int,SubmitResult>
	 */
	private static array $results = array();

	/**
	 * Tworzy handler submisji z wstrzykniętym loaderem schematu i serwisem rezerwacji.
	 *
	 * @param EventFormLoader    $loader       Loader złożonego schematu eventu.
	 * @param ReservationService $reservations Serwis rezerwacji miejsc.
	 */
	public function __construct(
		private readonly EventFormLoader $loader,
		private readonly ReservationService $reservations
	) {
	}

	/**
	 * Podpina obsługę submisji pod `template_redirect`.
	 */
	public static function register(): void {
		add_action(
			'template_redirect',
			static function (): void {
				( new self(
					new EventFormLoader( new \EvReg\Persistence\EventConfigRepository() ),
					new ReservationService( new \EvReg\Persistence\RegistrationRepository(), new \EvReg\Persistence\EventConfigRepository() )
				) )->handle();
			}
		);
	}

	/**
	 * Wykrywa POST submisji formularza, weryfikuje nonce i limit prób, woła `process()`
	 * i wykonuje PRG redirect na sukcesie lub zapisuje wynik do magazynu na błędzie.
	 */
	public function handle(): void {
		if ( '1' !== (string) ( $_POST['evreg_submit'] ?? '' ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			return;
		}

		$event_id = isset( $_POST['evreg_event'] ) ? (int) $_POST['evreg_event'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing

		if ( ! isset( $_POST['evreg-nonce'] ) || ! is_string( $_POST['evreg-nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['evreg-nonce'] ) ), 'evreg_submit_' . $event_id ) ) {
			self::$results[ $event_id ] = SubmitResult::spam();
			return;
		}

		// Zwolniony z limitu nie jest ani blokowany, ani liczony: licznik jest per IP, więc
		// jego próby (np. rejestrowanie kilku osób z jednej przeglądarki, wspólny adres za NAT-em)
		// zużywałyby próg pozostałych osób z tego samego adresu.
		if ( ! self::bypassesRateLimit() ) {
			if ( self::isRateLimited() ) {
				self::$results[ $event_id ] = SubmitResult::rateLimited();
				return;
			}
			self::recordAttempt();
		}

		$post   = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$result = $this->process( $event_id, is_array( $post ) ? $post : array() );

		if ( $result->isSuccess() ) {
			wp_safe_redirect( add_query_arg( 'evreg', $result->code(), remove_query_arg( array( 'evreg', 'evreg_confirm' ) ) ) );
			exit;
		}

		self::$results[ $event_id ] = $result;
	}

	/**
	 * Zwraca wynik submisji bieżącego żądania dla danego eventu, jeśli zapisany.
	 *
	 * @param int $event_id ID posta eventu.
	 */
	public static function resultFor( int $event_id ): ?SubmitResult {
		return self::$results[ $event_id ] ?? null;
	}

	/**
	 * Buduje klucz transienta rate-limitu na podstawie adresu IP żądania.
	 */
	private static function rateKey(): string {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( (string) $_SERVER['REMOTE_ADDR'] ) ) : 'unknown';
		return 'evreg_rate_' . md5( $ip );
	}

	/**
	 * Czy bieżące IP osiągnęło próg dozwolonych prób submisji w oknie czasowym.
	 */
	public static function isRateLimited(): bool {
		return (int) get_transient( self::rateKey() ) >= self::rateLimit();
	}

	/**
	 * Efektywny próg prób submisji na IP w oknie czasowym (filtr `evreg_rate_limit`).
	 *
	 * Wynik nigdy nie jest mniejszy niż 1: filtr zwracający 0 (lub nieliczbową wartość)
	 * zablokowałby wszystkich już przy pierwszej próbie.
	 */
	public static function rateLimit(): int {
		$limit = apply_filters( 'evreg_rate_limit', self::RATE_LIMIT );

		return max( 1, is_numeric( $limit ) ? (int) $limit : 1 );
	}

	/**
	 * Czy bieżący użytkownik jest zwolniony z limitu prób (zarządza eventami).
	 *
	 * Zwolnienie wynika wyłącznie z zalogowanego użytkownika z capability `Capabilities::CAP`
	 * — nie ma żadnego nośnika z POST, ciasteczka ani nagłówka, który anonim mógłby podrobić.
	 * Filtr `evreg_bypass_rate_limit` ma ostatnie słowo w obie strony.
	 */
	public static function bypassesRateLimit(): bool {
		return (bool) apply_filters( 'evreg_bypass_rate_limit', current_user_can( Capabilities::CAP ) );
	}

	/**
	 * Odnotowuje próbę submisji dla bieżącego IP.
	 */
	public static function recordAttempt(): void {
		$key = self::rateKey();
		set_transient( $key, (int) get_transient( $key ) + 1, self::RATE_WINDOW );
	}

	/**
	 * Jądro submisji bez redirectu — testowalne.
	 *
	 * @param int                 $event_id ID posta eventu.
	 * @param array<string,mixed> $post     Surowe $_POST.
	 *
	 * @throws \RuntimeException Gdy ścieżka klasyczna rzuci wyjątek zapisu (w trybie „doby” jest łapany i logowany).
	 */
	public function process( int $event_id, array $post ): SubmitResult {
		if ( '' !== (string) ( $post['evreg_hp'] ?? '' ) ) {
			return SubmitResult::spam();
		}
		if ( time() - (int) ( $post['evreg_ts'] ?? 0 ) < self::MIN_FILL_SECONDS ) {
			return SubmitResult::spam();
		}

		$schema = $this->loader->load( $event_id );
		if ( null === $schema ) {
			return SubmitResult::configError();
		}

		// Tryb „doby” rozstrzygamy RAZ, tu, u wejścia: niepoprawna konfiguracja dób
		// (np. zduplikowany klucz) nie może dotrzeć do asemblera ani walidatora.
		try {
			$nights = $this->loader->nights( $event_id );
		} catch ( SchemaException ) {
			return SubmitResult::configError();
		}

		$assembled = ( new SubmissionAssembler() )->assemble( $schema, $post, $nights );
		if ( ! $assembled->isValid() ) {
			return SubmitResult::invalid( $assembled->errors(), $assembled->values() );
		}

		$request = $assembled->request();
		if ( '' === $request->email ) {
			return SubmitResult::configError();
		}

		// Niespójna para pokój/osoba towarzysząca: pokój dwuosobowy bez towarzyszącej
		// albo jednoosobowy z nią. Na formularzu publicznym reguła obowiązuje zawsze —
		// każda submisja to nowy wybór, nie ma „stanu przed".
		$mismatch = $this->companionRoomMismatch( $schema, $request );
		if ( null !== $mismatch ) {
			$accommodation_key = $schema->accommodationFieldKey();

			return null === $accommodation_key
				? SubmitResult::rejected()
				: SubmitResult::invalid( array( $accommodation_key => $mismatch ), $assembled->rawAnswers() );
		}

		try {
			$reservation = $this->reservations->reserve( $event_id, $request );
		} catch ( \RuntimeException $e ) {
			// Tryb „doby”: serwis rzuca, gdy liczba zapisanych wierszy dób nie zgadza się z
			// żądaną (awaria zapisu, transakcja już wycofana). To głośny sygnał dla operatora,
			// nie błąd wyboru uczestnika — logujemy go i pokazujemy ogólny komunikat na poziomie
			// formularza, nigdy fatala ani „brak miejsc”. Ścieżka klasyczna nie ma takiego rzutu,
			// więc jej wyjątki nadal lecą dalej.
			// O TRYB pytamy rozstrzygnięcie z wejścia, nie żądanie: `request->nights === null`
			// znaczy „wyboru dób nie podano" (tak składa się submisja z polem noclegu ukrytym
			// warunkiem, B21), a nie „to event klasyczny".
			if ( null === $nights ) {
				throw $e;
			}

			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( 'evreg reservation failed: ' . $e->getMessage() );

			return SubmitResult::failure( $assembled->rawAnswers() );
		}

		// Wybrany nocleg zajęty w wyścigu: zgłoszenie nie zostało zapisane, więc wracamy
		// na formularz z błędem przy polu noclegu i ODTWORZONYMI surowymi odpowiedziami.
		if ( 'accommodation_full' === $reservation->code ) {
			$accommodation_key = $schema->accommodationFieldKey();

			// W trybie „doby” kod niesie klucz doby, która zablokowała wybór — renderer
			// zamienia go na zdanie z jej etykietą. Ścieżka klasyczna zostaje bez zmian.
			$code = '' === $reservation->blockingNight ? 'accommodation_full' : 'night_full:' . $reservation->blockingNight;

			return null === $accommodation_key
				? SubmitResult::rejected()
				: SubmitResult::invalid(
					array( $accommodation_key => $code ),
					$assembled->rawAnswers()
				);
		}

		return match ( $reservation->code ) {
			'reserved'   => SubmitResult::success( 'reserved' ),
			'waitlisted' => SubmitResult::success( 'waitlisted' ),
			'duplicate'  => SubmitResult::duplicate(),
			default      => SubmitResult::rejected(),
		};
	}

	/**
	 * Zwraca kod błędu, gdy wybrany pokój nie zgadza się z flagą osoby towarzyszącej.
	 *
	 * @param FormSchema         $schema  Złożony schemat formularza.
	 * @param ReservationRequest $request Zbudowane żądanie rezerwacji.
	 */
	private function companionRoomMismatch( FormSchema $schema, ReservationRequest $request ): ?string {
		if ( null === $request->selection ) {
			return null;
		}

		$key = $schema->accommodationFieldKey();
		if ( null === $key ) {
			return null;
		}

		$field = $schema->findField( $key );
		if ( null === $field ) {
			return null;
		}

		$room = AccommodationConfig::fromArray( $field->config )->room( $request->selection->roomKey );

		return null === $room ? null : $room->companionMismatch( $request->companion );
	}
}

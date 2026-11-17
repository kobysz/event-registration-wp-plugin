import { useState } from '@wordpress/element';
import { Button, Notice, SelectControl, TextControl, ToggleControl } from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';
import {
	emptyAccommodation,
	addPackage,
	removePackage,
	updatePackage,
	addRoom,
	removeRoom,
	updateRoom,
	setInventoryCell,
	getInventoryCell,
	setAllowNone,
	setCompanionEnabled,
	setCompanionCountsEvent,
	setMode,
	addNight,
	removeNight,
	setNightField,
	NIGHT_KEY_MAX,
	nightKeyError,
} from '../ops/accommodationOps';

function NightKeyField( { acc, night, onCommit } ) {
	// The typed text is kept locally and committed only when valid, so an
	// empty or colliding key never reaches the config (the public form would
	// go offline on a duplicate).
	const [ draft, setDraft ] = useState( night.key );
	const problem = nightKeyError( acc, night.key, draft );
	let message = '';
	if ( 'duplicate' === problem ) {
		message = __( 'Inna doba ma już taki klucz — zmiana nie została zapisana.', 'event-registration' );
	} else if ( 'empty' === problem ) {
		message = __( 'Klucz doby nie może być pusty — zmiana nie została zapisana.', 'event-registration' );
	}

	return (
		<>
			<TextControl
				label={ __( 'Klucz', 'event-registration' ) }
				help={ __( 'Zmiana klucza rozłączy istniejące rezerwacje tej doby. Maksymalnie 64 znaki.', 'event-registration' ) }
				value={ draft }
				maxLength={ NIGHT_KEY_MAX }
				onBlur={ () => setDraft( night.key ) }
				onChange={ ( v ) => {
					setDraft( v );
					if ( null === nightKeyError( acc, night.key, v ) ) {
						onCommit( v );
					}
				} }
			/>
			{ message && <p className="evreg-night-row__warning">{ message }</p> }
		</>
	);
}

export default function AccommodationTab( { config, update, stats } ) {
	const acc = { ...emptyAccommodation(), ...( config.accommodation || {} ) };
	const setAcc = update( 'accommodation' );
	const nightsMode = 'nights' === acc.mode;
	const modeLocked = !! ( stats && stats.mode_locked );
	const nightSeats = ( stats && stats.night_seats ) || {};

	return (
		<div className="evreg-accommodation-tab">
			<SelectControl
				label={ __( 'Tryb noclegów', 'event-registration' ) }
				value={ nightsMode ? 'nights' : 'classic' }
				disabled={ modeLocked }
				options={ [
					{ value: 'classic', label: __( 'Klasyczny (pakiety i pokoje)', 'event-registration' ) },
					{ value: 'nights', label: __( 'Doby (pula miejsc na dobę)', 'event-registration' ) },
				] }
				onChange={ ( mode ) => setAcc( setMode( acc, mode ) ) }
			/>
			{ modeLocked && (
				<Notice status="warning" isDismissible={ false }>
					{ __( 'Trybu noclegów nie można zmienić: wydarzenie ma już zgłoszenia z noclegiem. Anuluj te zgłoszenia, aby zmienić tryb.', 'event-registration' ) }
				</Notice>
			) }

			{ nightsMode && (
				<div className="evreg-nights">
					<h3>{ __( 'Doby', 'event-registration' ) }</h3>
					{ ( acc.nights || [] ).map( ( night ) => {
						const reserved = Number( nightSeats[ night.key ] ) || 0;
						return (
							<div className="evreg-night-row" key={ night.key }>
								<NightKeyField
									acc={ acc }
									night={ night }
									onCommit={ ( v ) => setAcc( setNightField( acc, night.key, 'key', v ) ) }
								/>
								<TextControl
									label={ __( 'Nazwa', 'event-registration' ) }
									value={ night.label || '' }
									onChange={ ( v ) => setAcc( setNightField( acc, night.key, 'label', v ) ) }
								/>
								<TextControl
									type="number"
									min="0"
									label={ __( 'Cena za miejsce', 'event-registration' ) }
									value={ night.price ?? '' }
									onChange={ ( v ) => setAcc( setNightField( acc, night.key, 'price', v ) ) }
								/>
								<TextControl
									type="number"
									min="0"
									label={ __( 'Liczba miejsc', 'event-registration' ) }
									help={ __( '0 = brak miejsc (doba zamknięta).', 'event-registration' ) }
									value={ night.seats ?? '' }
									onChange={ ( v ) => setAcc( setNightField( acc, night.key, 'seats', v ) ) }
								/>
								<TextControl
									type="number"
									min="0"
									label={ __( 'Limit zgłoszeń z osobą towarzyszącą', 'event-registration' ) }
									help={ __( 'Puste = bez limitu. 0 = doba dostępna tylko dla pojedynczych zgłoszeń.', 'event-registration' ) }
									value={ night.companion_cap ?? '' }
									onChange={ ( v ) => setAcc( setNightField( acc, night.key, 'companion_cap', v ) ) }
								/>
								{ reserved > 0 && (
									<p className="evreg-night-row__warning">
										{ sprintf(
											/* translators: %d: number of reserved seats. */
											__( 'Ta doba ma %d zarezerwowanych miejsc. Usunięcie jej z listy nie zmieni istniejących zgłoszeń, ale doba zniknie z formularza.', 'event-registration' ),
											reserved
										) }
									</p>
								) }
								<Button isDestructive onClick={ () => setAcc( removeNight( acc, night.key ) ) }>
									{ __( 'Usuń', 'event-registration' ) }
								</Button>
							</div>
						);
					} ) }
					<Button variant="secondary" onClick={ () => setAcc( addNight( acc ) ) }>
						{ __( 'Dodaj dobę', 'event-registration' ) }
					</Button>
				</div>
			) }

			{ ! nightsMode && (
				<div className="evreg-classic">
			<h3>{ __( 'Pakiety', 'event-registration' ) }</h3>
			{ ( acc.packages || [] ).map( ( pkg ) => (
				<div className="evreg-pkg-row" key={ pkg.key }>
					<TextControl
						label={ __( 'Klucz', 'event-registration' ) }
						help={ __( 'Zmiana klucza rozłączy istniejące rezerwacje — w historii zgłoszeń zostanie zapisana nazwa z chwili rezerwacji.', 'event-registration' ) }
						value={ pkg.key }
						onChange={ ( key ) => setAcc( updatePackage( acc, pkg.key, { key } ) ) }
					/>
					<TextControl
						label={ __( 'Nazwa', 'event-registration' ) }
						value={ pkg.label || '' }
						onChange={ ( label ) => setAcc( updatePackage( acc, pkg.key, { label } ) ) }
					/>
					<Button isDestructive onClick={ () => setAcc( removePackage( acc, pkg.key ) ) }>
						{ __( 'Usuń', 'event-registration' ) }
					</Button>
				</div>
			) ) }
			<Button variant="secondary" onClick={ () => setAcc( addPackage( acc ) ) }>
				{ __( 'Dodaj pakiet', 'event-registration' ) }
			</Button>

			<h3>{ __( 'Pokoje', 'event-registration' ) }</h3>
			{ ( acc.rooms || [] ).map( ( room ) => (
				<div className="evreg-room-row" key={ room.key }>
					<TextControl
						label={ __( 'Klucz', 'event-registration' ) }
						help={ __( 'Zmiana klucza rozłączy istniejące rezerwacje — w historii zgłoszeń zostanie zapisana nazwa z chwili rezerwacji.', 'event-registration' ) }
						value={ room.key }
						onChange={ ( key ) => setAcc( updateRoom( acc, room.key, { key } ) ) }
					/>
					<TextControl
						label={ __( 'Nazwa', 'event-registration' ) }
						value={ room.label || '' }
						onChange={ ( label ) => setAcc( updateRoom( acc, room.key, { label } ) ) }
					/>
					<ToggleControl
						label={ __( 'Pole współlokatora', 'event-registration' ) }
						checked={ !! room.roommate_field }
						onChange={ ( roommate_field ) =>
							setAcc( updateRoom( acc, room.key, { roommate_field } ) )
						}
					/>
					<SelectControl
						label={ __( 'Liczba osób', 'event-registration' ) }
						help={ __( 'Nieustawione = jak dotąd (osoba towarzysząca opcjonalna). 1 osoba = bez towarzyszącej. 2 osoby = wymaga towarzyszącej, a pole współlokatora się nie pokazuje. Opcja „2 osoby" wymaga włączonej osoby towarzyszącej w ustawieniach noclegów poniżej.', 'event-registration' ) }
						value={ String( room.occupancy ?? 0 ) }
						options={ [
							{ value: '0', label: __( '— nieustawione —', 'event-registration' ) },
							{ value: '1', label: __( '1 osoba', 'event-registration' ) },
							{ value: '2', label: __( '2 osoby', 'event-registration' ), disabled: ! acc.companion_enabled },
						] }
						onChange={ ( occupancy ) =>
							setAcc( updateRoom( acc, room.key, { occupancy: parseInt( occupancy, 10 ) } ) )
						}
					/>
					{ 2 === ( room.occupancy ?? 0 ) && ! acc.companion_enabled && (
						<p className="evreg-room-row__warning">
							{ __( 'Ten pokój jest ustawiony na 2 osoby, ale osoba towarzysząca jest wyłączona dla tego wydarzenia — ustawienie jest ignorowane. Włącz osobę towarzyszącą albo zmień liczbę osób.', 'event-registration' ) }
						</p>
					) }
					<Button isDestructive onClick={ () => setAcc( removeRoom( acc, room.key ) ) }>
						{ __( 'Usuń', 'event-registration' ) }
					</Button>
				</div>
			) ) }
			<Button variant="secondary" onClick={ () => setAcc( addRoom( acc ) ) }>
				{ __( 'Dodaj pokój', 'event-registration' ) }
			</Button>

			<h3>{ __( 'Inwentarz (limit / cena za parę)', 'event-registration' ) }</h3>
			<table className="evreg-inventory widefat">
				<thead>
					<tr>
						<th>{ __( 'Pakiet \\ Pokój', 'event-registration' ) }</th>
						{ ( acc.rooms || [] ).map( ( room ) => (
							<th key={ room.key }>{ room.label || room.key }</th>
						) ) }
					</tr>
				</thead>
				<tbody>
					{ ( acc.packages || [] ).map( ( pkg ) => (
						<tr key={ pkg.key }>
							<th>{ pkg.label || pkg.key }</th>
							{ ( acc.rooms || [] ).map( ( room ) => {
								const cell = getInventoryCell( acc, pkg.key, room.key ) || {};
								return (
									<td key={ room.key }>
										<TextControl
											type="number"
											label={ __( 'Limit', 'event-registration' ) }
											value={ cell.capacity ?? '' }
											onChange={ ( capacity ) =>
												setAcc(
													setInventoryCell( acc, pkg.key, room.key, {
														capacity: parseInt( capacity, 10 ) || 0,
													} )
												)
											}
										/>
										<TextControl
											type="number"
											label={ __( 'Cena', 'event-registration' ) }
											value={ cell.price ?? '' }
											onChange={ ( price ) =>
												setAcc(
													setInventoryCell( acc, pkg.key, room.key, {
														price: parseFloat( price ) || 0,
													} )
												)
											}
										/>
									</td>
								);
							} ) }
						</tr>
					) ) }
				</tbody>
			</table>

				</div>
			) }

			<ToggleControl
				label={ __( 'Zezwól na brak noclegu', 'event-registration' ) }
				checked={ acc.allow_none !== false }
				onChange={ ( value ) => setAcc( setAllowNone( acc, value ) ) }
			/>

			<h3>{ __( 'Osoba towarzysząca', 'event-registration' ) }</h3>
			<ToggleControl
				label={ __( 'Opcja osoby towarzyszącej', 'event-registration' ) }
				checked={ !! acc.companion_enabled }
				onChange={ ( value ) => setAcc( setCompanionEnabled( acc, value ) ) }
			/>
			{ acc.companion_enabled && (
				<ToggleControl
					label={ __(
						'Osoba towarzysząca zajmuje miejsce w limicie wydarzenia',
						'event-registration'
					) }
					checked={ !! acc.companion_counts_event }
					onChange={ ( value ) => setAcc( setCompanionCountsEvent( acc, value ) ) }
				/>
			) }
		</div>
	);
}

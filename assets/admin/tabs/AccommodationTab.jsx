import { Button, TextControl, ToggleControl } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
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
} from '../ops/accommodationOps';

export default function AccommodationTab( { config, update } ) {
	const acc = config.accommodation && config.accommodation.packages
		? config.accommodation
		: emptyAccommodation();
	const setAcc = update( 'accommodation' );

	return (
		<div className="evreg-accommodation-tab">
			<h3>{ __( 'Pakiety', 'event-registration' ) }</h3>
			{ ( acc.packages || [] ).map( ( pkg ) => (
				<div className="evreg-pkg-row" key={ pkg.key }>
					<TextControl
						label={ __( 'Klucz', 'event-registration' ) }
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

( function () {
	function toList( value ) {
		if ( value === null || value === undefined ) { return []; }
		if ( Array.isArray( value ) ) { return value.map( String ); }
		var s = String( value );
		return s === '' ? [] : [ s ];
	}

	function listEquals( a, b ) {
		if ( a.length !== b.length ) { return false; }
		for ( var i = 0; i < a.length; i++ ) { if ( a[ i ] !== b[ i ] ) { return false; } }
		return true;
	}

	function intersects( a, b ) {
		for ( var i = 0; i < a.length; i++ ) { if ( b.indexOf( a[ i ] ) !== -1 ) { return true; } }
		return false;
	}

	// Lustro EvReg\Domain\Conditions\ConditionEngine::isMet (semantyka listowa).
	function isMet( operator, answer, expected ) {
		switch ( operator ) {
			case 'equals': return listEquals( answer, expected );
			case 'not_equals': return ! listEquals( answer, expected );
			case 'in': return intersects( answer, expected );
			case 'not_in': return ! intersects( answer, expected );
			case 'empty': return answer.length === 0;
			case 'not_empty': return answer.length > 0;
			default: return true;
		}
	}

	// Bieżąca wartość pola triggera jako lista stringów (lustro toList na odpowiedzi).
	function readTrigger( form, key ) {
		var out = [];
		var nodes = form.querySelectorAll(
			'[name="evreg_field[' + key + ']"], [name="evreg_field[' + key + '][]"]'
		);
		nodes.forEach( function ( node ) {
			if ( node.type === 'checkbox' || node.type === 'radio' ) {
				if ( node.checked ) { out.push( String( node.value ) ); }
			} else if ( node.value !== '' ) {
				out.push( String( node.value ) );
			}
		} );
		return out;
	}

	function apply( form ) {
		form.querySelectorAll( '[data-evreg-when-field]' ).forEach( function ( el ) {
			var key = el.getAttribute( 'data-evreg-when-field' );
			var operator = el.getAttribute( 'data-evreg-when-operator' );
			var raw = el.getAttribute( 'data-evreg-when-value' );
			var expected;
			try { expected = toList( JSON.parse( raw ) ); } catch ( e ) { expected = toList( raw ); }
			el.hidden = ! isMet( operator, readTrigger( form, key ), expected );
		} );
	}

	function selectedSlotAllowsRoommate( container ) {
		var radio = container.querySelector( 'input[type="radio"][name$="[slot]"]:checked' );
		if ( radio ) { return radio.getAttribute( 'data-evreg-roommate' ) === '1'; }
		var select = container.querySelector( 'select[name$="[slot]"]' );
		if ( select && select.selectedIndex >= 0 ) {
			var option = select.options[ select.selectedIndex ];
			return !! ( option && option.getAttribute( 'data-evreg-roommate' ) === '1' );
		}
		return false;
	}

	function applyRoommate( container ) {
		var input = container.querySelector( '[data-evreg-roommate-input]' );
		if ( ! input ) { return; }
		input.hidden = ! selectedSlotAllowsRoommate( container );
	}

	function applyCompanion( form ) {
		var checkbox = form.querySelector( 'input[name="evreg_companion"]' );
		var input = form.querySelector( '[data-evreg-companion-input]' );
		if ( ! checkbox || ! input ) { return; }
		input.hidden = ! checkbox.checked;
	}

	// Lustro EvReg\Domain\Accommodation\RoomOccupancy::seats.
	function seatsFor( occupancy, companion ) {
		return occupancy === 0 ? ( companion ? 2 : 1 ) : occupancy;
	}

	// Slot bywa zamknięty z DWÓCH niezależnych powodów: brak miejsc (serwer liczy to
	// świeżo i oznacza wyczerpane) oraz niepasująca liczba osób w pokoju. `disabled`
	// jest SUMĄ powodów — gdyby każdy powód miał własną pętlę, druga odblokowałaby
	// slot zamknięty przez pierwszą. Elementy bez tych atrybutów (np. <option> w edycji
	// admina) nie są dotykane.
	function applySlotState( form, clearStale ) {
		var anyMatch = false;
		var dropped = false;
		var checkbox = form.querySelector( 'input[name="evreg_companion"]' );
		var companion = !! ( checkbox && checkbox.checked );

		form.querySelectorAll( '[data-evreg-occupancy], [data-evreg-seats-left]' ).forEach( function ( el ) {
			var occupancy = parseInt( el.getAttribute( 'data-evreg-occupancy' ), 10 );
			if ( isNaN( occupancy ) ) { occupancy = 0; }

			var mismatch = occupancy !== 0 && occupancy !== ( companion ? 2 : 1 );

			var left = parseInt( el.getAttribute( 'data-evreg-seats-left' ), 10 );
			var full = ! isNaN( left ) && left < seatsFor( occupancy, companion );

			if ( ! mismatch ) { anyMatch = true; }
			el.hidden = mismatch;
			el.disabled = mismatch || full;

			if ( el.disabled && el.checked ) {
				el.checked = false;
				// Pusta grupa radio nie wysyła klucza [slot], co serwer odczytałby jako
				// „Bez noclegu" bez wiedzy zgłaszającego się — przenosimy zaznaczenie jawnie.
				var none = form.querySelector( 'input[name="' + el.name + '"][value=""]' );
				if ( none ) { none.checked = true; }
				dropped = true;
			}

			var wrap = el.closest( '.evreg-choice' );
			if ( wrap ) {
				wrap.hidden = mismatch;
				wrap.classList.toggle( 'is-full', full );
				var note = wrap.querySelector( '.evreg-seats-note' );
				if ( note ) { note.hidden = ! full; }
			}
		} );

		// Komunikaty niosą tekst z serwera; tu tylko przełączamy widoczność. Pusty zestaw
		// wierszy nie ma akapitu (serwer go wtedy nie renderuje), więc anyMatch=false nic nie pokaże.
		var mismatchNote = form.querySelector( '.evreg-accommodation-mismatch' );
		if ( mismatchNote ) { mismatchNote.hidden = anyMatch; }
		var droppedNote = form.querySelector( '.evreg-accommodation-dropped' );
		if ( droppedNote ) {
			// Przy starcie nie chowamy komunikatu wyrenderowanego przez serwer po błędzie POST.
			if ( dropped ) { droppedNote.hidden = false; } else if ( clearStale ) { droppedNote.hidden = true; }
		}
	}

	// Programowe el.checked = false w applySlotState NIE emituje zdarzenia `change`,
	// więc widoczność pola współlokatora trzeba przeliczyć ręcznie — inaczej zostaje
	// widoczna po odebraniu zaznaczenia. Kontenery .evreg-accommodation poza
	// formularzem (ekran edycji w adminie) obsługuje druga pętla.
	function refreshAccommodation( form ) {
		form.querySelectorAll( '.evreg-accommodation' ).forEach( applyRoommate );
	}

	document.querySelectorAll( '.evreg-form' ).forEach( function ( form ) {
		apply( form );
		applyCompanion( form );
		applySlotState( form, false );
		refreshAccommodation( form );
		form.addEventListener( 'change', function () {
			apply( form );
			applyCompanion( form );
			applySlotState( form, true );
			refreshAccommodation( form );
		} );
		form.addEventListener( 'input', function () { apply( form ); } );
	} );

	document.querySelectorAll( '.evreg-accommodation' ).forEach( function ( container ) {
		applyRoommate( container );
		container.addEventListener( 'change', function ( e ) {
			if ( e.target && /\[slot\]$/.test( e.target.name || '' ) ) {
				applyRoommate( container );
			}
		} );
	} );
}() );

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

	// Ile miejsc zajmie zgłoszenie w slocie: 2 z osobą towarzyszącą, inaczej 1.
	// Serwer wyłącza już sloty bez ani jednego miejsca; tutaj domykamy przypadek
	// „jedno wolne miejsce, a potrzebne dwa", który zależy od stanu checkboxa.
	function applySlotSeats( form ) {
		var checkbox = form.querySelector( 'input[name="evreg_companion"]' );
		var needed = checkbox && checkbox.checked ? 2 : 1;
		form.querySelectorAll( '[data-evreg-seats-left]' ).forEach( function ( el ) {
			var left = parseInt( el.getAttribute( 'data-evreg-seats-left' ), 10 );
			var full = ! isNaN( left ) && left < needed;
			el.disabled = full;
			if ( full && el.checked ) {
				el.checked = false;
				// Pusta grupa radio nie wysyła klucza [slot], co serwer odczytałby jako
				// „Bez noclegu" bez wiedzy zgłaszającego się — przenosimy zaznaczenie jawnie.
				var none = form.querySelector( 'input[name="' + el.name + '"][value=""]' );
				if ( none ) { none.checked = true; }
			}
			var wrap = el.closest( '.evreg-choice' );
			if ( wrap ) { wrap.classList.toggle( 'is-full', full ); }
		} );
	}

	// Programowe el.checked = false w applySlotSeats NIE emituje zdarzenia `change`,
	// więc widoczność pola współlokatora trzeba przeliczyć ręcznie — inaczej zostaje
	// widoczna po odebraniu zaznaczenia. Kontenery .evreg-accommodation poza
	// formularzem (ekran edycji w adminie) obsługuje druga pętla.
	function refreshAccommodation( form ) {
		form.querySelectorAll( '.evreg-accommodation' ).forEach( applyRoommate );
	}

	document.querySelectorAll( '.evreg-form' ).forEach( function ( form ) {
		apply( form );
		applyCompanion( form );
		applySlotSeats( form );
		refreshAccommodation( form );
		form.addEventListener( 'change', function () {
			apply( form );
			applyCompanion( form );
			applySlotSeats( form );
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

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
		syncRequired( form );
	}

	// `required` musi chodzić W PARZE z widocznością: kontrolek w schowanym kontenerze
	// przeglądarka nie potrafi sfokusować, więc ukryte-i-wymagane pole przerywa submit
	// bez komunikatu. Serwer i tak nie waliduje pól schowanych warunkiem (Validator +
	// VisibilityResolver). Oryginalny stan zapamiętujemy jawnie w data-evreg-required
	// (nie wnioskujemy go) i przywracamy po pokazaniu. Stan liczymy per kontrolka po
	// WSZYSTKICH przodkach warunkowych (closest), więc zagnieżdżone kontenery nie
	// przywracają `required` kontrolce, którą nadal chowa inny kontener.
	function syncRequired( form ) {
		form.querySelectorAll( '[data-evreg-when-field] input, [data-evreg-when-field] select, [data-evreg-when-field] textarea' ).forEach( function ( control ) {
			// `required` imienia towarzyszącej ma jednego właściciela: applyCompanion.
			if ( control.hasAttribute( 'data-evreg-companion-input' ) ) { return; }
			if ( control.closest( '[data-evreg-when-field][hidden]' ) ) {
				if ( control.required ) {
					control.setAttribute( 'data-evreg-required', '1' );
					control.required = false;
				}
			} else if ( control.hasAttribute( 'data-evreg-required' ) ) {
				control.required = true;
				control.removeAttribute( 'data-evreg-required' );
			}
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

	// Pole imienia towarzyszącej jest wymagane, ale tylko gdy checkbox zaznaczony.
	// `required` musi chodzić W PARZE z widocznością: ukrytej kontrolki przeglądarka
	// nie potrafi sfokusować, więc ukryte-i-wymagane pole blokuje submit bez
	// komunikatu. Lustro warunku w FormRenderer::renderCompanion (render serwerowy).
	function applyCompanion( form ) {
		var checkbox = form.querySelector( 'input[name="evreg_companion"]' );
		var input = form.querySelector( '[data-evreg-companion-input]' );
		if ( ! checkbox || ! input ) { return; }
		var on = checkbox.checked;
		input.hidden = ! on;
		// Blok towarzyszącej leży wewnątrz fieldsetu sekcji, więc sekcja schowana warunkiem
		// chowa też to pole — wtedy nie może być wymagane (syncRequired go nie dotyka).
		input.required = on && ! input.closest( '[data-evreg-when-field][hidden]' );
		var label = form.querySelector( '[data-evreg-companion-label]' );
		if ( label ) { label.hidden = ! on; }
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

	// Lustro EvReg\Domain\Capacity\NightCapacity (tryb „doby”): doba jest zamknięta, gdy
	// brakuje miejsc dla potrzebnej liczby osób albo gdy para nie mieści się w limicie par.
	// Dotyczy WYŁĄCZNIE checkboxów z data-evreg-night-seats-left; lustro trybu klasycznego
	// (seatsFor, applySlotState) nie dotyka tych elementów i odwrotnie.
	function applyNightState( form, event ) {
		var checkbox = form.querySelector( 'input[name="evreg_companion"]' );
		var companion = !! ( checkbox && checkbox.checked );
		var needed = companion ? 2 : 1;
		var dropped = false;

		form.querySelectorAll( 'input[data-evreg-night-seats-left]' ).forEach( function ( el ) {
			var left = parseInt( el.getAttribute( 'data-evreg-night-seats-left' ), 10 );
			var pairs = el.getAttribute( 'data-evreg-night-pairs-left' );
			var noSeats = ! isNaN( left ) && left < 1;
			var noPair = ! noSeats && ( ( ! isNaN( left ) && left < needed )
				|| ( companion && pairs !== null && pairs !== '' && parseInt( pairs, 10 ) < 1 ) );
			var full = noSeats || noPair;

			el.disabled = full;
			// `disabled` nie wysyła wartości — zaznaczenie zdejmujemy jawnie i ogłaszamy,
			// żeby nikt nie wysłał formularza w przekonaniu, że nadal trzyma tę dobę.
			if ( full && el.checked ) {
				el.checked = false;
				dropped = true;
			}

			var wrap = el.closest( '.evreg-choice' );
			if ( wrap ) {
				wrap.classList.toggle( 'is-full', full );
				var seatsNote = wrap.querySelector( '[data-evreg-night-note="full"]' );
				if ( seatsNote ) { seatsNote.hidden = ! noSeats; }
				var pairNote = wrap.querySelector( '[data-evreg-night-note="pair"]' );
				if ( pairNote ) { pairNote.hidden = ! noPair; }
			}
		} );

		var droppedNote = form.querySelector( '.evreg-nights-dropped' );
		if ( ! droppedNote ) { return; }
		if ( dropped ) {
			droppedNote.hidden = false;
		} else if ( event && event.target && event.target.matches
			&& event.target.matches( 'input[data-evreg-night-seats-left]' ) && event.target.checked ) {
			// Użytkownik świadomie zaznaczył dostępną dobę — stara notka przestaje być prawdą.
			// Przy starcie (bez zdarzenia) nie chowamy notki wyrenderowanej przez serwer po błędzie POST.
			droppedNote.hidden = true;
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
		applyNightState( form );
		refreshAccommodation( form );
		form.addEventListener( 'change', function ( e ) {
			apply( form );
			applyCompanion( form );
			applySlotState( form, true );
			applyNightState( form, e );
			refreshAccommodation( form );
		} );
		form.addEventListener( 'input', function () { apply( form ); applyCompanion( form ); } );
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

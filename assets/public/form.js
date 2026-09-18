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

	document.querySelectorAll( '.evreg-form' ).forEach( function ( form ) {
		apply( form );
		form.addEventListener( 'change', function () { apply( form ); } );
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

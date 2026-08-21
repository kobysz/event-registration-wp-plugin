( function () {
	function currentType( form ) {
		var checked = form.querySelector( 'input[name="__type"]:checked' );
		return checked ? checked.value : '';
	}

	function matches( type, operator, rawValue ) {
		var value;
		try { value = JSON.parse( rawValue ); } catch ( e ) { value = rawValue; }
		var list = Array.isArray( value ) ? value.map( String ) : [ String( value ) ];
		switch ( operator ) {
			case 'in': return list.indexOf( type ) !== -1;
			case 'not_in': return list.indexOf( type ) === -1;
			case 'equals': return String( value ) === type;
			case 'not_equals': return String( value ) !== type;
			case 'empty': return type === '';
			case 'not_empty': return type !== '';
			default: return true;
		}
	}

	function applyConditional( form ) {
		var type = currentType( form );
		form.querySelectorAll( '[data-evreg-when-field]' ).forEach( function ( section ) {
			if ( section.getAttribute( 'data-evreg-when-field' ) !== '__type' ) { return; }
			var visible = matches(
				type,
				section.getAttribute( 'data-evreg-when-operator' ),
				section.getAttribute( 'data-evreg-when-value' )
			);
			section.hidden = ! visible;
		} );
	}

	// Pole współlokatora jest per pokój: widoczne tylko gdy wybrany slot należy do
	// pokoju z włączoną flagą (kontrolka slotu — radio lub option — ma
	// data-evreg-roommate="1"). Działa w formularzu publicznym (radio) i w edycji
	// admina (select), niezależnie od klasy formularza.
	function selectedSlotAllowsRoommate( container ) {
		var radio = container.querySelector( 'input[type="radio"][name$="[slot]"]:checked' );
		if ( radio ) {
			return radio.getAttribute( 'data-evreg-roommate' ) === '1';
		}
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
		applyConditional( form );
		form.addEventListener( 'change', function ( e ) {
			if ( e.target && e.target.name === '__type' ) { applyConditional( form ); }
		} );
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

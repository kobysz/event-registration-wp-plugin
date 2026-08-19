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

	function apply( form ) {
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

	document.querySelectorAll( '.evreg-form' ).forEach( function ( form ) {
		apply( form );
		form.addEventListener( 'change', function ( e ) {
			if ( e.target && e.target.name === '__type' ) { apply( form ); }
		} );
	} );
}() );

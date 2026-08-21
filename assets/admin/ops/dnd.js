const SECTION_PREFIX = 'evreg-section:';

export function sectionDroppableId( sectionKey ) {
	return SECTION_PREFIX + sectionKey;
}

export function isSectionId( id ) {
	return typeof id === 'string' && id.startsWith( SECTION_PREFIX );
}

export function sectionKeyFromId( id ) {
	return isSectionId( id ) ? id.slice( SECTION_PREFIX.length ) : null;
}

/**
 * Tłumaczy zdarzenie @dnd-kit (active/over) na cel dla moveFieldTo.
 *
 * @param {Object} schema   Aktualna schema.
 * @param {string} activeId Klucz przeciąganego pola.
 * @param {?string} overId  Id elementu pod kursorem (klucz pola albo id sekcji).
 * @return {?{toSectionKey: string, toIndex: number}} Cel lub null gdy brak ruchu.
 */
export function resolveDrop( schema, activeId, overId ) {
	if ( ! overId || overId === activeId ) {
		return null;
	}

	const sections = schema.sections || [];

	if ( isSectionId( overId ) ) {
		const key = sectionKeyFromId( overId );
		const section = sections.find( ( s ) => s.key === key );
		if ( ! section ) {
			return null;
		}
		return { toSectionKey: key, toIndex: ( section.fields || [] ).length };
	}

	for ( const section of sections ) {
		const index = ( section.fields || [] ).findIndex( ( f ) => f.key === overId );
		if ( index !== -1 ) {
			return { toSectionKey: section.key, toIndex: index };
		}
	}

	return null;
}

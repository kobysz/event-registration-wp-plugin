/**
 * Pure state rules for the event config admin.
 *
 * The admin screen loads the event config, the mail templates and the
 * translation overlay with three independent requests and keeps them in one
 * state object. Every load therefore has to merge, never replace: a response
 * that lands first must survive the ones that land after it. A replacing
 * setState dropped the overlay from the state while its "loaded" flag stayed
 * on, and the next save then wrote an empty overlay over the stored one.
 *
 * The save side is gated on the data itself plus an explicit touch, so a part
 * that was never loaded or never edited is not written back at all.
 */

export const CONFIG_KEYS = [ 'schema', 'types', 'accommodation', 'settings' ];

export const EMPTY_CONFIG = { schema: {}, types: [], accommodation: {}, settings: {} };

/**
 * Merges one loaded part into the admin state without dropping the others.
 *
 * @param {Object} prev Current state.
 * @param {Object} part Keys carried by the response that just landed.
 * @return {Object} New state.
 */
export function mergeLoadedConfig( prev, part ) {
	return { ...prev, ...part };
}

/**
 * Narrows the state to the keys the config endpoint owns.
 *
 * Keeps the overlay and the mail templates out of the config PUT: they have
 * their own endpoints, and a config save must never carry a second copy of
 * them.
 *
 * @param {Object} config Admin state.
 * @return {Object} Payload for the config endpoint.
 */
export function configPayload( config ) {
	const payload = {};
	CONFIG_KEYS.forEach( ( key ) => {
		if ( undefined !== config[ key ] ) {
			payload[ key ] = config[ key ];
		}
	} );
	return payload;
}

/**
 * Decides which full-replace endpoints this save may call.
 *
 * Both are gated twice: the part has to be present in the state (so it was
 * loaded) and it has to be marked as touched (so the user actually edited it).
 *
 * @param {Object} config Admin state.
 * @param {Object} dirty  Map of touched state keys.
 * @return {{templates: boolean, i18n: boolean}} Save targets.
 */
export function saveTargets( config, dirty ) {
	const touched = dirty || {};
	return {
		templates: undefined !== config.mailTemplates && true === !! touched.mailTemplates,
		i18n: undefined !== config.i18n && true === !! touched.i18n,
	};
}

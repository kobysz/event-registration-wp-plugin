// Lista kluczy komunikatów o pełnym evencie żyje tu, ale ich TREŚĆ nie: domyślne
// teksty podaje serwer w window.evregAdmin.noticeDefaults, bo tę samą treść renderuje
// publiczny formularz (Shortcode::fullNotice → Frontend\DefaultNotices). Kopia tekstów
// w tym pliku była drugim domem i cichym ryzykiem rozjazdu — backlog B41.
export const NOTICE_KEYS = [ 'full_waitlist', 'full_closed' ];

export function defaultNotice( key ) {
	const defaults = ( typeof window !== 'undefined' && window.evregAdmin && window.evregAdmin.noticeDefaults ) || {};
	return Object.prototype.hasOwnProperty.call( defaults, key ) ? String( defaults[ key ] ) : '';
}

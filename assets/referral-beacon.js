/**
 * Agentimus — AI-referral beacon (opt-in "CDN mode").
 *
 * Counts a real visitor who arrived from an AI assistant, from the BROWSER, so the
 * count survives a full-page CDN/edge cache that would otherwise answer the request
 * without WordPress ever seeing it. It is the client-side twin of the server-side
 * recorder; only ONE of them runs (the owner opts into this mode), because the store
 * keeps no per-visit id and the two could not be deduped.
 *
 * Privacy: first-party only. It posts the navigation's referrer, utm_source and path
 * to this same site; the SERVER decides if that's a known AI source and stores just an
 * aggregate day count — no IP, no User-Agent, no query string. Nothing goes to a third
 * party. It fires at most once per page view, and only for an EXTERNAL arrival, so it
 * stays silent on ordinary internal navigation.
 */
( function () {
	var cfg = window.AgentimusReferral;
	if ( ! cfg || ! cfg.endpoint || ! navigator.sendBeacon ) {
		return;
	}

	try {
		var ref = document.referrer || '';
		var utm = new URLSearchParams( window.location.search ).get( 'utm_source' ) || '';

		// Only external arrivals: an internal navigation (referrer on our own origin, no
		// utm) is never an AI referral, so we don't beacon on ordinary page views.
		var external = ref && ref.indexOf( window.location.origin ) !== 0;
		if ( ! external && ! utm ) {
			return;
		}

		var payload = JSON.stringify( { ref: ref, utm: utm, path: window.location.pathname } );
		navigator.sendBeacon( cfg.endpoint, new Blob( [ payload ], { type: 'application/json' } ) );
	} catch ( e ) {
		// Never disrupt the page over an analytics beacon.
	}
} )();

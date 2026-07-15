/**
 * Agentimus — WebMCP bridge (experimental, opt-in).
 *
 * Registers the site's read-only tools with an in-browser AI agent via the
 * WebMCP browser API (document.modelContext). Inert in any browser without
 * the API — which is nearly all of them today — so it adds no behaviour for
 * human visitors. Enqueued only when the owner opts in and tools exist.
 *
 * Tool manifest is provided by PHP as window.AgentimusWebMCP.tools, each:
 *   { name, description, inputSchema, endpoint, method }
 */
( function () {
	'use strict';

	// The API surface moved while the spec is incubating: Chrome first shipped it
	// as navigator.modelContext, then moved to document.modelContext and left the
	// navigator name as a DEPRECATED alias (reading it logs a console warning on
	// every page view, and it will eventually be removed). Prefer the current
	// home; keep the old one only as a fallback for the earliest preview builds.
	var mc = ( typeof document !== 'undefined' && document.modelContext )
		? document.modelContext
		: ( ( typeof navigator !== 'undefined' ) ? navigator.modelContext : null );
	if ( ! mc || typeof mc.registerTool !== 'function' ) {
		return; // No WebMCP in this browser — do nothing at all.
	}

	var data = window.AgentimusWebMCP || {};
	var tools = Array.isArray( data.tools ) ? data.tools : [];

	tools.forEach( function ( tool ) {
		if ( ! tool || ! tool.name || ! tool.endpoint ) {
			return;
		}
		try {
			mc.registerTool( {
				name: tool.name,
				description: tool.description || '',
				inputSchema: tool.inputSchema || { type: 'object', properties: {} },
				execute: function ( args ) {
					var method = ( tool.method || 'GET' ).toUpperCase();
					var url = tool.endpoint;
					var params = ( args && typeof args === 'object' ) ? args : {};
					var init = { method: method, headers: { Accept: 'application/json' } };

					if ( method === 'GET' || method === 'HEAD' ) {
						var qs = Object.keys( params ).map( function ( k ) {
							return encodeURIComponent( k ) + '=' + encodeURIComponent( params[ k ] );
						} ).join( '&' );
						if ( qs ) {
							url += ( url.indexOf( '?' ) === -1 ? '?' : '&' ) + qs;
						}
					} else {
						init.headers[ 'Content-Type' ] = 'application/json';
						init.body = JSON.stringify( params );
					}

					return fetch( url, init ).then( function ( res ) {
						return res.text();
					} ).then( function ( text ) {
						return { content: [ { type: 'text', text: text } ] };
					} );
				}
			} );
		} catch ( e ) {
			// A spec change or a malformed tool entry must never break the page.
			if ( window.console && typeof console.debug === 'function' ) {
				console.debug( '[Agentimus WebMCP] skipped tool', tool.name, e );
			}
		}
	} );
}() );

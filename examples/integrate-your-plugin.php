<?php
/**
 * Agentify — example integration for plugin authors.
 *
 * Drop the snippet below into your own plugin to make it discoverable. There is
 * NO dependency and NO library to load: if no WP_Discovery engine (such as
 * Agentify) is active, the `wp_discovery_register` action simply never fires, so
 * the code is inert.
 *
 * Your plugin is then aggregated into the site's /.well-known/discovery.json
 * (and agent-card.json / mcp.json), so an AI agent learns what your plugin does
 * and how to reach it — without ever knowing your plugin exists.
 *
 * This file is documentation only; it is not loaded by Agentify.
 *
 * @package Agentify\Examples
 */

defined( 'ABSPATH' ) || exit;

/* -------------------------------------------------------------------------- *
 *  1. Minimal — the entire integration.
 * -------------------------------------------------------------------------- */

add_action(
	'wp_discovery_register',
	function ( $registry ) {
		$registry->register(
			array(
				'id'    => 'acme-bookings', // unique lowercase slug
				'title' => 'Acme Bookings',
				'type'  => 'scheduling',    // controlled vocabulary (see #3)
			)
		);
	}
);

/* -------------------------------------------------------------------------- *
 *  2. Realistic — capabilities (intent), the API, auth and an agent card.
 *     Capabilities describe WHAT you can do; the concrete paths live only in
 *     `endpoints` / `tools`.
 * -------------------------------------------------------------------------- */

add_action(
	'wp_discovery_register',
	function ( $registry ) {
		$registry->register(
			array(
				'id'           => 'acme-bookings',
				'title'        => 'Acme Bookings',
				'type'         => 'scheduling',
				'description'  => 'Appointment booking, availability and calendars.',
				'version'      => defined( 'ACME_VERSION' ) ? ACME_VERSION : '',

				// Dot-notation INTENT — folded into the site-wide capability union.
				'capabilities' => array(
					'scheduling.availability.read',
					'scheduling.booking.create',
					'scheduling.booking.cancel',
				),

				// WHERE. type: rest | graphql | mcp | openapi | a2a | soap | rpc
				// Site-relative URLs are fine; they are absolutized on output.
				'endpoints'    => array(
					array(
						'url'         => '/wp-json/acme/v1',
						'type'        => 'rest',
						'methods'     => array( 'GET', 'POST' ),
						'auth'        => 'apikey',
						'description' => 'Public booking API.',
					),
				),

				// Optional machine schema for the endpoint(s).
				'schemas'      => array( '/wp-json/acme/v1/openapi.json' ),

				// HOW to authenticate. type: none | apikey | basic | oauth2 | oidc | custom
				'auth'         => array(
					'type'   => 'apikey',
					'docs'   => 'https://acme.dev/api/auth',
					'scopes' => array( 'bookings:write' ),
				),

				// Optional A2A agent card → surfaces in /.well-known/agent-card.json.
				'agent'        => array(
					'name'        => 'Acme Booking Agent',
					'description' => 'Check availability and book appointments.',
					'endpoint'    => '/wp-json/acme/v1/agent',
					'auth'        => 'apikey',
					'skills'      => array(
						array( 'id' => 'check_availability', 'description' => 'List open slots.' ),
						array( 'id' => 'create_booking', 'description' => 'Book an appointment.' ),
					),
				),

				'docs'         => 'https://acme.dev/docs',
			)
		);
	}
);

/* -------------------------------------------------------------------------- *
 *  3. Field reference
 *
 *  id            (required) unique slug ^[a-z0-9](-?[a-z0-9]+)*$
 *  title         (required) human label
 *  type          (required) one of: content, commerce, scheduling, courses,
 *                forms, crm, auth, search, media, messaging, analytics,
 *                payments, directory, agent — or an "x-vendor-name" extension
 *  description   string
 *  version       string
 *  capabilities  string[]  dot-notation intent verbs
 *  endpoints     array of { url, type, methods[], auth, description }
 *  schemas       string[]  URLs to OpenAPI / JSON-Schema / GraphQL SDL
 *  auth          { type, oidc, scopes[], docs }
 *  agent         { name, description, skills[{id,description}], endpoint, auth }
 *  abilities     string[]  WP Abilities API names this resource fulfils
 *  tools         array of MCP tool definitions (see #5)
 *  docs          string (URL)
 *  provider      AUTO — filled by the collector; do NOT set it
 *
 *  A bad id/type is rejected and shown (with the reason) in the admin
 *  Discovery Hub → Validation panel. Unknown keys are dropped with a warning.
 * -------------------------------------------------------------------------- */

/* -------------------------------------------------------------------------- *
 *  4. Facade alternative — a direct-call convenience (guard it, since the call
 *     is direct). The `wp_discovery_register` hook above is the vendor-neutral
 *     path; this facade class is implementation-specific (Agentify ships
 *     `Agentify_Discovery`).
 * -------------------------------------------------------------------------- */

if ( class_exists( 'Agentify_Discovery' ) ) {
	Agentify_Discovery::register(
		array(
			'id'    => 'acme-bookings',
			'title' => 'Acme Bookings',
			'type'  => 'scheduling',
		)
	);
}

/* -------------------------------------------------------------------------- *
 *  5. Advanced — serve a well-known document, or expose MCP tools.
 * -------------------------------------------------------------------------- */

add_action(
	'wp_discovery_register',
	function ( $registry ) {

		// Serve a document under /.well-known/ (callback | redirect | file).
		$registry->add_well_known(
			array(
				'name'         => 'security.txt', // → /.well-known/security.txt
				'content_type' => 'text/plain',
				'callback'     => function () {
					return "Contact: mailto:security@acme.dev\n";
				},
			)
		);

		// MCP-shaped tools — flatten into the site's tools[] and /.well-known/mcp.json.
		$registry->register(
			array(
				'id'    => 'acme-tools',
				'title' => 'Acme Tools',
				'type'  => 'agent',
				'tools' => array(
					array(
						'name'        => 'acme/check-availability',
						'title'       => 'Check availability',
						'description' => 'Return open slots for a service.',
						'inputSchema' => array(
							'type'       => 'object',
							'properties' => array(
								'service_id' => array( 'type' => 'integer' ),
							),
						),
						'annotations' => array( 'readOnlyHint' => true ),
						'auth'        => 'apikey',
					),
				),
			)
		);
	}
);

<?php
/**
 * AuthMd — the /auth.md document: how an agent or API client authenticates to
 * this site, written in plain markdown at a predictable root path (the auth
 * companion to /llms.txt). Everything is generated from the LIVE configuration
 * — the OAuth server's own RFC 8414/9728 metadata and the feature flags — so
 * the document can never promise an endpoint or a flow the site doesn't run.
 *
 * Scanners looking for OAuth support read docs and /.well-known/openid-configuration
 * (which a plain OAuth 2.0 server rightly doesn't serve); this file is where they
 * — and humans — learn the actual flow without spelunking WWW-Authenticate headers.
 *
 * @package Agentimus
 */

namespace Agentimus;

defined( 'ABSPATH' ) || exit;

final class AuthMd {

	/** @var Settings */
	private $settings;

	/**
	 * @param Settings $settings Feature flags + identity.
	 */
	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * The auth.md document.
	 *
	 * @return string Markdown.
	 */
	public function markdown() {
		$name = (string) $this->settings->identity( 'name', get_bloginfo( 'name' ) );
		$name = '' !== $name ? $name : (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST );

		$out  = '# Authentication — ' . $name . "\n\n";
		$out .= 'How agents and API clients authenticate to ' . home_url( '/' ) . ".\n\n";

		$out .= "## Public content — no credentials\n\n";
		$out .= 'Published content is open: the REST read API (' . esc_url_raw( rest_url() ) . '), '
			. 'the markdown editions, llms.txt and every /.well-known discovery document '
			. "answer without authentication.\n\n";
		// Say the registration state outright — agents probe /register, /signup and
		// /account before finding the answer at wp-login.php; one sentence here ends
		// the guessing. Read live, so a membership site tells the truth too.
		if ( get_option( 'users_can_register' ) ) {
			$register = function_exists( 'wp_registration_url' ) ? wp_registration_url() : home_url( '/wp-login.php?action=register' );
			$out     .= 'Public user registration is open at ' . esc_url_raw( $register ) . ".\n";
		} else {
			$out .= "There are no public user accounts — registration is disabled. The OAuth flow below authorizes an agent against the site owner's account, not a new one.\n";
		}

		if ( $this->settings->enabled( 'enable_mcp_server' ) ) {
			// Every URL below comes from the OAuth server's own metadata — the same
			// array served at the RFC 8414 well-known — so this file cannot drift
			// from what the server actually implements. Sections follow the auth.md
			// walkthrough shape (workos.com/auth-md): Discover → Pick a method →
			// Register → Claim → Use the credential → Errors → Revocation.
			$meta = Oauth\Server::authorization_server_metadata();

			$out .= "\n## MCP server — OAuth 2.0\n\n";
			$out .= 'The MCP endpoint at `' . esc_url_raw( Oauth\Server::resource() ) . '` is an OAuth 2.0 '
				. "protected resource (RFC 9728).\n";

			$out .= "\n### Discover\n\n";
			$out .= '- Protected-resource metadata (RFC 9728): ' . esc_url_raw( home_url( '/.well-known/oauth-protected-resource/agentimus/mcp' ) );
			$derived = Discovery\WellKnown::mcp_resource_prm_route();
			if ( '' !== $derived ) {
				$out .= ', also at ' . esc_url_raw( home_url( '/.well-known/' . $derived ) );
			}
			$out .= ' and at the flat ' . esc_url_raw( home_url( '/.well-known/oauth-protected-resource' ) ) . ".\n";
			$out .= '- Authorization-server metadata (RFC 8414): ' . esc_url_raw( home_url( '/.well-known/oauth-authorization-server/agentimus/mcp' ) )
				. " — carries the `agent_auth` block (with `register_uri`) pointing back at this file.\n";
			$out .= '- An unauthenticated request to the MCP endpoint answers 401 with a `WWW-Authenticate` header naming the '
				. "protected-resource metadata — a compliant client can discover this whole flow from that alone.\n";

			$out .= "\n### Pick a method\n\n";
			$out .= 'One method: `anonymous`. This server accepts no identity assertion (no ID-JAG) and runs no claim-code '
				. "ceremony — an agent registers a client anonymously, and the site owner personally approves the grant in the browser.\n";
			$out .= 'Scopes: ' . implode( ', ', $meta['scopes_supported'] )
				. ( $this->settings->enabled( 'enable_agent_writes' )
					? ' — write tools are on; every write still runs as the user who approved the connection.'
					: ' — the write scope grants nothing until the owner switches agent writes on.' ) . "\n";

			$out .= "\n### Register\n\n";
			$out .= 'Dynamic client registration (RFC 7591): POST a JSON body with `redirect_uris` to '
				. esc_url_raw( $meta['registration_endpoint'] ) . ". Public clients — no client secret is issued; PKCE is the proof of possession.\n";
			$out .= "POST only: a GET of the registration endpoint answers 404 (`rest_no_route`) — that is WordPress routing, not a missing endpoint.\n";

			$out .= "\n### Claim\n\n";
			$out .= 'Send the user to the authorization endpoint ' . esc_url_raw( $meta['authorization_endpoint'] )
				. ' with PKCE (' . implode( '/', $meta['code_challenge_methods_supported'] ) . ' only). Expect a WordPress '
				. "login first — the site owner's own browser session is what grants consent. The owner approves the scope, "
				. "and the authorization code returns to your `redirect_uri`.\n";

			$out .= "\n### Use the credential\n\n";
			$out .= 'Exchange the code at ' . esc_url_raw( $meta['token_endpoint'] ) . ' (grants: '
				. implode( ', ', $meta['grant_types_supported'] ) . ").\n";
			$out .= 'Send the access token as `Authorization: Bearer …` (header only). Access tokens live about '
				. max( 1, (int) round( Oauth\Server::ACCESS_TTL / HOUR_IN_SECONDS ) ) . ' hour(s); refresh tokens about '
				. max( 1, (int) round( Oauth\Server::REFRESH_TTL / DAY_IN_SECONDS ) ) . " days.\n";
			$out .= "A WordPress application password (HTTP Basic) also works, for clients without OAuth support.\n";

			$out .= "\n### Errors\n\n";
			$out .= "The token endpoint speaks RFC 6749 vocabulary (`invalid_request`, `invalid_grant`, `invalid_client`). "
				. 'Every REST error body is WordPress\'s typed shape: `{"code", "message", "data": {"status"}}`.' . "\n";

			$out .= "\n### Revocation\n\n";
			$out .= 'There is no RFC 7009 revocation endpoint. Access tokens expire on their own; the site owner can '
				. 'disconnect any client from the admin at any time, which invalidates its grants — a revoked client\'s '
				. "refresh attempt fails with `invalid_grant`; re-register and re-claim.\n";

			$out .= "\n## Not OpenID Connect\n\n";
			$out .= 'This server issues access tokens for its own MCP endpoint only; it is not an identity '
				. "provider, so there is no /.well-known/openid-configuration.\n";
		} else {
			$out .= "\n## Delegated access\n\n";
			$out .= 'No authenticated agent endpoint is currently enabled on this site. Reading is public; '
				. "changing anything stays with the site owner.\n";
			$external = trim( (string) $this->settings->get( 'oauth_auth_server', '' ) );
			if ( '' !== $external ) {
				$out .= "\nAn external authorization server protects this site's authenticated APIs; its "
					. 'protected-resource metadata (RFC 9728) is at '
					. esc_url_raw( home_url( '/.well-known/oauth-protected-resource' ) ) . ".\n";
			}
		}

		return rtrim( $out ) . "\n";
	}
}

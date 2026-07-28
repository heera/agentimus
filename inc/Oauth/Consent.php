<?php
/**
 * The consent page at /agentimus/connect — the one screen every OAuth connect
 * passes through. Server-rendered OUTSIDE the REST API (plain cookie auth
 * works here after the wp-login round-trip), styled to match the admin, and
 * worded per the approved prototype: the client's name is presented as its
 * claim, the return address as the checkable fact, and the SCOPE IS THE
 * OWNER'S CHOICE — a write request can be granted read-only, and a site
 * whose write wall is off can only grant read (with the amber note saying
 * why). Deny and every validation failure render here; we never redirect an
 * error to a URI that failed validation.
 *
 * @package Agentimus
 */

namespace Agentimus\Oauth;

use Agentimus\Settings;

defined( 'ABSPATH' ) || exit;

final class Consent {

	const QUERY_VAR = 'agentimus_connect';

	/** @var Settings */
	private $settings;

	/**
	 * @param Settings $settings Settings store.
	 */
	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Hook the rewrite and the front controller.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'init', array( __CLASS__, 'add_rules' ) );
		add_action( 'template_redirect', array( $this, 'route' ), 0 );
	}

	/**
	 * The /agentimus/connect rewrite. Registered before priority 20, so the
	 * self-healing flush (whose signature includes the plugin version) carries
	 * it in with the same flush that adds the OAuth well-known routes.
	 *
	 * @return void
	 */
	public static function add_rules() {
		add_rewrite_rule( '^agentimus/connect/?$', 'index.php?' . self::QUERY_VAR . '=1', 'top' );
		add_rewrite_tag( '%' . self::QUERY_VAR . '%', '1' );
	}

	/**
	 * Front controller. Login is required (browser round-trips through
	 * wp-login and comes back), consent requires manage_options — the same
	 * bar as creating a connection token.
	 *
	 * @return void
	 */
	public function route() {
		if ( '1' !== (string) get_query_var( self::QUERY_VAR ) ) {
			return;
		}
		if ( ! $this->settings->enabled( 'enable_mcp_server' ) ) {
			$this->not_found(); // No MCP server, no connects — a clean 404, not a hint.
			return;
		}
		if ( ! is_user_logged_in() ) {
			auth_redirect();
			exit;
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die(
				esc_html__( 'Only an administrator can connect an assistant to this site.', 'agentimus' ),
				403
			);
		}

		nocache_headers();

		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : 'GET';
		if ( 'POST' === $method ) {
			$this->handle_decision();
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- OAuth authorize params come from the client, by spec; nothing changes state on GET.
		$req = Server::validate_authorize_request( wp_unslash( $_GET ) );
		if ( is_wp_error( $req ) ) {
			$this->render_error( $req );
			return;
		}
		$this->render( $req );
	}

	/**
	 * POST: the owner decided. The original authorize params ride hidden
	 * fields and are RE-validated — the form is a courier, never an authority.
	 *
	 * @return void
	 */
	private function handle_decision() {
		check_admin_referer( 'agentimus_oauth_consent' );

		$req = Server::validate_authorize_request( wp_unslash( $_POST ) );
		if ( is_wp_error( $req ) ) {
			$this->render_error( $req );
			return;
		}

		$approved = isset( $_POST['decision'] ) && 'approve' === sanitize_text_field( wp_unslash( $_POST['decision'] ) );
		if ( ! $approved ) {
			$this->redirect_back( $req, array( 'error' => 'access_denied' ) );
			return;
		}

		// The owner's choice (the `grant` radios — deliberately NOT named `scope`,
		// which is the hidden courier of the client's original request), capped by
		// the walls: a forged value can never out-rank max_grantable().
		$max    = Server::max_grantable( $req['wants_write'], $this->settings->enabled( 'enable_agent_writes' ) );
		$wanted = isset( $_POST['grant'] ) ? sanitize_text_field( wp_unslash( $_POST['grant'] ) ) : 'read';
		$scope  = ( 'write' === $wanted && 'write' === $max ) ? 'write' : 'read';

		$code = Server::issue_code( $req, $scope, get_current_user_id() );
		$this->redirect_back( $req, array( 'code' => $code ) );
	}

	/**
	 * Send the browser back to the client's VALIDATED redirect URI. Deliberate
	 * wp_redirect: the target is external by design and was checked against
	 * the client's registration — wp_safe_redirect would refuse it.
	 *
	 * @param array $req    Validated authorize request.
	 * @param array $params code or error.
	 * @return void
	 */
	private function redirect_back( array $req, array $params ) {
		if ( '' !== $req['state'] ) {
			$params['state'] = $req['state'];
		}
		$url = add_query_arg( array_map( 'rawurlencode', $params ), $req['redirect_uri'] );
		wp_redirect( $url ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- validated against the registered redirect_uris.
		exit;
	}

	/**
	 * Render the consent screen.
	 *
	 * @param array $req Validated authorize request.
	 * @return void
	 */
	private function render( array $req ) {
		status_header( 200 );
		header( 'Content-Type: text/html; charset=UTF-8' );
		echo $this->html( $req ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built with escaping below.
		exit;
	}

	/**
	 * Render a validation failure as a page. Plain words, no redirect — a
	 * request that failed validation has no address we trust to send it to.
	 *
	 * @param \WP_Error $error The validation error.
	 * @return void
	 */
	private function render_error( $error ) {
		status_header( 400 );
		header( 'Content-Type: text/html; charset=UTF-8' );
		$body = '<h1>' . esc_html__( 'This connection request is not valid.', 'agentimus' ) . '</h1>'
			. '<p class="claims">' . esc_html( $error->get_error_message() )
			. ' ' . esc_html__( 'Ask the assistant to start the connection again.', 'agentimus' ) . '</p>';
		echo $this->page( $body ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above.
		exit;
	}

	/**
	 * The consent form, per the approved prototype.
	 *
	 * @param array $req Validated authorize request.
	 * @return string
	 */
	public function html( array $req ) {
		$name  = $req['client_name'];
		$host  = wp_parse_url( $req['redirect_uri'], PHP_URL_HOST );
		$host  = is_string( $host ) ? $host : '';
		$walls = $this->settings->enabled( 'enable_agent_writes' );
		$max   = Server::max_grantable( $req['wants_write'], $walls );
		$down  = $req['wants_write'] && 'write' !== $max; // Asked write, walls said read.
		$user  = wp_get_current_user();

		$b  = '<h1>' . sprintf( esc_html__( '“%s” asks to connect to your site', 'agentimus' ), esc_html( $name ) ) . '</h1>';
		$b .= '<p class="claims">' . sprintf(
			/* translators: 1: client-claimed name, 2: redirect host. */
			esc_html__( 'It calls itself %1$s and will return to %2$s after you decide. If that is not where you started, close this tab.', 'agentimus' ),
			'<em>' . esc_html( $name ) . '</em>',
			'<em>' . esc_html( $host ) . '</em>'
		) . '</p>';

		$b .= '<form method="post" action="">';
		$b .= wp_nonce_field( 'agentimus_oauth_consent', '_wpnonce', true, false );
		foreach ( array( 'client_id', 'redirect_uri', 'response_type', 'code_challenge', 'code_challenge_method', 'scope', 'state' ) as $key ) {
			// The original params ride along and are re-validated on POST.
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- render-only echo of the authorize request.
			$val = isset( $_GET[ $key ] ) ? sanitize_text_field( wp_unslash( $_GET[ $key ] ) ) : '';
			$b  .= '<input type="hidden" name="' . esc_attr( $key ) . '" value="' . esc_attr( $val ) . '">';
		}

		$b .= '<div class="grantbox">';
		$b .= '<label class="grant"><input type="radio" name="grant" value="read"' . ( 'write' === $max ? '' : ' checked' ) . '>'
			. '<span><strong>' . esc_html__( 'Read only', 'agentimus' ) . '</strong>'
			. '<small>' . esc_html__( 'Look up your readiness score, AI traffic, request log, and page checks. It can never change anything.', 'agentimus' ) . '</small></span></label>';
		if ( 'write' === $max ) {
			$b .= '<label class="grant"><input type="radio" name="grant" value="write" checked>'
				. '<span><strong>' . esc_html__( 'Read and write', 'agentimus' ) . '</strong>'
				. '<small>' . esc_html__( 'Also draft and edit content, set AI descriptions and topics, apply fixes — always within your write settings. Publishing stays off unless you allow it there.', 'agentimus' ) . '</small></span></label>';
		}
		$b .= '</div>';

		if ( $down ) {
			$b .= '<p class="wallnote"><strong>' . esc_html__( 'This site does not allow agent writes.', 'agentimus' ) . '</strong> '
				. sprintf(
					/* translators: %s: client-claimed name. */
					esc_html__( '“%s” asked for write access, but your settings keep writing off — so it can connect as read-only. You can allow writes any time in Settings.', 'agentimus' ),
					esc_html( $name )
				) . '</p>';
		}

		$b .= '<p class="actas">' . sprintf(
			/* translators: %s: user login. */
			esc_html__( 'Approval acts as your account: %s · one approval, only for this assistant · revoke any time in Settings.', 'agentimus' ),
			'<code>' . esc_html( $user instanceof \WP_User ? $user->user_login : '' ) . '</code>'
		) . '</p>';

		$b .= '<div class="actions">'
			. '<button class="btn approve" type="submit" name="decision" value="approve">' . esc_html__( 'Approve', 'agentimus' ) . '</button>'
			. '<button class="btn ghost" type="submit" name="decision" value="deny">' . esc_html__( 'Cancel', 'agentimus' ) . '</button>'
			. '</div>';

		$b .= '<p class="fineprint">' . sprintf(
			/* translators: %s: client-claimed name. */
			esc_html__( 'Approving gives “%s” its own key to this site’s MCP tools — nothing else. Every call it makes shows in Agent Access.', 'agentimus' ),
			esc_html( $name )
		) . '</p>';
		$b .= '</form>';

		return $this->page( $b );
	}

	/**
	 * The page shell: the prototype's cream card, self-contained CSS, no
	 * theme, no scripts.
	 *
	 * @param string $body Escaped body HTML.
	 * @return string
	 */
	private function page( $body ) {
		$site = get_bloginfo( 'name' );
		$host = wp_parse_url( home_url( '/' ), PHP_URL_HOST );
		return '<!DOCTYPE html><html lang="' . esc_attr( get_bloginfo( 'language' ) ) . '"><head>'
			. '<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
			. '<meta name="robots" content="noindex">'
			. '<title>' . esc_html__( 'Connect an assistant', 'agentimus' ) . ' · ' . esc_html( $site ) . '</title>'
			. '<style>'
			. 'body{margin:0;background:#f7f3ea;color:#211f1a;font:15px/1.55 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;display:flex;justify-content:center;padding:48px 20px}'
			. '.card{width:100%;max-width:480px}'
			. '.badge{display:flex;align-items:center;gap:10px;margin-bottom:26px}'
			. '.mark{width:34px;height:34px;border-radius:8px;background:#211f1a;color:#f7f3ea;font-family:Georgia,serif;font-size:20px;display:grid;place-items:center}'
			. '.badge b{font-family:"Iowan Old Style","Palatino Linotype",Georgia,serif;font-weight:500;font-size:17px}'
			. '.badge small{display:block;font-family:ui-monospace,Menlo,monospace;font-size:11.5px;color:#6d6759}'
			. 'h1{font-family:"Iowan Old Style","Palatino Linotype",Georgia,serif;font-weight:500;font-size:27px;line-height:1.25;margin:0 0 6px}'
			. '.claims{margin:0 0 22px;color:#6d6759}.claims em{font-style:normal;color:#211f1a}'
			. '.grantbox{border:1px solid #e3dbc9;border-radius:8px;background:#fdfbf6;overflow:hidden;margin:0 0 14px}'
			. '.grant{display:flex;gap:12px;padding:14px 16px;align-items:flex-start;border-bottom:1px solid #e3dbc9;cursor:pointer}'
			. '.grant:last-child{border-bottom:0}.grant input{margin-top:4px;accent-color:#1a6b52}'
			. '.grant strong{display:block;font-size:14.5px}.grant small{color:#6d6759;display:block;font-size:13px}'
			. '.wallnote{border:1px solid #d8c294;background:#f6ecd9;border-radius:8px;padding:11px 14px;font-size:13.5px;margin:0 0 14px}'
			. '.actas{font-size:13px;color:#6d6759;margin:0 0 22px}.actas code{font-family:ui-monospace,Menlo,monospace;font-size:12px;color:#211f1a}'
			. '.actions{display:flex;gap:10px}'
			. '.btn{font-family:ui-monospace,Menlo,monospace;font-size:12px;letter-spacing:.12em;text-transform:uppercase;border-radius:6px;padding:11px 20px;cursor:pointer;border:1px solid transparent}'
			. '.approve{background:#1a6b52;border-color:#1a6b52;color:#fdfbf6}'
			. '.ghost{background:transparent;border-color:#cfc5ad;color:#211f1a}'
			. '.btn:focus-visible{outline:2px solid #1a6b52;outline-offset:2px}'
			. '.fineprint{margin:22px 0 0;font-size:12.5px;color:#6d6759}'
			. '</style></head><body><div class="card">'
			. '<div class="badge"><span class="mark" aria-hidden="true">A</span><span><b>' . esc_html( $site ) . '</b>'
			. '<small>' . esc_html( is_string( $host ) ? $host : '' ) . ' · Agentimus</small></span></div>'
			. $body
			. '</div></body></html>';
	}

	/**
	 * A clean 404 for the gated-off state.
	 *
	 * @return void
	 */
	private function not_found() {
		global $wp_query;
		if ( $wp_query instanceof \WP_Query ) {
			$wp_query->set_404();
		}
		status_header( 404 );
		nocache_headers();
	}
}

<?php
/**
 * ⛔ What an agent can never do while it works through the Agentimus MCP address,
 * even signed in as an administrator.
 *
 * An agent call is a request on /agentimus/v1/mcp, however it signed in: the
 * connection token, OAuth or an application password. Tools another plugin adds to
 * our server run inside that request, so the blocks hold for them too. They start
 * when core has matched the route ({@see start()}) and end after its callbacks
 * ({@see finish()}); a REST call made from inside a tool keeps them.
 *
 * The blocks only take away. An agent never gets a power its user lacks.
 *
 * His rulings, 2026-11-22:
 *  - Removed: editing plugin or theme files (that is running PHP, and PHP can undo
 *    every other block), users, login passwords, WordPress updates, uploading any
 *    file type, import and export, menus and the site editor, multisite network
 *    powers.
 *  - Allowed: installing plugins and themes from WordPress.org only (never a zip or
 *    another address), turning plugins on and off, switching themes, updating and
 *    deleting plugins and themes. Agentimus itself can never be turned off,
 *    uninstalled or deleted: that would remove every limit at once.
 *  - Kept as they are: the site address, secret keys, admin email, sign-up settings,
 *    the roles list, the agent-access switches and Agentimus's sign-in data.
 *  - ⭐ `unfiltered_html` stays. {@see AgentMarkup} refuses any script an agent
 *    adds; taking the power away would make kses strip the owner's own embeds from
 *    any post an agent edits (ruling of 2026-10-31).
 *
 * Learned from Fluent MCP's hard blocks, with the three holes found in them
 * closed: application passwords, other admins' accounts, and deleting an option
 * where only updating it was guarded.
 *
 * Honest limit: an application password used OUTSIDE this address still reaches
 * the whole REST API with its user's full powers. SECURITY.md says so.
 *
 * @package Agentimus
 */

namespace Agentimus\Abilities;

use Agentimus\McpToken;

defined( 'ABSPATH' ) || exit;

final class HardBlocks {

	/** Powers removed for the whole agent call. */
	const REMOVED = array(
		'edit_plugins',
		'edit_themes',
		'edit_files',
		'create_users',
		'edit_users',
		'promote_users',
		'delete_users',
		'update_core',
		'unfiltered_upload',
		'edit_theme_options',
		'import',
		'export',
		'manage_network',
		'manage_sites',
		'manage_network_users',
		'manage_network_plugins',
		'manage_network_themes',
		'manage_network_options',
		'upgrade_network',
		'setup_network',
		'create_sites',
		'delete_sites',
	);

	/**
	 * Checks refused for every user, the agent's own included. Editing a user means
	 * its email and password, which is how an account is taken over; a login
	 * password works without this address, so none of these limits would apply.
	 */
	const REFUSED = array(
		'edit_user',
		'delete_user',
		'promote_user',
		'remove_user',
		'add_users',
		'edit_user_meta',
		'add_user_meta',
		'delete_user_meta',
		'create_app_password',
		'list_app_passwords',
		'read_app_password',
		'edit_app_password',
		'delete_app_password',
		'delete_app_passwords',
		'upload_plugins',
		'upload_themes',
	);

	/** Options that keep their value. The roles list is added in {@see kept_options()}. */
	const KEPT_OPTIONS = array(
		'siteurl',
		'home',
		'admin_email',
		'new_admin_email',
		'users_can_register',
		'default_role',
		'secret',
		'auth_key',
		'auth_salt',
		'secure_auth_key',
		'secure_auth_salt',
		'logged_in_key',
		'logged_in_salt',
		'nonce_key',
		'nonce_salt',
	);

	/** The settings that decide what agents may do. Every other setting still changes. */
	const ACCESS_SETTINGS = array(
		'enable_mcp_server',
		'mcp_public_tool_list',
		'enable_agent_writes',
		'agent_writes_publish',
		'agent_writes_settings',
		'agent_writes_maintenance',
		'agent_writes_content',
		'oauth_auth_server',
		'enable_webmcp',
		'webmcp_hidden_tools',
	);

	/** The connection token's fields that may change: its "last used" line. */
	const TOKEN_USE_FIELDS = array( 'last_used_at', 'last_used_ua' );

	/** The only address an install may come from. */
	const INSTALL_HOST = 'downloads.wordpress.org';

	/** @var int How many agent-call dispatches are open (an agent call is never nested in practice; counted anyway). */
	private static $depth = 0;

	/** @var array<string, array{0: mixed, 1: bool}> Values held while core deletes an option, to put back. */
	private static $held = array();

	/**
	 * Hook the start and end of every REST dispatch. Both cost one string compare
	 * on any route that is not ours.
	 *
	 * @return void
	 */
	public static function watch() {
		// After the credentials' confine() (priority 0), before every permission check.
		add_filter( 'rest_request_before_callbacks', array( self::class, 'start' ), 1, 3 );
		// Last, so the agent-access recorder (priority 10) still runs inside the call.
		add_filter( 'rest_request_after_callbacks', array( self::class, 'finish' ), PHP_INT_MAX, 3 );
	}

	/**
	 * rest_request_before_callbacks: an agent call starts.
	 *
	 * @param mixed            $response Dispatch result so far.
	 * @param array            $handler  The matched handler (unused).
	 * @param \WP_REST_Request $request  The request core matched.
	 * @return mixed Unchanged.
	 */
	public static function start( $response, $handler, $request ) {
		if ( $request instanceof \WP_REST_Request && McpToken::is_route( $request->get_route() ) ) {
			if ( 0 === self::$depth ) {
				self::on();
			}
			++self::$depth;
		}
		return $response;
	}

	/**
	 * rest_request_after_callbacks: the agent call ends.
	 *
	 * @param mixed            $response Dispatch result.
	 * @param array            $handler  The matched handler (unused).
	 * @param \WP_REST_Request $request  The request core matched.
	 * @return mixed Unchanged.
	 */
	public static function finish( $response, $handler, $request ) {
		if ( self::$depth > 0 && $request instanceof \WP_REST_Request && McpToken::is_route( $request->get_route() ) ) {
			--self::$depth;
			if ( 0 === self::$depth ) {
				self::off();
			}
		}
		return $response;
	}

	/**
	 * Whether an agent call is running now.
	 *
	 * @return bool
	 */
	public static function active() {
		return self::$depth > 0;
	}

	/**
	 * Start every block.
	 *
	 * @return void
	 */
	private static function on() {
		add_filter( 'user_has_cap', array( self::class, 'remove_powers' ), PHP_INT_MAX );
		// Super admins pass user_has_cap without looking, so refuse in map_meta_cap too.
		add_filter( 'map_meta_cap', array( self::class, 'refuse' ), PHP_INT_MAX, 4 );

		foreach ( self::kept_options() as $option ) {
			add_filter( 'pre_update_option_' . $option, array( self::class, 'keep_value' ), PHP_INT_MAX, 2 );
		}
		add_filter( 'pre_update_option_agentimus_settings', array( self::class, 'keep_access_settings' ), PHP_INT_MAX, 2 );
		add_filter( 'pre_update_option_' . McpToken::OPTION, array( self::class, 'keep_token' ), PHP_INT_MAX, 2 );
		add_filter( 'pre_update_option_agentimus_oauth', array( self::class, 'keep_grants' ), PHP_INT_MAX, 2 );
		add_filter( 'pre_update_option_active_plugins', array( self::class, 'keep_agentimus_on' ), PHP_INT_MAX, 2 );
		add_filter( 'pre_update_site_option_active_sitewide_plugins', array( self::class, 'keep_agentimus_on_network' ), PHP_INT_MAX, 2 );

		// Core has no filter that stops a delete, so hold the value and put it back.
		add_action( 'delete_option', array( self::class, 'hold_before_delete' ), PHP_INT_MAX );
		add_action( 'deleted_option', array( self::class, 'restore_after_delete' ), PHP_INT_MAX );

		// Turning Agentimus off or deleting it stops before its own hooks run.
		add_action( 'deactivate_plugin', array( self::class, 'stop_for_agentimus' ), 0 );
		add_action( 'pre_uninstall_plugin', array( self::class, 'stop_for_agentimus' ), 0 );
		add_action( 'delete_plugin', array( self::class, 'stop_for_agentimus' ), 0 );

		add_filter( 'upgrader_pre_download', array( self::class, 'only_wordpress_org_installs' ), PHP_INT_MAX, 4 );

		// Saved skills: whatever an agent writes stays off, an agent's mark stays on
		// it, and none is deleted, by any path (step D).
		add_filter( 'wp_insert_post_data', array( self::class, 'skills_stay_off' ), PHP_INT_MAX, 2 );
		add_filter( 'update_post_metadata', array( self::class, 'skills_keep_their_author' ), PHP_INT_MAX, 4 );
		add_filter( 'add_post_metadata', array( self::class, 'skills_keep_their_author' ), PHP_INT_MAX, 4 );
		add_filter( 'pre_delete_post', array( self::class, 'skills_stay' ), PHP_INT_MAX, 2 );
	}

	/**
	 * Stop every block.
	 *
	 * @return void
	 */
	private static function off() {
		remove_filter( 'user_has_cap', array( self::class, 'remove_powers' ), PHP_INT_MAX );
		remove_filter( 'map_meta_cap', array( self::class, 'refuse' ), PHP_INT_MAX );

		foreach ( self::kept_options() as $option ) {
			remove_filter( 'pre_update_option_' . $option, array( self::class, 'keep_value' ), PHP_INT_MAX );
		}
		remove_filter( 'pre_update_option_agentimus_settings', array( self::class, 'keep_access_settings' ), PHP_INT_MAX );
		remove_filter( 'pre_update_option_' . McpToken::OPTION, array( self::class, 'keep_token' ), PHP_INT_MAX );
		remove_filter( 'pre_update_option_agentimus_oauth', array( self::class, 'keep_grants' ), PHP_INT_MAX );
		remove_filter( 'pre_update_option_active_plugins', array( self::class, 'keep_agentimus_on' ), PHP_INT_MAX );
		remove_filter( 'pre_update_site_option_active_sitewide_plugins', array( self::class, 'keep_agentimus_on_network' ), PHP_INT_MAX );

		remove_action( 'delete_option', array( self::class, 'hold_before_delete' ), PHP_INT_MAX );
		remove_action( 'deleted_option', array( self::class, 'restore_after_delete' ), PHP_INT_MAX );

		remove_action( 'deactivate_plugin', array( self::class, 'stop_for_agentimus' ), 0 );
		remove_action( 'pre_uninstall_plugin', array( self::class, 'stop_for_agentimus' ), 0 );
		remove_action( 'delete_plugin', array( self::class, 'stop_for_agentimus' ), 0 );

		remove_filter( 'upgrader_pre_download', array( self::class, 'only_wordpress_org_installs' ), PHP_INT_MAX );
		remove_filter( 'wp_insert_post_data', array( self::class, 'skills_stay_off' ), PHP_INT_MAX );
		remove_filter( 'update_post_metadata', array( self::class, 'skills_keep_their_author' ), PHP_INT_MAX );
		remove_filter( 'add_post_metadata', array( self::class, 'skills_keep_their_author' ), PHP_INT_MAX );
		remove_filter( 'pre_delete_post', array( self::class, 'skills_stay' ), PHP_INT_MAX );

		self::$held = array();
	}

	/**
	 * user_has_cap: take the removed powers off.
	 *
	 * @param array<string, bool> $allcaps The user's powers.
	 * @return array<string, bool>
	 */
	public static function remove_powers( $allcaps ) {
		foreach ( self::REMOVED as $power ) {
			$allcaps[ $power ] = false;
		}
		return (array) $allcaps;
	}

	/**
	 * map_meta_cap: refuse a check that needs a removed power, the checks refused
	 * for every user, and turning Agentimus off.
	 *
	 * @param string[] $caps    What the check maps to.
	 * @param string   $cap     The check.
	 * @param int      $user_id The user asking (unused).
	 * @param array    $args    The check's arguments, such as a plugin file.
	 * @return string[]
	 */
	public static function refuse( $caps, $cap, $user_id = 0, $args = array() ) {
		$caps = (array) $caps;
		if ( in_array( $cap, self::REMOVED, true ) || in_array( $cap, self::REFUSED, true ) || array_intersect( $caps, self::REMOVED ) ) {
			return array( 'do_not_allow' );
		}
		if ( 'deactivate_plugin' === $cap && isset( $args[0] ) && self::is_agentimus( $args[0] ) ) {
			return array( 'do_not_allow' );
		}
		return $caps;
	}

	/**
	 * pre_update_option_*: keep the value it had.
	 *
	 * @param mixed $value     The value asked for (unused).
	 * @param mixed $old_value The value now.
	 * @return mixed
	 */
	public static function keep_value( $value, $old_value ) {
		return $old_value;
	}

	/**
	 * pre_update_option_agentimus_settings: the agent-access switches keep their
	 * value; every other setting changes as asked.
	 *
	 * @param mixed $value     The settings asked for.
	 * @param mixed $old_value The settings now.
	 * @return mixed
	 */
	public static function keep_access_settings( $value, $old_value ) {
		if ( ! is_array( $value ) ) {
			return $old_value;
		}
		$old = is_array( $old_value ) ? $old_value : array();
		foreach ( self::ACCESS_SETTINGS as $key ) {
			if ( array_key_exists( $key, $old ) ) {
				$value[ $key ] = $old[ $key ];
			} else {
				unset( $value[ $key ] );
			}
		}
		return $value;
	}

	/**
	 * pre_update_option_agentimus_mcp_token: only the "last used" line changes. An
	 * agent can't make a token, change its level or swap its key.
	 *
	 * @param mixed $value     The token record asked for.
	 * @param mixed $old_value The token record now.
	 * @return mixed
	 */
	public static function keep_token( $value, $old_value ) {
		if ( ! is_array( $old_value ) ) {
			return $old_value;
		}
		$kept = $old_value;
		if ( is_array( $value ) ) {
			foreach ( self::TOKEN_USE_FIELDS as $field ) {
				if ( array_key_exists( $field, $value ) ) {
					$kept[ $field ] = $value[ $field ];
				}
			}
		}
		return $kept;
	}

	/**
	 * pre_update_option_agentimus_oauth: only each app's "last used" time changes.
	 * An agent can't add an app, a grant or a token, or change one.
	 *
	 * @param mixed $value     The OAuth state asked for.
	 * @param mixed $old_value The OAuth state now.
	 * @return mixed
	 */
	public static function keep_grants( $value, $old_value ) {
		if ( ! is_array( $old_value ) ) {
			return $old_value;
		}
		$kept = $old_value;
		if ( is_array( $value ) && isset( $kept['clients'] ) && is_array( $kept['clients'] ) ) {
			foreach ( $kept['clients'] as $id => $client ) {
				if ( is_array( $client ) && isset( $value['clients'][ $id ]['last_used'] ) ) {
					$kept['clients'][ $id ]['last_used'] = (int) $value['clients'][ $id ]['last_used'];
				}
			}
		}
		return $kept;
	}

	/**
	 * pre_update_option_active_plugins: Agentimus stays on. Other plugins come and
	 * go as asked.
	 *
	 * @param mixed $value     The list asked for.
	 * @param mixed $old_value The list now.
	 * @return mixed
	 */
	public static function keep_agentimus_on( $value, $old_value ) {
		$self = self::agentimus_file();
		if ( in_array( $self, (array) $old_value, true ) && ! in_array( $self, (array) $value, true ) ) {
			$value   = array_values( (array) $value );
			$value[] = $self;
		}
		return $value;
	}

	/**
	 * pre_update_site_option_active_sitewide_plugins: the same, on a network
	 * (keyed by file, valued by the time it was turned on).
	 *
	 * @param mixed $value     The list asked for.
	 * @param mixed $old_value The list now.
	 * @return mixed
	 */
	public static function keep_agentimus_on_network( $value, $old_value ) {
		$self = self::agentimus_file();
		if ( is_array( $old_value ) && isset( $old_value[ $self ] ) && ( ! is_array( $value ) || ! isset( $value[ $self ] ) ) ) {
			$value          = (array) $value;
			$value[ $self ] = $old_value[ $self ];
		}
		return $value;
	}

	/**
	 * delete_option: hold a kept option's value before core deletes it.
	 *
	 * @param string $option The option.
	 * @return void
	 */
	public static function hold_before_delete( $option ) {
		if ( ! in_array( $option, self::undeletable_options(), true ) ) {
			return;
		}
		$value = get_option( $option, null );
		if ( null === $value ) {
			return;
		}
		$alloptions = wp_load_alloptions();
		self::$held[ $option ] = array( $value, isset( $alloptions[ $option ] ) );
	}

	/**
	 * deleted_option: put a held option back.
	 *
	 * @param string $option The option.
	 * @return void
	 */
	public static function restore_after_delete( $option ) {
		if ( ! isset( self::$held[ $option ] ) ) {
			return;
		}
		list( $value, $autoload ) = self::$held[ $option ];
		unset( self::$held[ $option ] );
		add_option( $option, $value, '', $autoload ? 'yes' : 'no' );
	}

	/**
	 * deactivate_plugin, pre_uninstall_plugin, delete_plugin: stop when the plugin
	 * is Agentimus, before its own deactivation or uninstall code runs.
	 *
	 * @param string $plugin The plugin file.
	 * @return void
	 */
	public static function stop_for_agentimus( $plugin ) {
		if ( ! self::is_agentimus( $plugin ) ) {
			return;
		}
		wp_die(
			esc_html__( 'An agent cannot turn off or remove Agentimus. The site owner can, in wp-admin.', 'agentimus' ),
			'',
			array( 'response' => 403 )
		);
	}

	/**
	 * upgrader_pre_download: an install comes only from WordPress.org. An update
	 * comes from wherever the installed plugin or theme says, such as its vendor's
	 * server. Anything that names neither is treated as an install.
	 *
	 * @param mixed       $reply      What runs so far (false = download as normal).
	 * @param string      $package    The package address or file.
	 * @param object|null $upgrader   The upgrader (unused).
	 * @param array       $hook_extra What the upgrader is doing.
	 * @return mixed
	 */
	public static function only_wordpress_org_installs( $reply, $package, $upgrader = null, $hook_extra = array() ) {
		if ( false !== $reply ) {
			return $reply;
		}
		$hook_extra = (array) $hook_extra;
		$is_update  = ( isset( $hook_extra['plugin'] ) || isset( $hook_extra['theme'] ) || isset( $hook_extra['plugins'] ) || isset( $hook_extra['themes'] ) )
			&& ( ! isset( $hook_extra['action'] ) || 'install' !== $hook_extra['action'] );
		if ( $is_update || self::is_wordpress_org_package( $package ) ) {
			return $reply;
		}
		return new \WP_Error(
			'agentimus_install_source',
			__( 'An agent can install plugins and themes from WordPress.org only.', 'agentimus' )
		);
	}

	/**
	 * wp_insert_post_data: a skill an agent writes is stored off. Only the owner's
	 * screen turns one on.
	 *
	 * @param array $data    The post as it will be saved.
	 * @param array $postarr What was asked (unused).
	 * @return array
	 */
	public static function skills_stay_off( $data, $postarr = array() ) {
		if ( isset( $data['post_type'] ) && Skills::POST_TYPE === $data['post_type'] && 'publish' === ( isset( $data['post_status'] ) ? $data['post_status'] : '' ) ) {
			$data['post_status'] = 'draft';
		}
		return $data;
	}

	/**
	 * update_post_metadata: a skill's author mark can only say "agent" during an
	 * agent call, so an agent can't pass its skill off as the owner's.
	 *
	 * @param mixed  $check    Null to let the update run.
	 * @param int    $post_id  The post.
	 * @param string $meta_key The key.
	 * @param mixed  $value    The value.
	 * @return mixed
	 */
	public static function skills_keep_their_author( $check, $post_id, $meta_key, $value ) {
		if ( Skills::BY === $meta_key && 'agent' !== $value && Skills::POST_TYPE === get_post_type( $post_id ) ) {
			return false;
		}
		return $check;
	}

	/**
	 * pre_delete_post: an agent call never deletes a skill.
	 *
	 * @param mixed    $delete Null to let it run.
	 * @param \WP_Post $post   The post.
	 * @return mixed
	 */
	public static function skills_stay( $delete, $post ) {
		if ( $post instanceof \WP_Post && Skills::POST_TYPE === $post->post_type ) {
			return false;
		}
		return $delete;
	}

	/**
	 * Whether a package address is a WordPress.org download over HTTPS.
	 *
	 * @param mixed $package The package address or file.
	 * @return bool
	 */
	private static function is_wordpress_org_package( $package ) {
		if ( ! is_string( $package ) ) {
			return false;
		}
		$parts = wp_parse_url( $package );
		return is_array( $parts )
			&& isset( $parts['scheme'], $parts['host'] )
			&& 'https' === strtolower( $parts['scheme'] )
			&& self::INSTALL_HOST === strtolower( $parts['host'] )
			&& ! isset( $parts['user'] )
			&& ! isset( $parts['port'] );
	}

	/**
	 * Options that keep their value through an update.
	 *
	 * @return string[]
	 */
	private static function kept_options() {
		global $wpdb;
		// The roles list, and which other plugins' abilities the owner turned on.
		return array_merge( self::KEPT_OPTIONS, array( $wpdb->get_blog_prefix() . 'user_roles', OtherAbilities::OPTION ) );
	}

	/**
	 * Options that come back when deleted.
	 *
	 * @return string[]
	 */
	private static function undeletable_options() {
		return array_merge( self::kept_options(), array( 'agentimus_settings', McpToken::OPTION, 'agentimus_oauth', 'active_plugins' ) );
	}

	/**
	 * Whether a plugin file is Agentimus.
	 *
	 * @param mixed $plugin A plugin file as WordPress names it.
	 * @return bool
	 */
	private static function is_agentimus( $plugin ) {
		return is_string( $plugin ) && self::agentimus_file() === $plugin;
	}

	/**
	 * Agentimus's plugin file, as WordPress names active plugins.
	 *
	 * @return string
	 */
	private static function agentimus_file() {
		return plugin_basename( AGENTIMUS_FILE );
	}
}

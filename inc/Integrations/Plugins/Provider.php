<?php
/**
 * The shape every plugin provider takes — written once, so the seven after the
 * first cannot each get the rules slightly wrong.
 *
 * A provider answers two questions about one plugin: is it here, and what does
 * it offer AI assistants. Everything else — how a resource is shaped, how the
 * post types fold in, which hooks to use, when to stay silent — belongs here,
 * because it is the same for all of them and because the parts that must never
 * be got wrong are the parts nobody should be retyping.
 *
 * ⛔ THE RULE THIS CLASS EXISTS TO ENFORCE. Only public, read-only addresses are
 * ever advertised. `safe_endpoints()` forces every endpoint a subclass hands up
 * to GET and `auth: none`, and DROPS any that says otherwise — so a provider
 * that names an authenticated route by mistake advertises nothing rather than
 * sending assistants at a locked door. A comment could be ignored; this cannot.
 *
 * ⭐ A provider with nothing public to say is complete and correct: it declares
 * no endpoints, so it registers no resource and only answers the roster card.
 * That is what the seven presence-only entries are — not unfinished work, but
 * plugins whose content lives behind a login, said honestly.
 *
 * @package Agentimus
 */

namespace Agentimus\Integrations\Plugins;

defined( 'ABSPATH' ) || exit;

abstract class Provider {

	/**
	 * The roster, and the running order the screen shows (his call, 2026-08-15):
	 * the Fluent family first, then WooCommerce, then Easy Digital Downloads,
	 * then whatever joins later. A new provider joins the end unless it belongs
	 * to one of those groups — the roster is a running order, not an alphabet.
	 *
	 * ⭐ ONE list. The screen reads it for its cards, the Discovery hub reads it
	 * to tell "described by Agentimus" from "found by scanning", and the plugin
	 * boot reads it to register them. Adding a provider is adding a line here
	 * and nothing else — there is no second place to forget.
	 */
	const ROSTER = array(
		FluentCart::class,
		FluentForms::class,
		FluentCrm::class,
		FluentBooking::class,
		FluentCommunity::class,
		FluentSupport::class,
		WooCommerce::class,
		Edd::class,
	);

	/** This provider's id in the roster, the card and the discovery document. */
	const ID = '';

	/* -- What a subclass answers -------------------------------------------- */

	/**
	 * Whether the plugin is active on this site.
	 *
	 * @return bool
	 */
	abstract public static function present();

	/**
	 * The plugin's name, as its makers spell it.
	 *
	 * @return string
	 */
	abstract protected static function name();

	/**
	 * The one line under the name on the roster card.
	 *
	 * @return string
	 */
	abstract protected static function blurb();

	/**
	 * The public, read-only addresses this plugin serves on THIS site. Empty
	 * means nothing to advertise, which is a complete answer.
	 *
	 * Each entry: { url, description }. Method and auth are not a subclass's to
	 * choose — {@see safe_endpoints()} settles both.
	 *
	 * @return array<int,array>
	 */
	protected static function endpoints() {
		return array();
	}

	/**
	 * The plugin's own PUBLIC post types, to fold into what this site advertises.
	 *
	 * ⭐ Following the vendor, not deciding for them: a plugin that registered a
	 * type as public and REST-enabled has already declared that content public.
	 * ⛔ And the owner's no still outranks it — Content types keeps a veto that is
	 * applied after this ({@see \Agentimus\Content::post_types()}).
	 *
	 * @return string[]
	 */
	protected static function post_types() {
		return array();
	}

	/**
	 * The resource's kind — 'commerce', 'community', 'content'…
	 *
	 * @return string
	 */
	protected static function type() {
		return 'content';
	}

	/**
	 * The resource's title in the discovery document.
	 *
	 * @return string
	 */
	protected static function title() {
		return static::name();
	}

	/**
	 * One plain sentence: what an assistant would find here.
	 *
	 * @return string
	 */
	protected static function description() {
		return '';
	}

	/**
	 * What this resource allows, in the discovery vocabulary.
	 *
	 * @return string[]
	 */
	protected static function capabilities() {
		return array();
	}

	/* -- The machinery, written once ---------------------------------------- */

	/**
	 * Hook this provider up.
	 *
	 * Both hooks are added whatever the plugin's state, and each callback asks
	 * present() when it runs: a provider registers during our own boot, which
	 * can be before the plugin it describes has loaded, so a presence check here
	 * would be a coin toss on plugin order.
	 *
	 * ⭐ Priority 20, deliberately BETWEEN a plugin's own voice and the scanner's.
	 * A plugin that describes itself speaks at the default 10; the REST
	 * auto-adapter fills gaps at 99. We sit in the middle so that by the time we
	 * speak we can see what a vendor already said, and stand down rather than
	 * say it again — and so a namespace we describe is never also stubbed.
	 */
	public static function register() {
		add_action( AGENTIMUS_CANONICAL_HOOK, array( static::class, 'provide' ), 20 );
		add_filter( 'agentimus_post_types', array( static::class, 'fold_post_types' ), 10, 2 );
	}

	/**
	 * Hand the resource to the collector, if there is one to hand.
	 *
	 * @param \Agentimus\Discovery\Registry $registry The collector.
	 */
	public static function provide( $registry ) {
		$resource = static::resource();
		if ( array() === $resource || ! is_object( $registry ) || ! method_exists( $registry, 'register' ) ) {
			return;
		}

		// ⛔ Never a second voice on the same address. If a plugin — its own, or an
		// adapter someone wrote for it — already advertised what we were going to,
		// theirs stands and we say nothing. His rule read the other way round: we
		// do not hide what a vendor advertises, and we do not repeat it either. A
		// reader seeing one address under two names cannot tell which to believe.
		if ( self::already_described( $registry, $resource['endpoints'] ) ) {
			return;
		}

		$registry->register( $resource );
	}

	/**
	 * Whether some earlier provider already advertised one of these addresses.
	 *
	 * @param object $registry  The collector, mid-collection.
	 * @param array  $endpoints The addresses we were about to name.
	 * @return bool
	 */
	private static function already_described( $registry, array $endpoints ) {
		if ( ! method_exists( $registry, 'resources' ) ) {
			return false;
		}
		$ours = array();
		foreach ( $endpoints as $endpoint ) {
			$ours[] = untrailingslashit( (string) $endpoint['url'] );
		}
		foreach ( (array) $registry->resources() as $existing ) {
			if ( empty( $existing['endpoints'] ) || ! is_array( $existing['endpoints'] ) ) {
				continue;
			}
			foreach ( $existing['endpoints'] as $endpoint ) {
				$url = isset( $endpoint['url'] ) ? untrailingslashit( (string) $endpoint['url'] ) : '';
				if ( '' !== $url && in_array( $url, $ours, true ) ) {
					return true;
				}
			}
		}
		return false;
	}

	/**
	 * This plugin as one discovery resource, or an empty array when there is
	 * nothing true to say.
	 *
	 * @return array
	 */
	public static function resource() {
		if ( ! static::present() ) {
			return array();
		}

		$endpoints = self::safe_endpoints( static::endpoints() );
		if ( array() === $endpoints ) {
			return array(); // Nothing a machine can read is nothing to advertise.
		}

		return array(
			'id'           => static::ID,
			'title'        => static::title(),
			'type'         => static::type(),
			'description'  => static::description(),
			'capabilities' => static::capabilities(),
			'endpoints'    => $endpoints,
		);
	}

	/**
	 * Fold this plugin's public content into what the site advertises — only
	 * types the site really registered as public.
	 *
	 * @param string[] $types     The types so far.
	 * @param string[] $available Every public post type on this site.
	 * @return string[]
	 */
	public static function fold_post_types( $types, $available ) {
		if ( ! static::present() ) {
			return $types;
		}
		$types = (array) $types;
		foreach ( static::post_types() as $slug ) {
			if ( in_array( $slug, (array) $available, true ) && ! in_array( $slug, $types, true ) ) {
				$types[] = $slug;
			}
		}
		return $types;
	}

	/**
	 * The roster card the Integrations screen renders.
	 *
	 * @return array{id:string,name:string,blurb:string,present:bool}
	 */
	public static function describe() {
		return array(
			'id'      => static::ID,
			'name'    => static::name(),
			'blurb'   => static::blurb(),
			'present' => static::present(),
		);
	}

	/* -- The one rule ------------------------------------------------------- */

	/**
	 * Force every advertised address to be a public, read-only GET, and drop any
	 * that isn't.
	 *
	 * ⛔ This is the rule the whole class exists for. An authenticated route in a
	 * discovery document sends assistants at a locked door and tells every reader
	 * something untrue about the site; `wc/v3` is the example that started it.
	 * A subclass cannot opt out: whatever it writes, what leaves here is GET and
	 * `auth: none`, or it does not leave at all.
	 *
	 * @param array $endpoints What the subclass declared.
	 * @return array<int,array>
	 */
	private static function safe_endpoints( $endpoints ) {
		$out = array();
		foreach ( (array) $endpoints as $endpoint ) {
			if ( ! is_array( $endpoint ) || empty( $endpoint['url'] ) ) {
				continue;
			}
			// A declared auth of anything but 'none' is a provider telling us this
			// door is locked. Believe it, and say nothing about it.
			if ( isset( $endpoint['auth'] ) && 'none' !== $endpoint['auth'] ) {
				continue;
			}
			$out[] = array(
				'url'         => (string) $endpoint['url'],
				'type'        => 'rest',
				'methods'     => array( 'GET' ),
				'auth'        => 'none',
				'description' => isset( $endpoint['description'] ) ? (string) $endpoint['description'] : '',
			);
		}
		return $out;
	}
}

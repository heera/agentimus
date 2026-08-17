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
		FluentBoards::class,
		WooCommerce::class,
		Edd::class,
	);

	/** This provider's id in the roster, the card and the discovery document. */
	const ID = '';

	/**
	 * The classes that mean "this plugin is running here". Any one of them.
	 *
	 * ⭐ NAMES ARE DATA, and this is why. Nine providers each wrote their own
	 * `present()`, and one of them named a constant that has never existed in any
	 * release of the plugin it describes (`FLUENTSUPPORT_PLUGIN_PATH`; Fluent
	 * Support defines `FLUENT_SUPPORT_*`). Nothing failed, because the OTHER half
	 * of that probe worked — a dead fallback is invisible until the day it is all
	 * you have. As a list, every name is one row a test can walk.
	 *
	 * @var string[]
	 */
	const CLASSES = array();

	/**
	 * The constants that mean the same thing — a build whose autoloader has not
	 * run yet still defines these, so they catch what the class probe misses.
	 *
	 * @var string[]
	 */
	const CONSTANTS = array();

	/* -- What a subclass answers -------------------------------------------- */

	/**
	 * Whether the plugin is active on this site.
	 *
	 * ⭐ Written ONCE, off the declared names, so "how do we know a plugin is
	 * here" has one answer for the whole roster instead of nine. A provider with a
	 * stranger question to ask may still override this.
	 *
	 * ⛔ Only names the VENDOR controls — a class they ship, a constant they
	 * define. ⛔ Never `is_plugin_active()`: that reads a folder name the owner can
	 * rename, needs an admin-only file loaded, and says nothing about whether the
	 * plugin's code is really running.
	 *
	 * @return bool
	 */
	public static function present() {
		foreach ( static::CLASSES as $class ) {
			if ( class_exists( $class ) ) {
				return true;
			}
		}
		foreach ( static::CONSTANTS as $constant ) {
			if ( defined( $constant ) ) {
				return true;
			}
		}
		return false;
	}

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
	 * Whether SOMEONE ELSE already advertised one of these addresses.
	 *
	 * ⭐ Our own row is skipped. `provide()` asks this before registering, so we
	 * are never in the registry at that moment and the skip costs nothing there —
	 * but {@see in_the_document()} asks the same question AFTER a full collection,
	 * where finding our own address and reading it as a rival would make every
	 * provider report that it had stood down.
	 *
	 * @param object $registry  The collector.
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
		foreach ( (array) $registry->resources() as $id => $existing ) {
			if ( (string) $id === (string) static::ID ) {
				continue;
			}
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
	 * ⚠️ `present` and `describes` are two different facts and the card used to
	 * show only the first, calling every installed plugin "Described". On a site
	 * with the whole family installed, five of them describe NOTHING — their data
	 * is behind a login — and the card claimed otherwise for all five. A plugin
	 * being here is not the same as us having anything to say about it.
	 *
	 * @param object|null $registry The collected registry, when the caller has
	 *                              one. Given it, the card can tell a description
	 *                              we made from one we stood down from; without
	 *                              it, it can only report what we would say.
	 * @return array{id:string,name:string,blurb:string,present:bool,describes:bool}
	 */
	public static function describe( $registry = null ) {
		$present = static::present();
		return array(
			'id'        => static::ID,
			'name'      => static::name(),
			'blurb'     => static::blurb(),
			'present'   => $present,
			'describes' => $present && ( self::in_the_document( $registry ) || array() !== static::live_post_types() ),
		);
	}

	/**
	 * Whether OUR resource is really in the document this site serves.
	 *
	 * ⚠️ NOT "would we register one". `provide()` STANDS DOWN when a plugin's own
	 * voice already owns the address, and this card used to count the intention
	 * instead of the outcome: on wpftest the `agentimus-adapter-fluentcart`
	 * plugin names the product route, so our provider says nothing there — and
	 * the tile still read "Described". It was true on that site only by accident,
	 * because the product post type folds in separately; a provider that stood
	 * down AND registered no public post type would have claimed a description it
	 * never made.
	 *
	 * ⭐ It asks the SAME question `provide()` asks, through the same method, so
	 * the card and the document can never disagree about who described what. ⛔ Not
	 * "is our id in the registry": that would make the card depend on our own
	 * registration having already run this request, which is true of a page load
	 * and not of every caller.
	 *
	 * ⚠️ The registry is HANDED IN, never fetched. Reaching for the singleton here
	 * would force the whole collection at card-drawing time — freezing the
	 * document before a provider that boots later has had its say, and (the way
	 * it was caught) making the answer depend on whether something else had
	 * already collected this request.
	 *
	 * @param object|null $registry The collected registry, or null when the caller
	 *                              has none — with no view of what else is in the
	 *                              document there is nothing that could have stood
	 *                              us down, so our own answer stands.
	 * @return bool
	 */
	private static function in_the_document( $registry = null ) {
		$resource = static::resource();
		if ( array() === $resource ) {
			return false;
		}
		if ( ! is_object( $registry ) ) {
			return true;
		}
		return ! self::already_described( $registry, $resource['endpoints'] );
	}

	/**
	 * This plugin's post types that THIS site actually registered.
	 *
	 * @return string[]
	 */
	private static function live_post_types() {
		$out = array();
		foreach ( static::post_types() as $slug ) {
			if ( post_type_exists( $slug ) ) {
				$out[] = $slug;
			}
		}
		return $out;
	}

	/**
	 * One endpoint for a plugin's own public post type, or nothing.
	 *
	 * ⭐ The route is read from the type's OWN registration, never assembled from
	 * its name: Easy Digital Downloads registers `download` but serves it at
	 * `edd-downloads`, so guessing would have advertised a 404. And it is only
	 * named when the site registered the type as public AND REST-enabled — the
	 * vendor's own declaration that this content is public.
	 *
	 * @param string $post_type   The type slug.
	 * @param string $description What an assistant would find there.
	 * @return array<int,array>
	 */
	protected static function type_endpoint( $post_type, $description ) {
		if ( ! post_type_exists( $post_type ) ) {
			return array();
		}
		$object = get_post_type_object( $post_type );
		if ( ! $object || empty( $object->public ) || empty( $object->show_in_rest ) ) {
			return array();
		}
		$base = ! empty( $object->rest_base ) ? $object->rest_base : $post_type;
		return array(
			array(
				'url'         => '/wp-json/wp/v2/' . $base,
				'description' => $description,
			),
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

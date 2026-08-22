<?php
/**
 * FluentCommunity — the spaces a community is organised into.
 *
 * ⭐ Probed on a real site before writing a line of it: `/wp-json/
 * fluent-community/v2/spaces` answers 200 to anyone, and returns the list of
 * spaces. That is what an assistant can usefully be told — the rooms this
 * community has, so it can answer "what is discussed here".
 *
 * ⚠️ …on a site whose community is OPEN, which is the only site it was probed
 * on. That address is not public the way a storefront is: it is public while the
 * portal's access level says so, and a members-only community refuses it with a
 * 401. This provider does not try to know that — it names the address, and
 * {@see \Agentimus\Discovery\Reachability} proves it before the site publishes
 * it. See {@see endpoints()} for why that is where the decision belongs.
 *
 * ⛔ Only spaces. The feeds route answers 200 as well, but a feed is members'
 * posts, and a community's posts are not the site owner's to hand out wholesale
 * to every reader of a public document. Naming the rooms is a description;
 * naming everything said in them is a different decision, and not one this
 * plugin gets to make on the owner's behalf.
 *
 * @package Agentimus
 */

namespace Agentimus\Integrations\Plugins;

defined( 'ABSPATH' ) || exit;

final class FluentCommunity extends Provider {

	const ID = 'fluentcommunity';

	/**
	 * The plugin's own page, so a card can open it.
	 *
	 * ⛔ NEVER GUESSED. Source: FluentCommunity's own `Plugin URI:` header — ⚠️ `.co`, NOT `.com`.
	 */
	const HOME = 'https://fluentcommunity.co';

	/** The public list of spaces — verified 200 without a login. */
	const SPACES_URL = '/wp-json/fluent-community/v2/spaces';

	/** Read from FluentCommunity's own bootstrap: `fluent-community.php:17`, `app/App.php:3`. */
	const CLASSES = array( 'FluentCommunity\\App\\App' );

	const CONSTANTS = array( 'FLUENT_COMMUNITY_PLUGIN_VERSION' );

	protected static function name() {
		return 'FluentCommunity';
	}

	protected static function blurb() {
		return __( 'Community spaces and posts, described to AI assistants.', 'agentimus' );
	}

	/**
	 * ⚠️ Not "community" — the spec's vocabulary does not have that word, and the
	 * registry rejected the whole resource for it (correctly, and by name). A
	 * community's spaces are a listing of places, which is what `directory`
	 * means here; the extension form `x-community` was the alternative and says
	 * less to a reader who does not know this plugin.
	 */
	protected static function type() {
		return 'directory';
	}

	protected static function title() {
		return __( 'Community spaces', 'agentimus' );
	}

	protected static function description() {
		return __( 'The spaces this community is organised into, readable without a login.', 'agentimus' );
	}

	protected static function capabilities() {
		return array( 'community.spaces.read' );
	}

	/**
	 * ⚠️⚠️ THIS ADDRESS IS NOT PUBLIC THE WAY A STOREFRONT IS, and for one session
	 * it was fixed here by reading FluentCommunity's own `Helper::isPublicAccessible()`.
	 * That code was correct and is deliberately gone.
	 *
	 * ⭐⭐ HIS RULE, and it is the better engineering: *"you know this plugin's
	 * settings, that is why you could decide — but this will not work for plugins
	 * Agentimus finds automatically. We must figure out the right way as if we know
	 * nothing about a plugin, everything at runtime."* A fix that needs us to have
	 * read one vendor's source is a fix that protects one vendor, and there are
	 * thousands.
	 *
	 * So this provider goes back to naming the address, and {@see
	 * \Agentimus\Discovery\Reachability} decides whether it is published — by
	 * asking the route's own permission check as nobody, and by making one real
	 * anonymous request. Verified on a live site: with the community set to
	 * members-only that address answers 401 and the general check marks it
	 * `refused-a-stranger`, with no FluentCommunity knowledge anywhere in the path.
	 */
	protected static function endpoints() {
		return array(
			array(
				'url'         => self::SPACES_URL,
				'description' => __( 'List the community’s spaces.', 'agentimus' ),
			),
		);
	}
}

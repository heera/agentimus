<?php
/**
 * FluentCommunity — the spaces a community is organised into.
 *
 * ⭐ Probed on a real site before writing a line of it: `/wp-json/
 * fluent-community/v2/spaces` answers 200 to anyone, and returns the list of
 * spaces. That is what an assistant can usefully be told — the rooms this
 * community has, so it can answer "what is discussed here".
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

	/** The public list of spaces — verified 200 without a login. */
	const SPACES_URL = '/wp-json/fluent-community/v2/spaces';

	/**
	 * Whether the plugin is active here. The class is the primary probe; the
	 * version constant catches a build whose autoloader hasn't run yet.
	 *
	 * @return bool
	 */
	public static function present() {
		return class_exists( 'FluentCommunity\App\App' ) || defined( 'FLUENT_COMMUNITY_PLUGIN_VERSION' );
	}

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

	protected static function endpoints() {
		return array(
			array(
				'url'         => self::SPACES_URL,
				'description' => __( 'List the community’s spaces.', 'agentimus' ),
			),
		);
	}
}

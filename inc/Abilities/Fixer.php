<?php
/**
 * Apply-fix — enact a readiness check's own remediation, by check id.
 *
 * The write half of the read-readiness loop: `read-readiness` returns every check
 * with its known fix; this class APPLIES that fix for the checks whose remediation
 * is a known-safe, reversible switch the admin UI itself would flip. It is a CLOSED
 * vocabulary, deliberately not a generic "update any setting" tool: an agent can
 * only enact the exact remediations the plugin already offers, so it can never
 * "fix" its way into disabling the request log, loosening a privacy default, or
 * touching anything security-relevant. Checks whose fix needs content, judgement
 * or server access (write a profile sentence, delete a static robots.txt, change
 * the permalink structure) come back applied=false with the honest next step.
 *
 * Two-step shape, mirroring {@see Readiness::llms_words_row()}'s testability split:
 * {@see plan()} is the pure decision (what would change, or why nothing can), and
 * {@see apply()} executes a plan then re-runs the check so the caller sees the real
 * resulting state — including the honest partial outcomes (enabling security.txt
 * with no contact leaves the check warning about the contact; that is reported, not
 * papered over).
 *
 * @package Agentimus
 */

namespace Agentimus\Abilities;

use Agentimus\Settings;
use Agentimus\Readiness;
use Agentimus\Sitemap;
use Agentimus\Paths;

defined( 'ABSPATH' ) || exit;

final class Fixer {

	/** @var Settings */
	private $settings;

	/**
	 * @param Settings $settings Settings store.
	 */
	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Decide what fixing one check would take — without changing anything.
	 *
	 * @param string $check_id A readiness check id (also the id of a readiness-sourced score action).
	 * @return array One of:
	 *   { automatable: true,  flips: array<string,bool> }               — Agentimus settings to switch on;
	 *   { automatable: true,  option: array{key:string,value:mixed} }   — a core option write (blog_public);
	 *   { automatable: false, reason: string }                          — the honest manual next step.
	 */
	public function plan( $check_id ) {
		$check_id = (string) $check_id;

		switch ( $check_id ) {
			case 'public':
				return array(
					'automatable' => true,
					'option'      => array(
						'key'   => 'blog_public',
						'value' => '1',
					),
				);

			case 'llms':
				return $this->flip( 'enable_llms_txt' );

			case 'llms_full':
				return $this->flip( 'enable_llms_full' );

			case 'schema':
				// Safe even beside an SEO plugin: Schema stands down by itself when one
				// owns the markup (see Schema::seo_plugin_active), so no duplicate risk.
				return $this->flip( 'enable_schema' );

			case 'sitemap':
				return $this->flip( 'enable_sitemap' );

			case 'ai_usage':
				return $this->flip( 'enable_ai_header', 'enable_tdmrep' );

			case 'topics':
				if ( ! $this->settings->enabled( 'enable_topics' ) ) {
					return $this->flip( 'enable_topics' );
				}
				if ( ! (bool) $this->settings->get( 'topics_derive_default', true ) ) {
					return $this->flip( 'topics_derive_default' );
				}
				return $this->manual( __( 'Topics are on and auto-filling; the remaining lift is per-post. Use write-topics to give key posts sharper, specific topics.', 'agentimus' ) );

			case 'security_txt':
				if ( ! $this->settings->enabled( 'enable_security_txt' ) ) {
					// Honest partial: with no contact configured the document stays
					// unpublished — apply() re-checks and reports exactly that.
					return $this->flip( 'enable_security_txt' );
				}
				return $this->manual( __( 'security.txt is already on but has no contact, and a contact is a human decision — the owner adds one under Settings → Security.txt (or a public contact email under Identity).', 'agentimus' ) );

			case 'robots_sitemap':
				// Mirror the check's own branches (see Readiness::check_robots_sitemap).
				if ( file_exists( Paths::site_root() . 'robots.txt' ) ) {
					return $this->manual( __( 'A static robots.txt file at the site root overrides the managed one. Only the owner can edit or delete that file; the Sitemap: line must be added there by hand.', 'agentimus' ) );
				}
				if ( ! (bool) get_option( 'blog_public', 1 ) ) {
					// Downstream of "Search engine visibility" — the real fix is that switch.
					return array(
						'automatable' => true,
						'option'      => array(
							'key'   => 'blog_public',
							'value' => '1',
						),
					);
				}
				$detected = Sitemap::detect();
				if ( '' === (string) $detected['url'] ) {
					return $this->flip( 'enable_sitemap' );
				}
				return $this->flip( 'enable_robots' );

			case 'permalinks':
				return $this->manual( __( 'Changing the permalink structure rewrites every URL on the site and can need server configuration — that stays a human decision, under Settings → Permalinks.', 'agentimus' ) );

			case 'robots':
				return $this->manual( __( 'A static robots.txt file exists at the site root. Deleting a file from the server is never something an agent should do — the owner removes it (or maintains it by hand).', 'agentimus' ) );

			case 'llms_words':
				return $this->manual( __( 'The index is thin because the site is — this needs real content, not a switch. Add a profile sentence and expertise under Settings → Identity, or publish substantive pages (create-content can draft them).', 'agentimus' ) );

			case 'llms_full_size':
				return $this->manual( __( 'Shrinking the full-text file means choosing how many posts it carries — a trade-off the owner weighs under Settings → Features (“Posts in /llms-full.txt”).', 'agentimus' ) );

			case 'about':
			case 'expertise':
			case 'same_as':
			case 'entity_role':
				return $this->manual( __( 'This is identity content only the owner can vouch for (who they are, what they know, which profiles are theirs). It is filled in under Settings → Identity, not flipped on.', 'agentimus' ) );

			case 'entity_image':
				return $this->manual( __( 'The entity image comes from the Site Icon, chosen in the Customizer (Appearance → Customize → Site Identity) — picking an image is the owner’s call.', 'agentimus' ) );

			case 'post_types':
				return $this->manual( __( 'Content coverage is informational; widening it to more post types is a privacy decision the owner makes under Settings → Content.', 'agentimus' ) );
		}

		// Score actions that are not readiness checks get pointed at the right tool
		// rather than a generic "unknown" (read-readiness's score.actions[] reuses
		// readiness ids for readiness rows, and these ids for the rest — see Score).
		if ( 0 === strpos( $check_id, 'content_' ) ) {
			return $this->manual( __( 'This is a per-page content action, not a site switch. Run check-page on that post to see what to improve, then fix it with update-content / write-topics / write-description.', 'agentimus' ) );
		}
		if ( 'visibility_failing' === $check_id || 'measure_setup' === $check_id ) {
			return $this->manual( __( 'AI Visibility runs spend the site’s own AI credits, so starting or configuring them stays with the owner (More → AI visibility).', 'agentimus' ) );
		}

		// A check id we don't recognise: either a third-party check appended via the
		// agentimus_readiness_checks filter (apply() keeps it when the row exists — its
		// own fix text is the remediation), or a typo (apply() turns it into an error).
		return array(
			'automatable' => false,
			'reason'      => __( 'Agentimus has no automated fix for this check — it comes from another plugin. Its own fix text is the remediation.', 'agentimus' ),
			'unknown'     => true,
		);
	}

	/**
	 * Apply the fix for one readiness check and report the real resulting state.
	 *
	 * @param string $check_id A readiness check id.
	 * @return array|\WP_Error {
	 *   applied: bool,        — whether anything was changed just now
	 *   changed: string[],    — the settings/options switched (empty when none)
	 *   message: string,      — what happened / what to do instead, in plain words
	 *   check:   array|null   — the check's row after applying, so the caller sees the outcome
	 * }
	 */
	public function apply( $check_id ) {
		$check_id = (string) $check_id;
		$before   = $this->find_check( $check_id );

		$plan = $this->plan( $check_id );
		if ( ! empty( $plan['unknown'] ) && null === $before ) {
			return new \WP_Error(
				'agentimus_unknown_check',
				__( 'Unknown check id. Use an id from read-readiness (checks[].id or score.actions[].id).', 'agentimus' ),
				array( 'status' => 404 )
			);
		}

		// Already passing → nothing to change; say so instead of pretending to act.
		if ( null !== $before && 'pass' === $before['status'] ) {
			return array(
				'applied' => false,
				'changed' => array(),
				'message' => __( 'Already passing — nothing to change.', 'agentimus' ),
				'check'   => $before,
			);
		}

		if ( empty( $plan['automatable'] ) ) {
			return array(
				'applied' => false,
				'changed' => array(),
				'message' => (string) $plan['reason'],
				'check'   => $before,
			);
		}

		$changed = array();

		if ( ! empty( $plan['flips'] ) ) {
			$all = $this->settings->all();
			foreach ( $plan['flips'] as $key => $value ) {
				$all[ $key ] = $value;
				$changed[]   = $key;
			}
			$this->settings->update( $all ); // Full read-modify-write, like every one-click settings action.
		}

		if ( ! empty( $plan['option'] ) ) {
			update_option( $plan['option']['key'], $plan['option']['value'] );
			$changed[] = (string) $plan['option']['key'];
		}

		$after = $this->find_check( $check_id );

		return array(
			'applied' => true,
			'changed' => $changed,
			'message' => ( null !== $after && 'pass' === $after['status'] )
				? __( 'Fix applied — the check now passes.', 'agentimus' )
				: __( 'Fix applied. The check has not fully cleared yet — see its current detail for what remains.', 'agentimus' ),
			'check'   => $after,
		);
	}

	/**
	 * The current readiness row for one check id, or null when no check carries it.
	 *
	 * @param string $check_id Check id.
	 * @return array|null
	 */
	private function find_check( $check_id ) {
		foreach ( ( new Readiness( $this->settings ) )->report() as $row ) {
			if ( isset( $row['id'] ) && $row['id'] === $check_id ) {
				return $row;
			}
		}
		return null;
	}

	/**
	 * An automatable plan flipping the named Agentimus toggles ON. Fixes only ever
	 * ENABLE a documented feature — nothing in the vocabulary switches a protection off.
	 *
	 * @param string ...$keys Settings keys to enable.
	 * @return array
	 */
	private function flip( ...$keys ) {
		$flips = array();
		foreach ( $keys as $key ) {
			$flips[ $key ] = true;
		}
		return array(
			'automatable' => true,
			'flips'       => $flips,
		);
	}

	/**
	 * A not-automatable plan with the honest manual next step.
	 *
	 * @param string $reason Plain-language reason + what to do instead.
	 * @return array
	 */
	private function manual( $reason ) {
		return array(
			'automatable' => false,
			'reason'      => $reason,
		);
	}
}

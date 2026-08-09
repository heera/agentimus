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
	 *   { automatable: true,  flips: array<string,bool> }  — Agentimus settings to switch on;
	 *   { automatable: false, reason: string }             — the honest manual next step.
	 */
	public function plan( $check_id ) {
		$check_id = (string) $check_id;

		switch ( $check_id ) {
			case 'public':
				// Never automated, even though it is a single core option: "Discourage
				// search engines" may be a deliberate privacy choice (a staging or
				// members-only site), and reversing it makes the whole site crawler-
				// visible. Turning a site public is the owner's call, full stop —
				// this is exactly the "never loosen a protection" line in action.
				return $this->manual( __( 'Making the site visible to search engines reverses a deliberate privacy choice (“Discourage search engines”, Settings → Reading) — going public stays the owner’s decision, never an agent’s.', 'agentimus' ) );

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
				// Mirror the check's own branches IN ITS ORDER (see
				// Readiness::check_robots_sitemap) so the plan always answers the same
				// warning the row is currently showing — a plan keyed to a different
				// branch would apply a remediation the check never asked for.
				$static = file_exists( Paths::site_root() . 'robots.txt' );
				if ( ! (bool) get_option( 'blog_public', 1 ) && ! $static ) {
					// Downstream of "Search engine visibility" — and going public is the
					// owner's privacy decision, same as the 'public' check above.
					return $this->manual( __( 'This follows from “Search engine visibility” being off, and making the site public is the owner’s decision (Settings → Reading) — once it is on, the Sitemap: line is emitted automatically.', 'agentimus' ) );
				}
				if ( '' === (string) Sitemap::detect()['url'] ) {
					return $this->flip( 'enable_sitemap' );
				}
				if ( $static ) {
					return $this->manual( __( 'A sitemap exists but the static robots.txt file at the site root overrides the managed one. Only the owner can edit that file; the Sitemap: line must be added there by hand.', 'agentimus' ) );
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
			return $this->manual( __( 'Citation checks spend the site’s own AI credits, so starting or configuring them stays with the owner (More → Visibility → Citations).', 'agentimus' ) );
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

		// Base the read-modify-write on the STORED option merged with defaults — NOT on
		// Settings::all(), whose result passes through the `agentimus_settings` read
		// filter: writing that back would permanently bake a site's runtime filter
		// overrides (an environment forcing a flag off, say) into the saved option.
		$stored = get_option( Settings::OPTION, array() );
		$all    = wp_parse_args( is_array( $stored ) ? $stored : array(), $this->settings->defaults() );

		// Only count a flip that actually flips. A remediation whose switch is already
		// on cannot be what this warning needs (another plugin's filter, say, is
		// overriding the output) — reporting "applied" there would send an agent into
		// a retry loop against a fix that can never bite.
		$changed = array();
		foreach ( $plan['flips'] as $key => $value ) {
			if ( (bool) ( isset( $all[ $key ] ) ? $all[ $key ] : false ) !== (bool) $value ) {
				$all[ $key ] = $value;
				$changed[]   = $key;
			}
		}

		if ( empty( $changed ) ) {
			return array(
				'applied' => false,
				'changed' => array(),
				'message' => __( 'The switch this fix flips is already on, so the warning has a different cause — see the check’s detail and fix text for what is actually in the way.', 'agentimus' ),
				'check'   => $before,
			);
		}

		$this->settings->update( $all ); // Full read-modify-write, like every one-click settings action.

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

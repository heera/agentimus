<?php
/**
 * Set-aside — the triage half of the content worklist.
 *
 * `read-content-issues` tells an agent which pages are worth fixing, and
 * update-content / write-description / write-topics / apply-fix let it fix them.
 * Until this class there was no way to say the third thing an owner says all the
 * time: **this one is fine as it is**. An agent working the list could only fix,
 * never triage — a one-directional absence, since the reads already honour the
 * owner's own set-aside list ({@see \Agentimus\Worklist::set_aside_ids()}).
 *
 * This is deliberately ONE PAGE AT A TIME, in both directions. The owner's screen
 * has two bulk actions beside this button — "Set all aside" for a whole check and
 * "Restore all" — and both sit behind a confirmation dialog that names the count,
 * because each moves dozens of pages on one click. A confirmation dialog is a
 * HUMAN gate; an agent has nobody to show it to, so the action it guards does not
 * survive the crossing. The per-page click carries no dialog because it needs
 * none: small, visible in the Set Aside count immediately, and undone by this
 * same call with aside=false. ⭐ THE RULE, and it maintains itself: an agent gets
 * the actions the owner's own UI does not confirm.
 *
 * Two-step shape, mirroring {@see Fixer}: {@see plan()} is the pure decision (may
 * this page be set aside, and would anything change) and {@see set_aside()}
 * executes a plan, then reads the list BACK to confirm the change actually landed
 * — the store caps the list at 1,000 ids and keeps the first 1,000, so an id
 * appended past the cap is dropped by the sanitiser. Reporting "saved" there
 * would be a write that silently did nothing.
 *
 * @package Agentimus
 */

namespace Agentimus\Abilities;

use Agentimus\Settings;
use Agentimus\Gradeability;
use Agentimus\Worklist;

defined( 'ABSPATH' ) || exit;

final class Triage {

	/**
	 * @var int The store's own ceiling on the set-aside list
	 *          ({@see Settings::sanitize()}), restated here only to explain
	 *          the refusal — the cap itself is enforced there, once.
	 */
	const MAX_SET_ASIDE = 1000;

	/** @var Settings */
	private $settings;

	/**
	 * @param Settings $settings Settings store.
	 */
	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Decide what setting one page aside (or putting it back) would do — without
	 * changing anything.
	 *
	 * ⭐ The two directions are judged by DIFFERENT rules, on purpose. Setting a
	 * page aside is a statement about a live page, so it requires one: published,
	 * and of a type this site actually grades. Putting one back only requires the
	 * id to be ON the list — a page parked months ago may since have been
	 * unpublished or deleted, and refusing to restore it then would strand the
	 * entry with no way to clear it.
	 *
	 * @param int  $post_id Post ID.
	 * @param bool $aside   True to set aside, false to put back.
	 * @return array One of:
	 *   { allowed: true,  change: bool, parked: int[] }   — parked is the CURRENT stored list;
	 *   { allowed: false, code: string, reason: string }  — the honest refusal.
	 */
	public function plan( $post_id, $aside ) {
		$post_id = (int) $post_id;
		$aside   = (bool) $aside;

		if ( $post_id < 1 ) {
			return $this->refuse( 'agentimus_bad_post', __( 'A post id is required. Every row read-content-issues returns carries one, as `id`.', 'agentimus' ) );
		}

		$parked = $this->parked();
		$is_in  = in_array( $post_id, $parked, true );

		if ( ! $aside ) {
			// Restoring: the list is the only thing that has to be true.
			return array(
				'allowed' => true,
				'change'  => $is_in,
				'parked'  => $parked,
			);
		}

		$post = get_post( $post_id );
		if ( ! $post instanceof \WP_Post ) {
			return $this->refuse( 'agentimus_not_found', __( 'No post with that id.', 'agentimus' ) );
		}

		if ( 'publish' !== $post->post_status ) {
			// Grading only ever reads published content, so a draft is already
			// outside the worklist. Parking it would be a decision with no effect,
			// recorded as though it had one.
			return $this->refuse(
				'agentimus_not_published',
				__( 'Only published pages are graded, so this one is already outside the worklist — there is nothing to set aside.', 'agentimus' )
			);
		}

		$types = Gradeability::post_types();
		if ( ! in_array( (string) $post->post_type, $types, true ) ) {
			return $this->refuse(
				'agentimus_not_graded',
				sprintf(
					/* translators: 1: the post's type slug, 2: comma-separated list of graded type slugs. */
					__( 'This site does not grade “%1$s” content for citability, so there is nothing to set aside. The types it grades are: %2$s.', 'agentimus' ),
					(string) $post->post_type,
					$types ? implode( ', ', $types ) : __( 'none', 'agentimus' )
				)
			);
		}

		// Room check BEFORE the write, so a full list refuses out loud rather than
		// letting the sanitiser drop the id and answering "saved".
		if ( ! $is_in && count( $parked ) >= self::MAX_SET_ASIDE ) {
			return $this->refuse(
				'agentimus_set_aside_full',
				sprintf(
					/* translators: %s: the maximum number of set-aside pages. */
					__( 'The set-aside list is full at %s pages. Put some back before setting another aside.', 'agentimus' ),
					number_format_i18n( self::MAX_SET_ASIDE )
				)
			);
		}

		return array(
			'allowed' => true,
			'change'  => ! $is_in,
			'parked'  => $parked,
		);
	}

	/**
	 * Set one page aside as "not cited content", or put it back into grading.
	 *
	 * Nothing about the page itself is touched — it stays published exactly as it
	 * is. What changes is whether the content checks judge it, and therefore the
	 * site's content score.
	 *
	 * @param int  $post_id Post ID.
	 * @param bool $aside   True to set aside, false to put back.
	 * @return array|\WP_Error {@see result()} for the shape.
	 */
	public function set_aside( $post_id, $aside ) {
		$post_id = (int) $post_id;
		$aside   = (bool) $aside;

		$plan = $this->plan( $post_id, $aside );
		if ( empty( $plan['allowed'] ) ) {
			return new \WP_Error(
				$plan['code'],
				$plan['reason'],
				array( 'status' => 'agentimus_not_found' === $plan['code'] ? 404 : 400 )
			);
		}

		// Already in the state asked for: say so instead of reporting a write that
		// did not happen.
		if ( empty( $plan['change'] ) ) {
			return $this->result(
				$post_id,
				$aside,
				false,
				$aside
					? __( 'Already set aside — nothing changed. It is not being graded.', 'agentimus' )
					: __( 'Not set aside, so there was nothing to put back. It is already being graded.', 'agentimus' )
			);
		}

		// Base the read-modify-write on the STORED option merged with defaults —
		// NOT Settings::all(), whose result passes through the `agentimus_settings`
		// read filter: writing that back would bake a site's runtime overrides into
		// the saved option. Same reasoning as Fixer::apply().
		$stored = get_option( Settings::OPTION, array() );
		$all    = wp_parse_args( is_array( $stored ) ? $stored : array(), $this->settings->defaults() );

		$list = isset( $all['optimize_ignored'] ) && is_array( $all['optimize_ignored'] )
			? array_values( array_filter( array_map( 'intval', $all['optimize_ignored'] ) ) )
			: array();
		$list = array_values( array_diff( $list, array( $post_id ) ) );
		if ( $aside ) {
			$list[] = $post_id;
		}

		$all['optimize_ignored'] = $list;
		$this->settings->update( $all ); // Sanitises + busts the OPTIMIZE cache, exactly as the owner's click does.

		// ⭐ READ IT BACK. The sanitiser is the last word on what got stored, and a
		// write whose effect it dropped must never answer "saved" — his bar: a
		// partial outcome never reports as success.
		if ( in_array( $post_id, $this->parked(), true ) !== $aside ) {
			return new \WP_Error(
				'agentimus_set_aside_failed',
				__( 'The change did not stick. Read the page back with read-content-issues before trying again.', 'agentimus' ),
				array( 'status' => 500 )
			);
		}

		return $this->result(
			$post_id,
			$aside,
			true,
			$aside
				? __( 'Set aside. It stays published exactly as it is, and no content check grades it any more.', 'agentimus' )
				: __( 'Put back. It is graded again, and will reappear on the worklist if anything about it needs fixing.', 'agentimus' )
		);
	}

	/**
	 * The one return literal, so every path answers in the same shape — including
	 * the no-op ones. SetAsideAbilityTest reads the keys out of THIS method to
	 * check the declared output schema, so a key added here is a key the schema
	 * is made to declare.
	 *
	 * @param int    $post_id Post ID.
	 * @param bool   $aside   The page's state now.
	 * @param bool   $changed Whether this call is what put it there.
	 * @param string $message Plain-language account of what happened.
	 * @return array
	 */
	private function result( $post_id, $aside, $changed, $message ) {
		return array(
			'postId'  => (int) $post_id,
			// ⛔ Through Worklist, never a bare get_the_title() — see title_of().
			// Titles come out of the database entity-encoded, and an agent that is
			// handed one name by read-content-issues and a different one here has
			// been given two names for one page.
			'title'   => Worklist::title_of( $post_id ),
			'aside'   => (bool) $aside,
			'changed' => (bool) $changed,
			'message' => (string) $message,
			// ⛔ THE SAME COUNTS read-content-issues returns, from the same owner —
			// his law: two surfaces counting the same thing read ONE count. An
			// agent that sets a page aside and then quotes a tally it derived
			// itself is the disagreement that law exists to prevent.
			'counts'  => ( new Worklist( $this->settings ) )->counts(),
		);
	}

	/** The stored set-aside list, as the reads see it. */
	private function parked() {
		$raw = (array) $this->settings->get( 'optimize_ignored', array() );
		return array_values( array_filter( array_map( 'intval', $raw ) ) );
	}

	/**
	 * A refusal carrying the reason in the owner's own language.
	 *
	 * @param string $code   Error code.
	 * @param string $reason Plain-language reason.
	 * @return array
	 */
	private function refuse( $code, $reason ) {
		return array(
			'allowed' => false,
			'code'    => $code,
			'reason'  => $reason,
		);
	}
}

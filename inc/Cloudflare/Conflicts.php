<?php
/**
 * Edge-vs-policy conflict detection — the panel's reason to exist.
 *
 * Cloudflare has no API for reading its policy settings, so nothing here reads
 * settings. Every conflict is built from OBSERVED behaviour (what the edge did
 * to known AI crawlers) compared against the owner's declared policy. That
 * makes each row evidence, not a guess — the July 2026 case this is built for
 * was Bot Fight Mode silently challenging OpenAI's fetchers while the site's
 * own policy said "allow AI reading".
 *
 * Pure — no DB, no HTTP, no time() — so it unit-tests standalone.
 *
 * @package Agentimus
 */

namespace Agentimus\Cloudflare;

defined( 'ABSPATH' ) || exit;

final class Conflicts {

	/** @var int Ignore blips: a conflict needs at least this many blocked requests… */
	const MIN_BLOCKED = 10;

	/** @var float …and at least this share of the crawler's traffic turned away. */
	const MIN_BLOCKED_SHARE = 0.2;

	/** @var int "Training crawlers keep passing" needs at least this many passes to say so. */
	const MIN_TRAINER_PASSES = 25;

	/**
	 * Crawler tokens whose purpose is collecting content for model training —
	 * the set the ai-train=no line addresses.
	 *
	 * @var string[]
	 */
	const TRAINERS = array(
		'gptbot',
		'claudebot',
		'anthropic-ai',
		'google-extended',
		'applebot-extended',
		'bytespider',
		'ccbot',
		'meta-externalagent',
		'amazonbot',
		'cohere-ai',
		'diffbot',
		'imagesiftbot',
		'omgilibot',
		'timpibot',
		'shapbot',
	);

	/**
	 * Compare observed edge behaviour with the owner's declared policy.
	 *
	 * Significance and currency are separate tests: the week's totals decide
	 * whether something is WORTH saying, but a conflict only FIRES while there
	 * is evidence in the recent window — so a warning retires within a day of
	 * the owner fixing the cause, instead of shouting for a week about history.
	 *
	 * @param array      $crawlers Per-crawler totals: { ua, name, operator, requests, cached, origin, blocked }.
	 * @param array      $policy   The owner's declarations:
	 *                             - ai_input (bool)        robots Content-Signal ai-input.
	 *                             - ai_train (bool)        robots Content-Signal ai-train.
	 *                             - blocked_agents (array) crawler tokens the OWNER deliberately blocks.
	 * @param int        $days     The window the totals cover, for the copy.
	 * @param array|null $recent   Last-24h per-crawler map { ua => { blocked, passed } }
	 *                             (see Table::recent()), or null to treat all
	 *                             evidence as current.
	 * @return array<int,array{id:string,level:string,title:string,body:string,link:string}>
	 *         Worst first: warns (a real breakage) before infos (an unenforced wish).
	 */
	public static function detect( array $crawlers, array $policy, $days, $recent = null ) {
		$out      = array();
		$days     = max( 1, (int) $days );
		$ai_input = ! isset( $policy['ai_input'] ) || false !== $policy['ai_input'];
		$ai_train = ! isset( $policy['ai_train'] ) || false !== $policy['ai_train'];
		// The owner's block list stores UA names as typed ("GPTBot"); crawler rows
		// carry lowercase tokens ("gptbot"). Compare case-insensitively or a
		// deliberate block would never be recognised as one.
		$owner_blocked = array_map( 'strtolower', array_map( 'strval', isset( $policy['blocked_agents'] ) ? (array) $policy['blocked_agents'] : array() ) );

		// ── The edge is turning away a crawler the owner allows ────────────────
		// The threshold runs PER CRAWLER, the message per operator. Bot Fight Mode
		// challenges one fetcher (ChatGPT-User) while the same company's crawler
		// (GPTBot) passes — an operator-level share would dilute the real breakage
		// below the threshold and hide it. "Cloudflare is blocking OpenAI" is
		// still the sentence an owner acts on, so qualifying crawlers roll up.
		if ( $ai_input ) {
			$per_operator = array();
			foreach ( $crawlers as $c ) {
				$token = strtolower( (string) $c['ua'] );
				if ( in_array( $token, $owner_blocked, true ) ) {
					continue; // The owner blocks this crawler on purpose — the edge agreeing is not a conflict.
				}
				// Same reasoning one level up: with ai-train=no declared, the edge
				// blocking a TRAINING crawler (e.g. Cloudflare's Training policy set
				// to Block) is the owner's wish being enforced — even for a trainer
				// the owner never named. Reading fetchers stay warn-eligible.
				if ( ! $ai_train && in_array( $token, self::TRAINERS, true ) ) {
					continue;
				}
				$blocked  = (int) $c['blocked'];
				$requests = (int) $c['requests'];
				$op       = (string) $c['operator'];
				if ( '' === $op || $blocked < self::MIN_BLOCKED || $blocked < $requests * self::MIN_BLOCKED_SHARE ) {
					continue;
				}
				// Currency: the week made it significant, but only still-happening
				// blocking fires — otherwise the fix already landed and the warning
				// would just be nagging about history.
				if ( null !== $recent && empty( $recent[ $token ]['blocked'] ) ) {
					continue;
				}
				if ( ! isset( $per_operator[ $op ] ) ) {
					$per_operator[ $op ] = array( 'blocked' => 0, 'requests' => 0 );
				}
				$per_operator[ $op ]['blocked']  += $blocked;
				$per_operator[ $op ]['requests'] += $requests;
			}

			foreach ( $per_operator as $op => $sums ) {
				$out[] = array(
					'id'    => 'edge-blocks-' . trim( strtolower( preg_replace( '/[^a-z0-9]+/i', '-', $op ) ), '-' ),
					'level' => 'warn',
					'title' => sprintf(
						/* translators: %s: AI company name (e.g. OpenAI). */
						__( 'Cloudflare is blocking %s, but your policy says allow', 'agentimus' ),
						$op
					),
					'body'  => sprintf(
						/* translators: 1: blocked request count, 2: total request count, 3: AI company name, 4: number of days. */
						__( 'Cloudflare turned away %1$s of %2$s requests from %3$s crawlers in the last %4$d days — including some in the last day. Your site policy allows AI reading — so an assistant that tries to read a page gets an error, and your request log never shows it. Either allow it at Cloudflare, or accept that %3$s cannot read this site. Once the blocking stops, this warning clears by itself within a day.', 'agentimus' ),
						number_format_i18n( $sums['blocked'] ),
						number_format_i18n( $sums['requests'] ),
						$op,
						$days
					),
					'link'  => 'bots',
				);
			}
		}

		// ── ai-train=no declared, but training crawlers pass the edge freely ───
		// The robots line is advisory; the edge is where it could be enforced.
		if ( ! $ai_train ) {
			$passes        = 0;
			$recent_passes = 0;
			foreach ( $crawlers as $c ) {
				if ( in_array( (string) $c['ua'], self::TRAINERS, true ) ) {
					$passes += (int) $c['cached'] + (int) $c['origin'];
					if ( null !== $recent && ! empty( $recent[ (string) $c['ua'] ]['passed'] ) ) {
						$recent_passes += (int) $recent[ (string) $c['ua'] ]['passed'];
					}
				}
			}
			// Same currency rule as above: once the edge starts enforcing (e.g. the
			// owner set Training to Block), passes stop — and so does this notice,
			// within a day, instead of reciting the week's history.
			if ( null !== $recent && 0 === $recent_passes ) {
				$passes = 0;
			}
			if ( $passes >= self::MIN_TRAINER_PASSES ) {
				$out[] = array(
					'id'    => 'train-not-enforced',
					'level' => 'info',
					'title' => __( 'Cloudflare is letting training crawlers through', 'agentimus' ),
					'body'  => sprintf(
						/* translators: 1: request count, 2: number of days. */
						__( 'Your site asks AI companies not to use your content for training (the ai-train=no line in robots.txt). Cloudflare is letting training crawlers through anyway — %1$s requests in the last %2$d days, including some in the last day. Asking is not stopping: polite crawlers obey, the rest ignore you. Cloudflare is the one who can stop them — block Training crawlers there, and this notice clears by itself within a day.', 'agentimus' ),
						number_format_i18n( $passes ),
						$days
					),
					'link'  => 'ai-crawlers',
				);
			}
		}

		return $out;
	}
}

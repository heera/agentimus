<?php
/**
 * Weekly digest email renderer. Pure: takes the collected data array and
 * returns subject/HTML strings. No queries, no options, no side effects — so a
 * unit test can feed it fixtures and assert on the exact output.
 *
 * Copy rules (house): plain language, short sentences, no idioms, no
 * exclamation marks. Every dynamic value escaped. No images anywhere — nothing
 * external may load from the email, ever.
 *
 * @package Agentimus
 */

namespace Agentimus\Digest;

defined( 'ABSPATH' ) || exit;

final class Renderer {

	/** Accent — the plugin's green. */
	const ACCENT = '#2f7a4c';

	/** Top clients / sources listed per section. */
	const TOP_ROWS = 5;

	/**
	 * Quiet-week gate. False = do not send. A week counts as quiet when nothing
	 * happened: no agent reads, no referral visits, no agent-access events, and
	 * the score did not move since the last digest. An unknown previous score
	 * (first send) counts as "did not move" — the first email should carry
	 * news, not a lone number.
	 *
	 * @param array $data Collected digest data.
	 * @return bool Whether there is anything worth sending.
	 */
	public static function should_send( array $data ) {
		$agents    = (int) ( $data['agents']['total'] ?? 0 );
		$referrals = (int) ( $data['referrals']['total'] ?? 0 );
		$access    = (int) ( $data['access']['events'] ?? 0 );
		$score     = isset( $data['score'] ) && is_array( $data['score'] ) ? $data['score'] : array();
		$moved     = isset( $score['prev'], $score['now'] ) && null !== $score['prev'] && null !== $score['now'] && (int) $score['prev'] !== (int) $score['now'];
		$robots    = ! empty( $data['robots']['change'] ); // A policy change is news even on a quiet week.
		return $agents > 0 || $referrals > 0 || $access > 0 || $moved || $robots;
	}

	/**
	 * Subject line. Leads with the single most interesting number.
	 *
	 * @param array $data Collected digest data.
	 * @return string
	 */
	public static function subject( array $data ) {
		$agents = (int) ( $data['agents']['total'] ?? 0 );
		$score  = isset( $data['score']['now'] ) && null !== $data['score']['now'] ? (int) $data['score']['now'] : null;

		if ( $agents > 0 && null !== $score ) {
			return sprintf(
				/* translators: 1: number of AI requests this week, 2: readiness score. */
				__( 'Your site’s AI week: %1$s AI reads, score %2$d', 'agentimus' ),
				number_format_i18n( $agents ),
				$score
			);
		}
		if ( $agents > 0 ) {
			return sprintf(
				/* translators: %s: number of AI requests this week. */
				__( 'Your site’s AI week: %s AI reads', 'agentimus' ),
				number_format_i18n( $agents )
			);
		}
		return __( 'Your site’s AI week', 'agentimus' );
	}

	/**
	 * The email body.
	 *
	 * @param array $data Collected digest data.
	 * @return string HTML.
	 */
	public static function html( array $data ) {
		$out  = self::open( $data );
		$out .= self::section_agents( $data );
		$out .= self::section_referrals( $data );
		$out .= self::section_impostors( $data );
		$out .= self::section_edge( $data );
		$out .= self::section_robots( $data );
		$out .= self::section_access( $data );
		$out .= self::section_score( $data );
		$out .= self::section_nudge( $data );
		$out .= self::footer( $data );
		return $out;
	}

	/* ------------------------------------------------------------------ */

	/** Head + intro. On the first-ever digest, one line explains why it exists. */
	private static function open( array $data ) {
		$site   = (string) ( $data['site_name'] ?? '' );
		$period = isset( $data['period'] ) && is_array( $data['period'] ) ? $data['period'] : array();
		$range  = isset( $period['label'] ) ? (string) $period['label'] : '';

		$h  = '<div style="margin:0;padding:24px 12px;background:#f6f7f7;">';
		$h .= '<table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr><td align="center">';
		$h .= '<table role="presentation" width="560" cellpadding="0" cellspacing="0" style="max-width:560px;width:100%;background:#ffffff;border:1px solid #e0e0e0;border-radius:8px;">';
		$h .= '<tr><td style="padding:28px 32px 8px;font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,sans-serif;">';
		$h .= '<p style="margin:0 0 4px;font-size:13px;color:#646970;">' . esc_html( $site ) . ( '' !== $range ? ' · ' . esc_html( $range ) : '' ) . '</p>';
		$h .= '<h1 style="margin:0;font-size:20px;line-height:1.3;color:#1d2327;">' . esc_html__( 'What AI did on your site this week', 'agentimus' ) . '</h1>';

		if ( ! empty( $data['first'] ) ) {
			$h .= '<p style="margin:12px 0 0;font-size:14px;line-height:1.6;color:#3c434a;">'
				. esc_html__( 'This is the first weekly note from Agentimus. It arrives once a week, and only when there is something to report. One click at the bottom stops it for good.', 'agentimus' )
				. '</p>';
		}
		$h .= '</td></tr>';
		return $h;
	}

	/** Agent reads + top clients. A zero section stays out — a zero row is noise. */
	private static function section_agents( array $data ) {
		$a     = isset( $data['agents'] ) && is_array( $data['agents'] ) ? $data['agents'] : array();
		$total = (int) ( $a['total'] ?? 0 );
		if ( 0 === $total ) {
			return '';
		}
		$line = sprintf(
			/* translators: %s: number of requests from AI assistants and crawlers. */
			_n( 'AI assistants and crawlers read your site %s time.', 'AI assistants and crawlers read your site %s times.', $total, 'agentimus' ),
			number_format_i18n( $total )
		);
		$body = self::stat_row( $line, self::compare( $total, isset( $a['prev'] ) ? $a['prev'] : null ) );

		$clients = array_slice( (array) ( $a['by_client'] ?? array() ), 0, self::TOP_ROWS );
		if ( $clients ) {
			$body .= '<table role="presentation" cellpadding="0" cellspacing="0" style="margin:8px 0 0;font-size:13px;color:#3c434a;">';
			foreach ( $clients as $c ) {
				$body .= '<tr><td style="padding:2px 12px 2px 0;">' . esc_html( (string) ( $c['label'] ?? '' ) ) . '</td>'
					. '<td style="padding:2px 0;text-align:right;">' . esc_html( number_format_i18n( (int) ( $c['hits'] ?? 0 ) ) ) . '</td></tr>';
			}
			$body .= '</table>';
		}
		return self::section( __( 'AI reads', 'agentimus' ), $body );
	}

	/** Humans arriving from AI answers. */
	private static function section_referrals( array $data ) {
		$r     = isset( $data['referrals'] ) && is_array( $data['referrals'] ) ? $data['referrals'] : array();
		$total = (int) ( $r['total'] ?? 0 );
		if ( 0 === $total ) {
			return '';
		}
		$line = sprintf(
			/* translators: %s: number of visitors who arrived from an AI assistant. */
			_n( '%s visitor arrived from an AI answer.', '%s visitors arrived from AI answers.', $total, 'agentimus' ),
			number_format_i18n( $total )
		);
		$body = self::stat_row( $line, self::compare( $total, isset( $r['prev'] ) ? $r['prev'] : null ) );

		$names = array();
		foreach ( array_slice( (array) ( $r['by_source'] ?? array() ), 0, self::TOP_ROWS ) as $s ) {
			$name = (string) ( $s['label'] ?? '' );
			if ( '' !== $name ) {
				$names[] = $name;
			}
		}
		if ( $names ) {
			$body .= '<p style="margin:8px 0 0;font-size:13px;color:#3c434a;">'
				. esc_html( sprintf(
					/* translators: %s: comma-separated list of AI assistants. */
					__( 'Sent by: %s', 'agentimus' ),
					implode( ', ', $names )
				) )
				. '</p>';
		}
		return self::section( __( 'People from AI answers', 'agentimus' ), $body );
	}

	/** Requests that claimed a known crawler and failed its operator's identity check. */
	private static function section_impostors( array $data ) {
		$total = (int) ( $data['impostors']['total'] ?? 0 );
		if ( 0 === $total ) {
			return '';
		}
		$line = sprintf(
			/* translators: %s: number of requests that faked a known crawler. */
			_n( '%s request pretended to be a known crawler and failed the identity check.', '%s requests pretended to be a known crawler and failed the identity check.', $total, 'agentimus' ),
			number_format_i18n( $total )
		);
		return self::section( __( 'Impostors caught', 'agentimus' ), self::stat_row( $line, '' ) );
	}

	/**
	 * What the CDN is doing to AI crawlers, when it contradicts the site's own
	 * declared policy — absent unless a conflict is live right now.
	 *
	 * ⭐ IT NAMES THE AGE. A conflict that started three weeks ago and one that
	 * started yesterday call for different reactions, and the email is often the
	 * first the owner hears of either.
	 */
	private static function section_edge( array $data ) {
		$conflicts = (array) ( $data['edge']['conflicts'] ?? array() );
		if ( ! $conflicts ) {
			return '';
		}
		$rows = '';
		foreach ( $conflicts as $c ) {
			$since = (string) ( $c['sinceText'] ?? '' );
			$line  = (string) ( $c['title'] ?? '' );
			if ( '' !== $since ) {
				$line .= ' — ' . $since;
			}
			$rows .= self::stat_row( $line, '' );
		}
		return self::section( __( 'At your CDN', 'agentimus' ), $rows );
	}

	/** A robots.txt policy change this period — absent when nothing changed. */
	private static function section_robots( array $data ) {
		$change = isset( $data['robots']['change'] ) && is_array( $data['robots']['change'] ) ? $data['robots']['change'] : null;
		if ( null === $change ) {
			return '';
		}
		$line = sprintf(
			/* translators: 1: date, 2: lines added, 3: lines removed. */
			__( 'Your robots.txt crawler rules changed on %1$s (%2$d lines added, %3$d removed). If that was not you, check your recently activated plugins.', 'agentimus' ),
			wp_date( get_option( 'date_format', 'F j, Y' ), (int) $change['at'] ),
			count( (array) $change['added'] ),
			count( (array) $change['removed'] )
		);
		// The email is this notice's only surface (it renders no readiness row),
		// so it carries the WHICH, not just the how-many.
		$detail = array();
		if ( ! empty( $change['added'] ) ) {
			/* translators: %s: the added lines, e.g. "user-agent: GPTBot; disallow: /". */
			$detail[] = sprintf( __( 'New: %s.', 'agentimus' ), \Agentimus\RobotsWatch::excerpt( (array) $change['added'] ) );
		}
		if ( ! empty( $change['removed'] ) ) {
			/* translators: %s: the removed lines. */
			$detail[] = sprintf( __( 'Gone: %s.', 'agentimus' ), \Agentimus\RobotsWatch::excerpt( (array) $change['removed'] ) );
		}
		return self::section( __( 'robots.txt changed', 'agentimus' ), self::stat_row( $line, implode( ' ', $detail ) ) );
	}

	/** Authenticated agent activity. The store is event-keyed, so say "events", never "calls". */
	private static function section_access( array $data ) {
		$events = (int) ( $data['access']['events'] ?? 0 );
		if ( 0 === $events ) {
			return '';
		}
		$line = sprintf(
			/* translators: %s: number of authenticated assistant activity events. */
			_n( 'Your connected assistants were active this week: %s event.', 'Your connected assistants were active this week: %s events.', $events, 'agentimus' ),
			number_format_i18n( $events )
		);
		return self::section( __( 'Connected assistants', 'agentimus' ), self::stat_row( $line, '' ) );
	}

	/** Readiness score + movement since the last digest. */
	private static function section_score( array $data ) {
		$s = isset( $data['score'] ) && is_array( $data['score'] ) ? $data['score'] : array();
		if ( ! isset( $s['now'] ) || null === $s['now'] ) {
			return '';
		}
		$now  = (int) $s['now'];
		$prev = isset( $s['prev'] ) && null !== $s['prev'] ? (int) $s['prev'] : null;

		$big = '<span style="font-size:32px;font-weight:600;color:' . esc_attr( self::ACCENT ) . ';">' . esc_html( (string) $now ) . '</span>'
			. '<span style="font-size:14px;color:#646970;"> / 100</span>';

		if ( null === $prev ) {
			$note = esc_html__( 'This is the first digest, so there is no earlier score to compare against yet. Next week this line shows the change.', 'agentimus' );
		} elseif ( $now > $prev ) {
			/* translators: %d: the score in the previous digest. */
			$note = esc_html( sprintf( __( 'Up from %d last week.', 'agentimus' ), $prev ) );
		} elseif ( $now < $prev ) {
			/* translators: %d: the score in the previous digest. */
			$note = esc_html( sprintf( __( 'Down from %d last week.', 'agentimus' ), $prev ) );
		} else {
			$note = esc_html__( 'Unchanged since last week.', 'agentimus' );
		}

		$body = '<p style="margin:0;">' . $big . '</p><p style="margin:6px 0 0;font-size:13px;color:#3c434a;">' . $note . '</p>';
		return self::section( __( 'AI readiness score', 'agentimus' ), $body );
	}

	/** One actionable nudge — the current top-ranked improvement, if any. */
	private static function section_nudge( array $data ) {
		$n = isset( $data['nudge'] ) && is_array( $data['nudge'] ) ? $data['nudge'] : array();
		if ( empty( $n['label'] ) ) {
			return '';
		}
		$body = '<p style="margin:0;font-size:14px;line-height:1.6;color:#3c434a;"><strong>' . esc_html( (string) $n['label'] ) . '</strong>';
		if ( ! empty( $n['detail'] ) ) {
			$body .= '<br>' . esc_html( (string) $n['detail'] );
		}
		$body .= '</p>';
		return self::section( __( 'One thing worth doing', 'agentimus' ), $body );
	}

	/** Dashboard button + the always-present stop link. */
	private static function footer( array $data ) {
		$links     = isset( $data['links'] ) && is_array( $data['links'] ) ? $data['links'] : array();
		$dashboard = (string) ( $links['dashboard'] ?? '' );
		$stop      = (string) ( $links['stop'] ?? '' );

		$f = '<tr><td style="padding:20px 32px 8px;font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,sans-serif;">';
		if ( '' !== $dashboard ) {
			$f .= '<a href="' . esc_url( $dashboard ) . '" style="display:inline-block;padding:9px 18px;background:' . esc_attr( self::ACCENT ) . ';color:#ffffff;text-decoration:none;border-radius:4px;font-size:14px;">'
				. esc_html__( 'Open the dashboard', 'agentimus' ) . '</a>';
		}
		$f .= '</td></tr>';

		$f .= '<tr><td style="padding:12px 32px 24px;font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,sans-serif;">';
		$f .= '<p style="margin:0;font-size:12px;line-height:1.6;color:#646970;">'
			. esc_html__( 'Agentimus sends this note once a week, built only from data stored on your own site. Nothing leaves your server except this email.', 'agentimus' );
		if ( '' !== $stop ) {
			$f .= ' <a href="' . esc_url( $stop ) . '" style="color:#646970;">' . esc_html__( 'Stop these emails', 'agentimus' ) . '</a>';
		}
		$f .= '</p></td></tr>';

		$f .= '</table></td></tr></table></div>';
		return $f;
	}

	/* ------------------------------------------------------------------ */

	/** A titled section row. */
	private static function section( $title, $body ) {
		return '<tr><td style="padding:16px 32px 4px;font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,sans-serif;">'
			. '<h2 style="margin:0 0 6px;font-size:11px;letter-spacing:0.06em;text-transform:uppercase;color:#646970;">' . esc_html( $title ) . '</h2>'
			. $body
			. '</td></tr>';
	}

	/** A lead stat line with an optional comparison note under it. */
	private static function stat_row( $line, $compare ) {
		$html = '<p style="margin:0;font-size:15px;line-height:1.5;color:#1d2327;">' . esc_html( $line ) . '</p>';
		if ( '' !== $compare ) {
			$html .= '<p style="margin:4px 0 0;font-size:13px;color:#646970;">' . esc_html( $compare ) . '</p>';
		}
		return $html;
	}

	/**
	 * Plain-words comparison against the prior week. NULL prev = no full prior
	 * week to compare against (first digest, or retention shorter than two
	 * weeks) — say nothing rather than guess.
	 *
	 * @param int      $now  This week's count.
	 * @param int|null $prev Prior week's count, or null when unknowable.
	 * @return string
	 */
	private static function compare( $now, $prev ) {
		if ( null === $prev ) {
			return '';
		}
		$prev = (int) $prev;
		if ( $now > $prev ) {
			/* translators: %s: the prior week's count. */
			return sprintf( __( 'Up from %s the week before.', 'agentimus' ), number_format_i18n( $prev ) );
		}
		if ( $now < $prev ) {
			/* translators: %s: the prior week's count. */
			return sprintf( __( 'Down from %s the week before.', 'agentimus' ), number_format_i18n( $prev ) );
		}
		return __( 'Same as the week before.', 'agentimus' );
	}
}

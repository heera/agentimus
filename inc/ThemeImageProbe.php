<?php
/**
 * Theme image probe — what the SERVED page does with a featured image's
 * description, learned once instead of guessed on every page.
 *
 * ⭐⭐ WHY. The featured image is drawn by the theme, not by the content, so
 * nothing Agentimus parses can see how it reaches a reader. Two cheap routes
 * were tried and both lie: reading `_wp_attachment_image_alt` says what the
 * MEDIA LIBRARY holds, not what the page serves, and calling
 * `get_the_post_thumbnail()` from wp-admin returns what the admin request
 * renders — the-alpha substitutes the post title inline in `single.php`, not
 * through a filter, so that call comes back with alt="" for a picture the
 * public page describes perfectly well. 1.37.0 therefore shipped the narrowed
 * claim it could prove: "no description OF ITS OWN".
 *
 * The full claim needs the served HTML — and that is one HTTP request, which no
 * check may make: this runs on every editor load and inside a sweep of twenty
 * pages. So the request moves out of the read path entirely, the way
 * {@see RouteProbe} moved the llms.txt self-check out of readiness.
 *
 * ⭐ AND IT IS ASKED ONCE, NOT PER PAGE. How a theme renders a featured image's
 * alt is a fact about the THEME, not about each post — so two sample pages
 * answer it for a site of three thousand:
 *
 *   • one post whose library alt is SET     → does the theme use it, or ignore it?
 *   • one post whose library alt is EMPTY   → what does it fall back to?
 *
 * That pair separates the four states that matter, including the one nobody
 * could see before: a theme that IGNORES a description the owner wrote, which
 * the old check passed as fine.
 *
 * ⛔ Fail-open, like every probe here. No answer means the checks fall back to
 * the narrowed claim — never to a worse verdict about somebody's page on the
 * strength of a fetch that did not happen.
 *
 * @package Agentimus
 */

namespace Agentimus;

defined( 'ABSPATH' ) || exit;

final class ThemeImageProbe {

	/** Stored summary (option; autoload off — only the checks read it). */
	const OPTION = 'agentimus_theme_image_probe';

	/** Filter tag on data() — the seam tests and site owners override. */
	const FILTER = 'agentimus_theme_image_probe';

	/**
	 * The one-off cron event that runs the loopback fetches.
	 *
	 * ⚠️ Actions and filters share ONE hook namespace, so this name must never
	 * equal FILTER — apply_filters(FILTER) would invoke the cron-registered
	 * refresh() and recurse without end. Locked by the test of the same name.
	 */
	const CRON = 'agentimus_theme_image_probe_refresh';

	/** Age after which the answer counts as stale and a refresh is queued. */
	const STALE_AFTER = 7 * DAY_IN_SECONDS;

	/** Per-response body cap. A single post page lives well below this. */
	const MAX_BYTES = 512000;

	/** What the theme serves as the featured image's alt. */
	const USES_LIBRARY = 'library'; // The description the owner wrote.
	const USES_TITLE   = 'title';   // The post's own title, standing in for one.
	const USES_NOTHING = 'none';    // alt="", or no alt attribute at all.
	const UNKNOWN      = '';        // Not probed, or the picture was not found on the page.

	/**
	 * Hook the cron handler, and re-ask whenever the answer could have changed.
	 *
	 * A theme switch is the obvious one. A plugin coming or going matters too:
	 * an SEO or accessibility plugin can filter `wp_get_attachment_image_attributes`
	 * and start (or stop) supplying the alt the theme prints.
	 *
	 * @return void
	 */
	public static function watch() {
		add_action( self::CRON, array( self::class, 'refresh' ) );
		add_action( 'switch_theme', array( self::class, 'schedule_soon' ) );
		add_action( 'activated_plugin', array( self::class, 'schedule_soon' ) );
		add_action( 'deactivated_plugin', array( self::class, 'schedule_soon' ) );
	}

	/**
	 * Queue the one-off probe event, if it is not already queued.
	 *
	 * @return void
	 */
	public static function schedule_soon() {
		if ( ! wp_next_scheduled( self::CRON ) ) {
			wp_schedule_single_event( time() + MINUTE_IN_SECONDS, self::CRON );
		}
	}

	/**
	 * Queue a refresh when there is no answer yet, or the one we have is stale.
	 * Called from the grading sweep — which reads whatever is stored and never
	 * waits for this.
	 *
	 * @return void
	 */
	public static function maybe_schedule() {
		$data = self::data();
		if ( null === $data || ( time() - (int) $data['checked_at'] ) > self::STALE_AFTER ) {
			self::schedule_soon();
		}
	}

	/**
	 * Clear the queued event (deactivation).
	 *
	 * @return void
	 */
	public static function clear_schedule() {
		wp_clear_scheduled_hook( self::CRON );
	}

	/**
	 * The stored answer, or null before the first successful probe.
	 *
	 * Shape: {
	 *   checked_at: int,
	 *   error:      string,  // last fetch error, '' when the run was clean
	 *   theme:      string,  // the stylesheet this answer describes
	 *   described:  string,  // what a page serves when the library alt IS set
	 *   bare:       string,  // …and when it is not
	 * }
	 *
	 * @return array|null
	 */
	public static function data() {
		$raw = get_option( self::OPTION, null );
		$raw = is_array( $raw ) && isset( $raw['checked_at'] ) ? $raw : null;

		// An answer about a theme this site no longer runs is not an answer.
		if ( is_array( $raw ) && isset( $raw['theme'] ) && (string) $raw['theme'] !== (string) get_stylesheet() ) {
			$raw = null;
		}

		/**
		 * Override or inspect the stored theme-image answer. Tests inject states
		 * here; a site owner can force null to put every featured-image row back
		 * to the claim that needs no fetch.
		 *
		 * @param array|null $raw The stored answer.
		 */
		return apply_filters( self::FILTER, $raw );
	}

	/**
	 * A short, stable string standing for "what this theme does with alt text".
	 *
	 * ⭐ It belongs in {@see PageCheck::ruleset()}, and that is the whole reason
	 * it exists as its own method: this answer decides what the featured-image
	 * check SAYS, so when it changes — a theme switch, an accessibility plugin
	 * switched on — every stored verdict about a featured image was made under a
	 * different rule and the site has to be read again. Without that, an owner
	 * could fix their theme and keep the old complaints for ever.
	 *
	 * @return string
	 */
	public static function signature() {
		$data = self::data();
		if ( null === $data ) {
			return '';
		}
		return 'described=' . (string) $data['described'] . ';bare=' . (string) $data['bare'];
	}

	/**
	 * Run the loopback fetches and store the answer. Cron context only.
	 *
	 * @return void
	 */
	public static function refresh() {
		$answer = array(
			'checked_at' => time(),
			'error'      => '',
			'theme'      => (string) get_stylesheet(),
			'described'  => self::UNKNOWN,
			'bare'       => self::UNKNOWN,
		);

		$token = Activity\Owner::mint_probe_token();

		foreach ( array( 'described' => true, 'bare' => false ) as $slot => $want_alt ) {
			$post = self::sample( $want_alt );
			if ( ! $post ) {
				continue; // A site with no such post simply has no answer for that half.
			}
			$thumb = (int) get_post_thumbnail_id( $post );
			$body  = self::fetch( (string) get_permalink( $post ), $token );
			if ( ! is_array( $body ) ) {
				$answer['error'] = (string) $body;
				continue;
			}
			if ( $body['code'] < 200 || $body['code'] >= 300 ) {
				$answer['error'] = 'HTTP ' . $body['code'];
				continue;
			}
			$served = self::alt_for_attachment( $body['body'], $thumb );
			if ( null === $served ) {
				continue; // The picture was not found on its own page — say nothing.
			}
			$answer[ $slot ] = self::classify(
				$served,
				(string) get_post_meta( $thumb, '_wp_attachment_image_alt', true ),
				(string) $post->post_title
			);
		}

		update_option( self::OPTION, $answer, false );
	}

	/**
	 * What one served alt means, against the two strings it could have come from.
	 *
	 * @param string $served  The alt the page actually printed.
	 * @param string $library The description in the media library.
	 * @param string $title   The post's title.
	 * @return string One of the USES_* constants.
	 */
	public static function classify( $served, $library, $title ) {
		// ⚠️ NORMALISED, and it took a real page to learn why. the-alpha serves
		// the post title as the alt — and WordPress texturizes what it prints, so
		// "Madonna - Frozen" leaves the database with a hyphen and reaches the
		// page as "Madonna &#8211; Frozen". Compared byte for byte, the title
		// does not match the title, and the probe concluded the theme was
		// describing pictures with words of its own.
		$served  = self::normalize( $served );
		$library = self::normalize( $library );
		$title   = self::normalize( $title );

		if ( '' === $served ) {
			return self::USES_NOTHING;
		}
		// ⛔ The TITLE is asked first. A library description that happens to equal
		// the post title answers nothing about which one the theme reached for,
		// and calling that "uses the library" is the more flattering of two
		// guesses — {@see sample()} avoids the case, and this is the backstop.
		if ( '' !== $title && 0 === strcasecmp( $served, $title ) ) {
			return self::USES_TITLE;
		}
		if ( '' !== $library && 0 === strcasecmp( $served, $library ) ) {
			return self::USES_LIBRARY;
		}
		// Something else entirely — a caption, a filename, a theme's own words.
		// Not nothing, and not the two things we can name, so it is treated as a
		// description: ⛔ never accuse a page of an emptiness we did not see.
		return self::USES_LIBRARY;
	}

	/**
	 * One string, as the page would have said it — entities decoded and
	 * WordPress's own typography put back to the plain characters an author
	 * typed. Comparing a served alt to a stored title without this compares the
	 * output of wptexturize() to its input.
	 *
	 * @param string $text Either side of the comparison.
	 * @return string
	 */
	private static function normalize( $text ) {
		$text = html_entity_decode( (string) $text, ENT_QUOTES, 'UTF-8' );
		$text = strtr(
			$text,
			array(
				"\xE2\x80\x93" => '-',  // en dash
				"\xE2\x80\x94" => '-',  // em dash
				"\xE2\x80\x98" => "'",  // curly single quotes
				"\xE2\x80\x99" => "'",
				"\xE2\x80\x9C" => '"',  // curly double quotes
				"\xE2\x80\x9D" => '"',
				"\xE2\x80\xA6" => '...',
				"\xC2\xA0"      => ' ',  // non-breaking space
			)
		);
		return trim( preg_replace( '/\s+/', ' ', $text ) );
	}

	/**
	 * The alt an attachment's image carries in a served page, or null when the
	 * picture is not on it.
	 *
	 * ⚠️ Matched by the attachment's FILE STEM, because the `src` on the page is
	 * almost never the original: WordPress serves `photo-1024x683.jpg` for
	 * `photo.jpg`, and a CDN may rewrite the host as well. The stem survives all
	 * of that, and the size suffix is what the pattern allows for.
	 *
	 * @param string $html   The served page.
	 * @param int    $thumb  Attachment ID.
	 * @return string|null
	 */
	public static function alt_for_attachment( $html, $thumb ) {
		$url = (string) wp_get_attachment_url( (int) $thumb );
		if ( '' === $url || '' === (string) $html ) {
			return null;
		}
		$file = pathinfo( wp_parse_url( $url, PHP_URL_PATH ), PATHINFO_FILENAME );
		if ( '' === (string) $file ) {
			return null;
		}

		if ( ! preg_match_all( '/<img\b[^>]*>/i', $html, $tags ) ) {
			return null;
		}
		$stem = preg_quote( (string) $file, '/' );
		foreach ( $tags[0] as $tag ) {
			// The same file, with or without WordPress's size suffix.
			if ( ! preg_match( '/(?:src|srcset)="[^"]*' . $stem . '(?:-\d+x\d+)?\.[a-z0-9]+/i', $tag ) ) {
				continue;
			}
			if ( preg_match( '/\balt="([^"]*)"/i', $tag, $alt ) ) {
				return html_entity_decode( $alt[1], ENT_QUOTES, 'UTF-8' );
			}
			return ''; // The image is there and carries no alt attribute at all.
		}
		return null;
	}

	/**
	 * One published post with a featured image, chosen by whether that image has
	 * a description of its own.
	 *
	 * @param bool $with_alt Want the sample WITH a library description.
	 * @return \WP_Post|null
	 */
	private static function sample( $with_alt ) {
		$posts = get_posts(
			array(
				'post_type'        => 'any',
				'post_status'      => 'publish',
				'posts_per_page'   => 25,
				'orderby'          => 'modified',
				'order'            => 'DESC',
				'meta_key'         => '_thumbnail_id', // phpcs:ignore WordPress.DB.SlowDBQuery -- bounded, cron-only.
				'suppress_filters' => false,
				'no_found_rows'    => true,
			)
		);
		foreach ( $posts as $post ) {
			$thumb = (int) get_post_thumbnail_id( $post );
			if ( ! $thumb ) {
				continue;
			}
			$alt = trim( (string) get_post_meta( $thumb, '_wp_attachment_image_alt', true ) );
			// ⛔ For the described half, a library alt that merely REPEATS the post
			// title is no evidence at all: whichever the theme reached for, the
			// served words are the same. Seen on the first real page probed, where
			// the alt was the title verbatim.
			if ( $with_alt && '' !== $alt && 0 !== strcasecmp( $alt, (string) $post->post_title ) ) {
				return $post;
			}
			if ( ! $with_alt && '' === $alt ) {
				return $post;
			}
		}
		return null;
	}

	/**
	 * One loopback fetch, carrying the self-check token so the site's own
	 * activity log skips it.
	 *
	 * @param string $url   Absolute URL on this site.
	 * @param string $token This run's self-check token.
	 * @return array{code:int,body:string}|string Response, or an error sentence.
	 */
	private static function fetch( $url, $token ) {
		$response = wp_remote_get(
			$url,
			array(
				'timeout'             => 8,
				'redirection'         => 2,
				'limit_response_size' => self::MAX_BYTES,
				'user-agent'          => 'Agentimus/' . AGENTIMUS_VERSION . ' self-check',
				'headers'             => array( 'X-Agentimus-Selfcheck' => $token ),
			)
		);
		if ( is_wp_error( $response ) ) {
			return $response->get_error_message();
		}
		return array(
			'code' => (int) wp_remote_retrieve_response_code( $response ),
			'body' => (string) wp_remote_retrieve_body( $response ),
		);
	}
}

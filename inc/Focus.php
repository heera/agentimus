<?php
/**
 * Focus — what this page is for, chosen from the searches that already reach it.
 *
 * Every SEO plugin has a focus keyword: you type a guess, and the plugin grades
 * the page against your guess. The field is familiar and worth keeping. What is
 * worth replacing is where the word comes from — Search Console already knows
 * what people typed to arrive here, so the guess is unnecessary on any page that
 * has been found even once.
 *
 * So the field offers the page's real searches, each with the position and
 * traffic it already earns, and the author promotes one. A page with no data yet
 * keeps the typed field, because a new post has to be able to say what it is for
 * before anyone has looked for it.
 *
 * Whatever is chosen is then measured — not scored. {@see Search\Coverage} says
 * whether one passage of the page answers that search, names the heading it
 * found it under, and says plainly when it found nothing. A number out of a
 * hundred would hide all of that.
 *
 * Renders as the first section of the shared "Search & AI" box: it is the
 * context for the title and description beneath it, not a footnote to them.
 *
 * @package Agentimus
 */

namespace Agentimus;

use Agentimus\Search\Coverage;
use Agentimus\Search\Standing;

defined( 'ABSPATH' ) || exit;

final class Focus {

	/** @var string Post meta: the search this page is judged against. */
	const META = '_agentimus_focus';

	/** @var string Nonce for the meta-box half. */
	const NONCE = 'agentimus_focus_meta';

	/** @var int Longest focus accepted. A search, not a sentence. */
	const MAX_LEN = 120;

	/** @var int Searches offered as choices before the list is cut. */
	const MAX_CHOICES = 5;

	/** @var Settings */
	private $settings;

	/**
	 * @param Settings $settings Plugin settings.
	 */
	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Hook the save. The field itself is drawn by the shared box
	 * ({@see Topics::render_meta_box()}), which owns the container.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'init', array( __CLASS__, 'register_meta' ) );
		if ( is_admin() ) {
			add_action( 'save_post', array( $this, 'save' ), 10, 2 );
			add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_live' ) );
		}
		add_action( 'rest_api_init', array( $this, 'routes' ) );
	}

	/**
	 * REST: POST /focus/check — re-measure without saving.
	 *
	 * READ ONLY, and that is what makes it safe in a box that is otherwise
	 * deliberately no-JS. The rule this box follows is about WRITES: a meta box
	 * that saves through REST can be clobbered by the form POST Gutenberg fires
	 * straight afterwards. Nothing here writes anything — it measures text the
	 * caller already has against a focus they just typed, and returns markup.
	 *
	 * @return void
	 */
	public function routes() {
		register_rest_route(
			'agentimus/v1',
			'/focus/check',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'rest_check' ),
				'permission_callback' => static function ( $request ) {
					return current_user_can( 'edit_post', (int) $request->get_param( 'post' ) );
				},
				'args'                => array(
					'post'    => array( 'type' => 'integer', 'required' => true ),
					'query'   => array( 'type' => 'string', 'required' => true ),
					'content' => array( 'type' => 'string', 'required' => false ),
					'title'   => array( 'type' => 'string', 'required' => false ),
				),
			)
		);
	}

	/**
	 * Measure and hand back the verdict block, rendered by the same code that
	 * renders it on page load — so the live answer can never drift from the
	 * saved one in wording, marks or order.
	 *
	 * @param \WP_REST_Request $request The request.
	 * @return \WP_REST_Response
	 */
	public function rest_check( \WP_REST_Request $request ) {
		$post = get_post( (int) $request->get_param( 'post' ) );
		if ( ! $post ) {
			return rest_ensure_response( array( 'html' => '' ) );
		}

		$query   = (string) $request->get_param( 'query' );
		$content = $request->get_param( 'content' );
		$title   = $request->get_param( 'title' );

		// Called even when the focus is empty — that state has its own answer
		// ("add the words…"), and skipping the renderer left the panel blank
		// instead, which reads as broken rather than as waiting.
		ob_start();
		$this->render_verdicts( $post, $query, $content, $title );
		return rest_ensure_response( array( 'html' => (string) ob_get_clean() ) );
	}

	/**
	 * The live-check script, only on an editor screen showing this box.
	 *
	 * @param string $hook The admin page.
	 * @return void
	 */
	public function enqueue_live( $hook ) {
		if ( 'post.php' !== $hook && 'post-new.php' !== $hook ) {
			return;
		}
		wp_enqueue_script(
			'agentimus-focus-live',
			plugins_url( 'assets/focus-live.js', AGENTIMUS_FILE ),
			array( 'wp-api-fetch' ),
			AGENTIMUS_VERSION,
			true
		);
	}

	/**
	 * Register the meta so the block editor and MCP can read it, and so an
	 * add-on can write it without going through this form.
	 *
	 * @return void
	 */
	public static function register_meta() {
		foreach ( Content::post_types() as $type ) {
			register_post_meta(
				$type,
				self::META,
				array(
					'type'              => 'string',
					'single'            => true,
					'show_in_rest'      => true,
					'default'           => '',
					'sanitize_callback' => array( __CLASS__, 'sanitize' ),
					'auth_callback'     => function ( $allowed, $meta_key, $object_id ) {
						return current_user_can( 'edit_post', (int) $object_id );
					},
				)
			);
		}
	}

	/**
	 * A focus is one short search string. Markup and newlines are stripped —
	 * this value is compared against page text and shown back in an attribute.
	 *
	 * @param string $value Raw input.
	 * @return string
	 */
	public static function sanitize( $value ) {
		$value = sanitize_text_field( (string) $value );
		$value = trim( preg_replace( '/\s+/u', ' ', $value ) );
		return mb_substr( $value, 0, self::MAX_LEN );
	}

	/**
	 * The focus this page is judged against: what the author chose, or — when
	 * they have chosen nothing — the search that brings the most people, so a
	 * page that has never been touched still says something true.
	 *
	 * @param int|\WP_Post $post Post or ID.
	 * @return array{query:string,chosen:bool}
	 */
	/**
	 * Split a stored focus into the searches it holds.
	 *
	 * The meta is still ONE string — a comma-separated list — so nothing that
	 * reads it needs a schema change or a migration, and a single-search value
	 * from before this existed comes back as a list of one. Comma is the
	 * separator because it is the character people already use for "and another
	 * one", and because a space has to stay INSIDE a search: "new features" is
	 * one thing somebody types into Google.
	 *
	 * @param string $value A stored or submitted focus.
	 * @return array<int,string> One or more searches, in order, deduplicated.
	 */
	public static function phrases( $value ) {
		$out = array();
		foreach ( explode( ',', (string) $value ) as $part ) {
			$part = self::sanitize( $part );
			if ( '' !== $part && ! in_array( $part, $out, true ) ) {
				$out[] = $part;
			}
		}
		return $out;
	}

	/**
	 * The one search everything OUTSIDE this editor uses.
	 *
	 * The worklist, the Findings screen and the MCP tools each judge a page
	 * against a single search, and teaching all of them about lists is a much
	 * bigger change than this box needed. They take the first — the one the
	 * author put first — and the editor is where the rest are measured.
	 *
	 * @param \WP_Post|int $post The post.
	 * @return string
	 */
	public static function primary( $post ) {
		$phrases = self::phrases( self::for_post( $post )['query'] );
		return $phrases ? $phrases[0] : '';
	}

	public static function for_post( $post ) {
		$post = get_post( $post );
		if ( ! $post ) {
			return array( 'query' => '', 'chosen' => false );
		}

		$chosen = self::sanitize( (string) get_post_meta( $post->ID, self::META, true ) );
		if ( '' !== $chosen ) {
			return array( 'query' => $chosen, 'chosen' => true );
		}

		$searches = self::searches( $post->ID );
		return array(
			'query'  => $searches ? (string) $searches[0]['query'] : '',
			'chosen' => false,
		);
	}

	/**
	 * The searches the engines report for this page, busiest first, with the
	 * machine probes stripped — no edit to a page makes a `site:` query click.
	 *
	 * @param int $post_id Post ID.
	 * @return array<int,array{query:string,position:float,impressions:int,clicks:int}>
	 */
	public static function searches( $post_id ) {
		static $memo = array();

		$post_id = (int) $post_id;
		if ( $post_id <= 0 ) {
			return array();
		}
		// One table read per request: the picker asks, and the answered-state
		// next-step asks again for the same post moments later.
		if ( isset( $memo[ $post_id ] ) ) {
			return $memo[ $post_id ];
		}

		try {
			$state = Search\Report::source_state();
			if ( '' === (string) $state['source'] ) {
				return array();
			}
			$rows = (array) Search\Table::snapshot( $state['source'] );
		} catch ( \Throwable $e ) {
			return array(); // No table, no source, mid-migration. Not worth an error.
		}

		$out = array();
		foreach ( $rows as $row ) {
			if ( (int) ( isset( $row['page_id'] ) ? $row['page_id'] : 0 ) !== $post_id ) {
				continue;
			}
			$query = (string) ( isset( $row['query'] ) ? $row['query'] : '' );
			if ( '' === $query || Search\Opportunities::is_operator_query( $query ) ) {
				continue;
			}
			$out[] = array(
				'query'       => $query,
				'position'    => round( (float) $row['position'], 1 ),
				'impressions' => (int) $row['impressions'],
				'clicks'      => (int) $row['clicks'],
			);
		}

		usort(
			$out,
			static function ( $a, $b ) {
				return $b['impressions'] <=> $a['impressions'];
			}
		);
		$memo[ $post_id ] = array_slice( $out, 0, self::MAX_CHOICES );
		return $memo[ $post_id ];
	}

	/**
	 * A search's numbers, in words rather than in Search Console's shorthand.
	 *
	 * "#4.2 · 2,140 shown · 63 visits" is three pieces of jargon to anyone who
	 * has never opened Search Console — and this panel is shown to everyone who
	 * writes a post, not to the people who already know what an impression is.
	 *
	 * @param array $row A reported search: position, impressions, clicks.
	 * @return string
	 */
	/**
	 * The engine these numbers came from, named the way people say it.
	 *
	 * This box was written against Search Console and said "Google" in four
	 * places, all of them plain wrong on a site that connected only Bing —
	 * telling an author Google reported a search that Google never saw. The
	 * source is already known; nothing here has to guess.
	 *
	 * @return string 'Google', 'Bing', or a neutral plural when neither answers.
	 */
	private static function engine_name() {
		try {
			$source = (string) Search\Report::source_state()['source'];
		} catch ( \Throwable $e ) {
			return __( 'Search engines', 'agentimus' );
		}
		if ( 'google' === $source ) {
			return __( 'Google', 'agentimus' );
		}
		if ( 'bing' === $source ) {
			return __( 'Bing', 'agentimus' );
		}
		return __( 'Search engines', 'agentimus' );
	}

	public static function plain_stats( array $row ) {
		$position = (float) ( isset( $row['position'] ) ? $row['position'] : 0 );
		$shown    = (int) ( isset( $row['impressions'] ) ? $row['impressions'] : 0 );
		$clicks   = (int) ( isset( $row['clicks'] ) ? $row['clicks'] : 0 );

		if ( $shown <= 0 ) {
			return sprintf(
				/* translators: %s: the connected search engine, e.g. Google or Bing. */
				__( 'You typed this one — %s hasn’t reported it', 'agentimus' ),
				self::engine_name()
			);
		}

		return sprintf(
			/* translators: 1: rounded average position, 2: impressions, 3: clicks. */
			__( 'Usually %1$s in results · seen %2$s times · %3$s came to read', 'agentimus' ),
			// Rounded and ordinal: "usually 4th" is a place somebody can picture;
			// "#4.2" is a decimal nobody can.
			self::ordinal( (int) max( 1, round( $position ) ) ),
			number_format_i18n( $shown ),
			number_format_i18n( $clicks )
		);
	}

	/**
	 * 1 → 1st, 2 → 2nd, 11 → 11th.
	 *
	 * Public because the Findings screen reports the same places in the same
	 * words — "5th, up from 8th" has to read identically wherever it appears.
	 *
	 * @param int $n A place in the results.
	 * @return string
	 */
	public static function ordinal( $n ) {
		$n   = (int) $n;
		$mod = $n % 100;
		if ( $mod >= 11 && $mod <= 13 ) {
			return $n . 'th';
		}
		switch ( $n % 10 ) {
			case 1:
				return $n . 'st';
			case 2:
				return $n . 'nd';
			case 3:
				return $n . 'rd';
			default:
				return $n . 'th';
		}
	}

	/**
	 * How many days the reported searches cover, from the snapshot's own range.
	 *
	 * Read rather than assumed: the poll window is configurable and the engines
	 * revise it, so a hardcoded "last 28 days" would be right until the day it
	 * quietly wasn't — and a number with the wrong span attached is worse than
	 * one with no span at all.
	 *
	 * @return int Days, or 0 when the snapshot cannot say.
	 */
	public static function window_days() {
		try {
			$state = Search\Report::source_state();
			if ( '' === (string) $state['source'] ) {
				return 0;
			}
			$totals = Search\Table::totals( (string) $state['source'] );
		} catch ( \Throwable $e ) {
			return 0;
		}
		if ( empty( $totals['start'] ) || empty( $totals['end'] ) ) {
			return 0;
		}
		$start = strtotime( (string) $totals['start'] );
		$end   = strtotime( (string) $totals['end'] );
		if ( ! $start || ! $end || $end < $start ) {
			return 0;
		}
		return (int) round( ( $end - $start ) / DAY_IN_SECONDS ) + 1; // Inclusive of both ends.
	}

	/**
	 * How many published, agent-visible posts carry a focus. One indexed COUNT,
	 * cheap enough for a payload ride-along. The empty-string guard is not
	 * decoration: saving the editor writes the meta row even when the field was
	 * never touched, so without it this would count every post ever opened.
	 *
	 * @return int
	 */
	public static function published_with_focus() {
		global $wpdb;
		$types = Content::post_types();
		if ( empty( $types ) || ! isset( $wpdb ) || ! is_object( $wpdb ) ) {
			return 0;
		}
		$placeholders = implode( ', ', array_fill( 0, count( $types ), '%s' ) );
		$args         = array_merge( array( self::META ), $types );
		// Same false-positive story as Topics::stats(): the sniff can't evaluate
		// $placeholders, which array_fill() builds to match $types exactly.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(DISTINCT p.ID) FROM {$wpdb->posts} p INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID WHERE m.meta_key = %s AND m.meta_value <> '' AND p.post_status = 'publish' AND p.post_type IN ($placeholders)", $args ) );
	}

	/**
	 * Measure the page against its focus.
	 *
	 * Reads the post's OWN saved content, not the live editor buffer — so the
	 * verdict describes what is published, and the panel says so rather than
	 * implying it graded the unsaved draft.
	 *
	 * @param \WP_Post $post  The post.
	 * @param string   $query The focus.
	 * @return array|null Coverage verdict, or null when there is nothing to measure.
	 */
	public static function coverage( $post, $query, $content = null, $title = null ) {
		$query = self::sanitize( $query );
		if ( '' === $query || ! $post ) {
			return null;
		}
		// A live check passes the editor's current text; everything else measures
		// what is saved. Same measurement either way — only the source differs,
		// and the panel says which one it read.
		$raw  = null === $content ? (string) $post->post_content : (string) $content;
		$html = function_exists( 'do_blocks' ) ? do_blocks( $raw ) : $raw;

		// "The title they see" is the SEO title whenever one is set (solo mode
		// swaps it into the document title) — measuring the post title instead
		// meant a fix typed into the SEO field could never clear the verdict:
		// searchers saw the right title while the panel kept asking for it.
		// The saved meta wins even during a live check: editing the H1 does not
		// change what a search result shows while the SEO title stands. With an
		// SEO plugin owning titles we cannot read its field, so the post title
		// stays the honest nearest truth.
		$seen = Seo::title_ui_enabled()
			? Seo::sanitize_title( (string) get_post_meta( $post->ID, Seo::META_TITLE, true ) )
			: '';
		if ( '' !== $seen ) {
			$title = $seen;
		} elseif ( null === $title ) {
			$title = (string) $post->post_title;
		}
		return Coverage::measure( $html, (string) $title, $query );
	}

	/* ---------------------------------------------------------------------- *
	 *  The field
	 * ---------------------------------------------------------------------- */

	/**
	 * Render the section. Called by the shared box.
	 *
	 * Plain radios and one text input: no JavaScript, no REST round trip, and
	 * nothing to go stale between the block editor's two saves. The choices ARE
	 * the data, so the form can be read and submitted by anything.
	 *
	 * @param \WP_Post $post The post being edited.
	 * @return void
	 */
	public function render_field( $post ) {
		$searches = self::searches( $post->ID );
		$current  = self::for_post( $post );
		$known    = wp_list_pluck( $searches, 'query' );
		// A focus typed by hand, or one whose search has since dropped out of the
		// report, still has to be offered back — otherwise saving the form would
		// silently discard it.
		$custom = $current['chosen'] && ! in_array( $current['query'], $known, true ) ? $current['query'] : '';

		wp_nonce_field( self::NONCE, self::NONCE );

		// The focus itself, promoted out of the list. A checked radio among five
		// says "one of these"; this says "this is what the page is for", which is
		// the claim the verdict below is about.
		// The PRIMARY, not the raw meta: with several searches stored the value is
		// "clamp, php - 8.6", and printing that here would show a comma-joined
		// blob as though somebody had searched for it, with one page's stats
		// underneath. Each search gets its own verdict below instead.
		$phrases = self::phrases( $current['query'] );
		$this->render_current( $phrases ? $phrases[0] : '', $searches, count( $phrases ) );

		// One wrapper, so the live check has a single node to swap. Everything
		// inside it is a function of (post content, focus) and nothing else.
		echo '<div class="agentimus-focus__live" data-post="' . esc_attr( (string) $post->ID ) . '">';
		$this->render_verdicts( $post, $current['query'] );
		echo '</div>';

		if ( $searches ) {
			// "Also finding this page" named the list without saying what the
			// radios beneath it DO — so a reader who has never opened Search
			// Console was asked to make a choice with no idea of its effect.
			// The heading is the question, and the line under it is the answer.
			echo '<p class="agentimus-fieldhead" style="margin-top:12px">' . esc_html__( 'Which search should this page answer?', 'agentimus' ) . '</p>';
			$days = self::window_days();
			echo '<p class="agentimus-fieldhint">'
				. esc_html(
					$days > 0
						? sprintf(
							/* translators: 1: the connected search engine, 2: number of days the search report covers. */
							__( '%1$s showed this page for these in the last %2$d days. Pick one and the check above measures against it.', 'agentimus' ),
							self::engine_name(),
							$days
						)
						: sprintf(
							/* translators: %s: the connected search engine, e.g. Google or Bing. */
							__( '%s showed this page for these. Pick one and the check above measures against it.', 'agentimus' ),
							self::engine_name()
						)
				)
				. '</p>';

			echo '<div class="agentimus-focus__list">';
			foreach ( $searches as $row ) {
				$checked = ( '' === $custom && $row['query'] === $current['query'] );
				printf(
					'<label class="agentimus-focus__opt"><input type="radio" name="agentimus_focus_pick" value="%1$s"%2$s />'
						. '<span class="agentimus-focus__q">%3$s</span>'
						. '<span class="agentimus-focus__n">%4$s</span></label>',
					esc_attr( $row['query'] ),
					$checked ? ' checked="checked"' : '',
					esc_html( $row['query'] ),
					esc_html( self::plain_stats( $row ) )
				);
			}
			printf(
				'<label class="agentimus-focus__opt"><input type="radio" name="agentimus_focus_pick" value="%1$s"%2$s />'
					. '<span class="agentimus-focus__q">%3$s</span>'
					. '<span class="agentimus-focus__n">%4$s</span></label>',
				esc_attr( '__custom__' ),
				'' !== $custom ? ' checked="checked"' : '',
				esc_html__( 'Something I’ll type instead', 'agentimus' ),
				esc_html(
					sprintf(
						/* translators: %s: the connected search engine, e.g. Google or Bing. */
						__( 'For a search %s hasn’t reported yet', 'agentimus' ),
						self::engine_name()
					)
				)
			);
			echo '</div>';
		} else {
			// The "no searches yet" sentence belongs to render_current() above and
			// is printed there — both branches fire on a new post, and it appeared
			// twice. This one only has to make the typed value the choice, since
			// there is nothing to pick from.
			echo '<input type="hidden" name="agentimus_focus_pick" value="__custom__" />';
		}

		printf(
			'<input type="text" class="widefat agentimus-focus__text" name="agentimus_focus_custom" value="%1$s" maxlength="%2$d" placeholder="%3$s" />',
			esc_attr( $custom ),
			(int) self::MAX_LEN,
			esc_attr__( 'e.g. wordpress llms.txt', 'agentimus' )
		);

		$this->inline_css();
	}

	/**
	 * The current focus, shown as its own block with the traffic it earns.
	 *
	 * @param string $query    The focus.
	 * @param array  $searches This page's reported searches.
	 * @return void
	 */
	private function render_current( $query, array $searches, $total = 1 ) {
		echo '<p class="agentimus-fieldhead">' . esc_html__( 'This page is for', 'agentimus' ) . '</p>';

		if ( '' === $query ) {
			// Only the fact, here. The instruction lives inside the live region
			// below, so clearing the words puts it back and it can never sit
			// beside a verdict that contradicts it.
			//
			// Which fact, though, depends on who is reporting. Bing buys page
			// detail one HTTP call at a time and stops at the busiest few, so on
			// a page outside that sample "no searches have reached this page" is
			// not something waiting will fix — and telling an author their post
			// is invisible when the source simply never looked at it is the
			// worst version of this box being wrong.
			$cap = Search\Report::page_cap();
			echo '<p class="agentimus-fieldhint">'
				. esc_html(
					$cap > 0
						? sprintf(
							/* translators: %d: how many pages the connected engine reports searches for. */
							__( 'Your search source only reports the %d busiest pages, and this is not one of them. Type the search you want it to answer.', 'agentimus' ),
							$cap
						)
						: __( 'No searches have reached this page yet — normal for a new post.', 'agentimus' )
				)
				. '</p>';
			return;
		}

		// With several searches stored, the box shows the first and says so —
		// otherwise it reads as the only one, and the verdicts below come out of
		// nowhere.
		if ( $total > 1 ) {
			printf(
				'<p class="agentimus-fieldhint">%s</p>',
				esc_html(
					sprintf(
						/* translators: %d: how many searches this page is for. */
						_n( '%d search, each checked on its own:', '%d searches, each checked on its own:', $total, 'agentimus' ),
						$total
					)
				)
			);
			return;
		}

		// The numbers, when this focus is one of the reported searches. A focus
		// typed by hand has none, and inventing a zero row would read as traffic.
		$stats = '';
		foreach ( $searches as $row ) {
			if ( $row['query'] === $query ) {
				$stats = self::plain_stats( $row );
				break;
			}
		}

		printf(
			'<div class="agentimus-focus__now"><span class="agentimus-focus__nowq">%1$s</span>%2$s</div>',
			esc_html( $query ),
			'' !== $stats ? '<span class="agentimus-focus__nown">' . esc_html( $stats ) . '</span>' : ''
		);
	}

	/**
	 * What the content checks flagged, as one line.
	 *
	 * The focus asks whether the page answers its search; this asks whether the
	 * page is worth quoting at all. Both belong here — an author fixing one is
	 * already in the right place to fix the other, and the worklist says both
	 * about the same row.
	 *
	 * @param \WP_Post $post The post.
	 * @return void
	 */
	private function render_flags( $post ) {
		$flags = PageCheck::flags( $post );
		if ( ! $flags ) {
			return;
		}
		printf(
			'<p class="agentimus-focus__verdict is-warn"><span class="agentimus-focus__mark" aria-hidden="true">◐</span><span><strong>%1$s</strong> — %2$s</span></p>',
			esc_html__( 'Also worth fixing', 'agentimus' ),
			esc_html( implode( ' · ', array_slice( $flags, 0, 3 ) ) )
		);
	}

	/**
	 * The measurement, in words the author can check.
	 *
	 * @param \WP_Post $post  The post.
	 * @param string   $query The focus.
	 * @return void
	 */
	/**
	 * Every search this page is for, each measured on its own.
	 *
	 * @param \WP_Post $post    The post.
	 * @param string   $value   The focus value (one search, or several).
	 * @param string   $content Editor text, or null for the saved post.
	 * @param string   $title   Editor title, or null.
	 * @return void
	 */
	public function render_verdicts( $post, $value, $content = null, $title = null ) {
		$phrases = self::phrases( $value );
		if ( ! $phrases ) {
			$this->render_verdict( $post, '', null, null !== $content );
			return;
		}

		$live = null !== $content;
		foreach ( $phrases as $i => $phrase ) {
			$cover = self::coverage( $post, $phrase, $content, $title );
			// The heading gives each verdict its subject — needed when there are
			// several, and a third naming of the same words when there is one:
			// the box above already shows it, and the chip below repeats it.
			if ( count( $phrases ) > 1 ) {
				printf(
					'<p class="agentimus-focus__which">%s</p>',
					esc_html( $phrase )
				);
			}
			// Only the last one carries the "measured against…" note, and only
			// the first carries an instruction: repeating either per search
			// turns a short panel into a wall.
			$this->render_verdict( $post, $phrase, $cover, $live, 0 === $i, count( $phrases ) - 1 === $i );
		}
	}

	public function render_verdict( $post, $query, $cover = null, $live = false, $with_todo = true, $with_note = true ) {
		$cover = null === $cover ? self::coverage( $post, $query ) : $cover;
		if ( ! $cover || ! $cover['words'] ) {
			// No focus, so no verdict — and saying so is the live region's job,
			// because clearing the words has to remove the old answer rather
			// than leave it standing over an empty field.
			echo '<p class="agentimus-focus__todo">'
				. esc_html__( 'Add the words this page should be found for. The check tells you which of them the page actually uses.', 'agentimus' )
				. '</p>';
			return;
		}

		$states = array(
			Coverage::ANSWERED  => array( 'ok', '●', __( 'Answered', 'agentimus' ) ),
			Coverage::SCATTERED => array( 'warn', '◐', __( 'Scattered', 'agentimus' ) ),
			Coverage::BARELY    => array( 'mute', '○', __( 'Barely', 'agentimus' ) ),
			Coverage::MISSING   => array( 'bad', '✕', __( 'Missing', 'agentimus' ) ),
		);
		list( $tone, $mark, $label ) = $states[ $cover['state'] ];

		switch ( $cover['state'] ) {
			case Coverage::ANSWERED:
				$why = '' !== $cover['heading']
					? sprintf(
						/* translators: %s: the heading above the passage that answers. */
						__( 'One passage carries it, under “%s”.', 'agentimus' ),
						$cover['heading']
					)
					: __( 'One passage carries the whole search.', 'agentimus' );
				break;
			case Coverage::SCATTERED:
				$why = sprintf(
					/* translators: %d: how many words the search has. */
					__( 'All %d words are on the page, but never together in one passage.', 'agentimus' ),
					$cover['words']
				);
				break;
			case Coverage::BARELY:
				$why = sprintf(
					/* translators: 1: words found, 2: words in the search. */
					__( '%1$d of %2$d words appear anywhere on the page.', 'agentimus' ),
					$cover['on_page'],
					$cover['words']
				);
				break;
			default:
				$why = __( 'None of it is on the page — this may not be what the post is for.', 'agentimus' );
		}

		// Exactly two flex children — the mark, then the whole sentence. Leaving
		// the label and the reason as separate items made the reason its own
		// flex box, so a wrapped line indented to its own edge instead of the
		// text's.
		printf(
			'<p class="agentimus-focus__verdict is-%1$s"><span class="agentimus-focus__mark" aria-hidden="true">%2$s</span><span><strong>%3$s</strong> — %4$s</span></p>',
			esc_attr( $tone ),
			esc_html( $mark ),
			esc_html( $label ),
			esc_html( $why )
		);

		// The single most valuable separate fact, and the one edit that fixes it.
		// NOT printed for an Answered page: there it stops being a footnote and
		// becomes the instruction itself (see the answered next-step below) —
		// whispering the one remaining fix in grey was how an owner arrived from
		// "Improve meta title & description" and read a green verdict as "done".
		if ( 0 === (int) $cover['in_title'] && Coverage::ANSWERED !== $cover['state'] ) {
			echo '<p class="agentimus-focus__verdict is-mute"><span class="agentimus-focus__mark" aria-hidden="true">○</span><span>'
				. esc_html__( 'Not in your title — the words people typed do not appear in the title they see.', 'agentimus' )
				. '</span></p>';
		}

		$this->render_flags( $post );

		// WHICH words, not how many. "None of it is on the page" leaves an author
		// to work out what to write; the badges hand them the list, and on a page
		// that half matches they show the one word to go and use.
		$this->render_terms( $cover, $query );

		// What to actually do about it — the panel's one instruction, marked by a
		// rule down its left edge and nothing else. It was bold, which is fine
		// for a phrase and shouting for four lines: the rule already says "this
		// is the instruction", so the weight was doing the job twice. Still
		// plain TEXT, not a link, and deliberately: there is
		// nowhere to send someone closer to the fix than the editor they are
		// already looking at, and a link that only scrolls is theatre.
		//
		// Each line names the ACT, the PLACE and the moment it re-checks —
		// "answer it here" assumed the author already knew that "here" is the
		// post body and that one paragraph has to carry the whole search.
		$advice = array(
			Coverage::SCATTERED => __( 'These words are all on the page but never together. Put them in ONE paragraph of the post that answers the search. Re-checked when you save.', 'agentimus' ),
			Coverage::BARELY    => __( 'Add a paragraph to the post that uses the missing words above and answers the search directly. Re-checked when you save.', 'agentimus' ),
			Coverage::MISSING   => __( 'Add a paragraph to the post using these words, or pick a search below that this page really is for. Re-checked when you save.', 'agentimus' ),
		);
		if ( $with_todo && isset( $advice[ $cover['state'] ] ) ) {
			echo '<p class="agentimus-focus__todo">' . esc_html( $advice[ $cover['state'] ] ) . '</p>';
		}

		// Answered is not always done. The advice map above only spoke for the
		// un-answered states, so an owner arriving from Search Opportunities —
		// whose page answers but RANKS 8th, or sits on page one unclicked — met
		// a green verdict and no next step. The search's own stored numbers say
		// which lever is next; when they say nothing, silence stays earned.
		if ( $with_todo && Coverage::ANSWERED === $cover['state'] ) {
			$next = $this->answered_next_step( $post, $query, $cover );
			if ( '' !== $next ) {
				echo '<p class="agentimus-focus__todo">' . esc_html( $next ) . '</p>';
			}
		}

		if ( ! $with_note ) {
			return;
		}
		echo '<p class="agentimus-focus__note">'
			. esc_html(
				$live
					? __( 'Measured against what you have written, saved or not.', 'agentimus' )
					: __( 'Measured against the last saved version of this page.', 'agentimus' )
			)
			. '</p>';
	}

	/**
	 * The one instruction an ANSWERED page still deserves, from the chosen
	 * search's own stored numbers.
	 *
	 * Three cases, first match wins, in the order of what they cost:
	 *  - ranked 8–20: one push from page one — the title (or, with the words
	 *    already there, internal links) is the lever;
	 *  - page one, shown but never clicked: the title/description pair is what
	 *    a searcher reads before deciding;
	 *  - anything else with the words missing from the title: say only that.
	 * A page that ranks well, earns its clicks and carries its words in the
	 * title gets nothing — silence is the earned state, never a filler tip.
	 *
	 * @param \WP_Post $post  The post.
	 * @param string   $query The chosen search.
	 * @param array    $cover Its Coverage::measure() verdict.
	 * @return string Empty when nothing is worth saying.
	 */
	private function answered_next_step( $post, $query, array $cover ) {
		$query = trim( (string) $query );
		if ( '' === $query ) {
			return '';
		}

		$row = null;
		foreach ( self::searches( $post->ID ) as $r ) {
			if ( mb_strtolower( $r['query'] ) === mb_strtolower( $query ) ) {
				$row = $r;
				break;
			}
		}

		// in_title is a COUNT of searched words found in the title, not a flag.
		// "Already in your title" is only true when ALL of them are — his OKF
		// page had "okf" in the title but not "bundle", and one match must not
		// claim the set. Partial keeps the title as the lever: finishing it is
		// the very edit being advised.
		$words_total  = (int) $cover['words'];
		$in_title_ct  = (int) $cover['in_title'];
		$all_in_title = $words_total > 0 && $in_title_ct >= $words_total;
		// Solo mode has the field in this very box; with an SEO plugin the
		// words go in that plugin's field instead — never point at a field
		// that is not on the screen.
		$title_field = Seo::title_ui_enabled()
			? __( 'the SEO title in this box', 'agentimus' )
			: __( 'your SEO plugin’s title field', 'agentimus' );

		$position = null !== $row ? (float) $row['position'] : 0.0;
		$shown    = null !== $row ? (int) $row['impressions'] : 0;
		$clicks   = null !== $row ? (int) $row['clicks'] : 0;

		// Rank 8–20, the "almost on page one" band (7.5 rounds to 8th — the
		// same edge the Opportunities card draws).
		if ( null !== $row && $position >= 7.5 && $position < 20.5 ) {
			if ( $all_in_title ) {
				// The lever must see its own completion (his catch: he added the
				// links and the panel kept asking). Inbound internal links are
				// LOCAL data — count them, and when they exist the instruction
				// becomes a status: your side is done, the rest is Google's
				// clock. Never silence here — a standing rank problem with no
				// words would read as the panel giving up.
				//
				// The test itself lives in Standing because the Findings screen and
				// the Opportunities card ask the same question of the same page, and
				// copies of "done" would eventually answer differently — this panel
				// saying nothing is left while the front door still counted it.
				$inbound = Standing::inbound_links( $post );
				if ( Standing::near_done( $post, $query, $cover ) ) {
					return sprintf(
						/* translators: %d: how many of the site's own posts link to this one. */
						_n(
							'Answered, titled, and %d of your posts links here — your side is done. What is left is Google’s: the next reports decide the rank, and the Opportunities card clears itself when they improve.',
							'Answered, titled, and %d of your posts link here — your side is done. What is left is Google’s: the next reports decide the rank, and the Opportunities card clears itself when they improve.',
							$inbound,
							'agentimus'
						),
						$inbound
					);
				}
				return __( 'Answered, and the words are already in your title — this result sits just off page one, so the next lever is links: the “Link to your own posts” box suggests which of your posts should point here.', 'agentimus' );
			}
			if ( $in_title_ct > 0 ) {
				return sprintf(
					/* translators: %s: where the SEO title is edited. */
					__( 'Answered on the page — and the title is almost there: it carries some of the searched words, not all. This result sits just off page one; finish the set in %s, and use it in an early heading.', 'agentimus' ),
					$title_field
				);
			}
			return sprintf(
				/* translators: %s: where the SEO title is edited. */
				__( 'Answered on the page — now the title: this result sits just off page one and the searched words are not in the title searchers see. Put them in %s, and in an early heading.', 'agentimus' ),
				$title_field
			);
		}

		// Page one, real views, not one click: the snippet is losing, not the page.
		if ( null !== $row && $position > 0 && $position < 7.5 && $shown >= 10 && 0 === $clicks ) {
			// This lever, too, must see its own completion. Once the pair is
			// written there is no further edit to ask for, and asking anyway is
			// the same bug the links branch above had: the panel kept demanding
			// work that was already done.
			if ( Standing::seen_done( $post ) ) {
				return sprintf(
					/* translators: %s: how often the result was shown. */
					__( 'Page one, shown %s times, and your title and description are both written — your side is done. What is left is whether searchers click: the next reports decide it.', 'agentimus' ),
					number_format_i18n( $shown )
				);
			}
			return sprintf(
				/* translators: 1: how often the result was shown, 2: where the SEO title is edited. */
				__( 'Page one, and people scroll past — shown %1$s times, no one came to read. Rewrite %2$s and the AI description: that pair is what a searcher reads before deciding.', 'agentimus' ),
				number_format_i18n( $shown ),
				$title_field
			);
		}

		if ( ! $all_in_title ) {
			if ( $in_title_ct > 0 ) {
				return sprintf(
					/* translators: %s: where the SEO title is edited. */
					__( 'One thing left: the title carries some of the searched words, not all. Finish the set in %s.', 'agentimus' ),
					$title_field
				);
			}
			return sprintf(
				/* translators: %s: where the SEO title is edited. */
				__( 'One thing left: the words people type are not in the title they see. Put them in %s.', 'agentimus' ),
				$title_field
			);
		}

		return '';
	}

	/**
	 * The searched words, one badge each, in the spelling the searcher used.
	 *
	 * Three states, and they are not a scale: in the passage that answers best,
	 * elsewhere on the page, or absent. Colour never carries it alone — the
	 * shapes differ too, and each badge says its state in a title.
	 *
	 * @param array $cover A Coverage::measure() verdict.
	 * @return void
	 */
	private function render_terms( array $cover, $query = '' ) {
		$terms = isset( $cover['terms'] ) && is_array( $cover['terms'] ) ? $cover['terms'] : array();
		if ( ! $terms ) {
			return;
		}

		// No badge for the search here. It was the THIRD time the same words
		// appeared in one short panel — as the heading, as a badge, and as the
		// chip that produced them — and of the three it was the one saying
		// nothing the mark and label above it had not already said.
		//
		// WHICH words are missing, in a sentence, and only when knowing changes
		// what you would write. "Answered" needs no list, and "Missing" means
		// all of them.
		if ( Coverage::BARELY === $cover['state'] || Coverage::SCATTERED === $cover['state'] ) {
			$absent = array();
			foreach ( $terms as $t ) {
				if ( empty( $t['on_page'] ) ) {
					$absent[] = (string) $t['word'];
				}
			}
			if ( $absent ) {
				printf(
					'<p class="agentimus-focus__absent">%s</p>',
					esc_html(
						sprintf(
							/* translators: %s: comma-separated list of words. */
							_n( 'Not on the page: %s', 'Not on the page: %s', count( $absent ), 'agentimus' ),
							implode( ', ', $absent )
						)
					)
				);
			}
		}
	}

	/**
	 * The section's own styles. Printed inline, like the box's other halves —
	 * a stylesheet for six rules that only ever appear in one meta box is more
	 * moving parts than it earns.
	 *
	 * @return void
	 */
	private function inline_css() {
		echo '<style>'
			. '.agentimus-focus__list{display:flex;flex-direction:column;gap:6px;margin:0 0 8px}'
			. '.agentimus-focus__opt{display:grid;grid-template-columns:18px minmax(0,1fr);gap:2px 4px;align-items:start;cursor:pointer}'
			. '.agentimus-focus__opt input{margin:2px 0 0}'
			. '.agentimus-focus__q{font-size:12.5px;color:#1e1e1e;overflow-wrap:anywhere;line-height:1.35}'
			. '.agentimus-focus__n{grid-column:2;font-size:11px;color:#646970;line-height:1.45}'
			. '.agentimus-focus__now{border:1px solid #146b64;border-left-width:3px;border-radius:2px;background:#f2f8f7;padding:8px 10px;margin:0 0 8px}.agentimus-focus__nowq{display:block;font-size:13.5px;font-weight:600;color:#1e1e1e;line-height:1.35;overflow-wrap:anywhere}.agentimus-focus__nown{display:block;margin-top:3px;font-size:11.5px;color:#50575e}.agentimus-focus__which{margin:10px 0 2px;font-size:12px;font-weight:600;color:#1e1e1e;overflow-wrap:anywhere}.agentimus-focus__which:first-child{margin-top:0}.agentimus-focus__chiplabel{margin:10px 0 4px;font-size:11px;color:#646970}.agentimus-focus__chips{display:flex;flex-wrap:wrap;gap:5px;margin:0 0 6px}.agentimus-focus__chip{display:inline-flex;align-items:center;gap:4px;font-size:12px;line-height:1.6;padding:2px 4px 2px 10px;border-radius:999px;color:#1e1e1e;background:#fff;border:1px solid #c3c4c7}.agentimus-focus__chipx{appearance:none;border:0;background:none;cursor:pointer;color:#8c8f94;font-size:14px;line-height:1;padding:0 4px;border-radius:999px}.agentimus-focus__chipx:hover{color:#b32d2e;background:#f6e7e7}.agentimus-focus__absent{margin:4px 0 0;font-size:11px;color:#8c8f94}.agentimus-focus__todo{margin:8px 0 0;padding-left:9px;border-left:2px solid #146b64;font-size:12px;line-height:1.5;color:#1e1e1e}.agentimus-focus__text{margin:0 0 8px}'
			. '.agentimus-focus__verdict{display:flex;gap:5px;align-items:baseline;margin:0 0 4px;font-size:11.5px;line-height:1.45;color:#50575e}.agentimus-focus__mark{flex:0 0 auto}'
			. '.agentimus-focus__verdict strong{font-weight:600}'
			. '.agentimus-focus__verdict.is-ok strong{color:#2f7a4c}'
			. '.agentimus-focus__verdict.is-warn strong{color:#96690f}'
			. '.agentimus-focus__verdict.is-bad strong{color:#b93c2b}'
			. '.agentimus-focus__verdict.is-mute strong{color:#646970}'
			. '.agentimus-focus__note{margin:6px 0 0;font-size:10.5px;color:#8c8f94}'
			. '</style>';
	}

	/**
	 * Save the chosen focus.
	 *
	 * @param int      $post_id Post ID.
	 * @param \WP_Post $post    The post.
	 * @return void
	 */
	public function save( $post_id, $post ) {
		if ( ! MetaSave::verified( $post_id, self::NONCE ) ) {
			return;
		}

		$pick   = isset( $_POST['agentimus_focus_pick'] ) ? sanitize_text_field( wp_unslash( $_POST['agentimus_focus_pick'] ) ) : '';
		$custom = isset( $_POST['agentimus_focus_custom'] ) ? self::sanitize( wp_unslash( $_POST['agentimus_focus_custom'] ) ) : '';

		$value = ( '__custom__' === $pick ) ? $custom : self::sanitize( $pick );

		if ( '' === $value ) {
			delete_post_meta( $post_id, self::META );
			return;
		}
		update_post_meta( $post_id, self::META, $value );
	}
}

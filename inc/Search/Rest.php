<?php
/**
 * Search REST controller — the opportunities report the Search Opportunities
 * card renders. Read-only: setting a page aside goes through the existing
 * /optimize/ignore route, because it is the same, single per-page decision.
 *
 * @package Agentimus
 */

namespace Agentimus\Search;


defined( 'ABSPATH' ) || exit;

final class Rest {

	/** @var string REST namespace. */
	const NS = 'agentimus/v1';

	/** @var \Agentimus\Settings Core settings — holds the shared set-aside list. */
	private $core;

	/** @var \Agentimus\Score|null Lazy — only report() needs it, and only for mapped cards. */
	private $score;

	/**
	 * @param \Agentimus\Settings $core Core settings instance.
	 */
	public function __construct( \Agentimus\Settings $core ) {
		$this->core = $core;
	}

	/**
	 * Hooks only.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'rest_api_init', array( $this, 'routes' ) );
	}

	/**
	 * Register the routes.
	 *
	 * @return void
	 */
	public function routes() {
		register_rest_route( self::NS, '/search/opportunities', array(
			'methods'             => \WP_REST_Server::READABLE,
			'callback'            => array( $this, 'report' ),
			'permission_callback' => array( $this, 'can_manage' ),
			'args'                => array(
				'source' => array( 'type' => 'string', 'required' => false ),
			),
		) );

		register_rest_route( self::NS, '/search/ignore', array(
			'methods'             => \WP_REST_Server::CREATABLE,
			'callback'            => array( $this, 'ignore' ),
			'permission_callback' => array( $this, 'can_manage' ),
			'args'                => array(
				// One of the two identities, checked in the callback (REST arg
				// validation cannot express "exactly one of"): `post` for mapped
				// pages, `url` for pages with no post behind them.
				'post'    => array( 'type' => 'integer', 'required' => false ),
				'url'     => array( 'type' => 'string', 'required' => false ),
				'ignored' => array( 'type' => 'boolean', 'required' => true ),
			),
		) );

		register_rest_route( self::NS, '/search/performance', array(
			'methods'             => \WP_REST_Server::READABLE,
			'callback'            => array( $this, 'performance' ),
			'permission_callback' => array( $this, 'can_manage' ),
			'args'                => array(
				'source' => array( 'type' => 'string', 'required' => false ),
			),
		) );
	}

	/**
	 * POST /search/ignore — set aside (or restore) a page from the search
	 * worklist. Its own list, not the citability one: excusing a page from
	 * "improve this for quoting" and from "improve this for search" are
	 * different calls, and each screen shows the list it owns.
	 *
	 * @param \WP_REST_Request $request The request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function ignore( \WP_REST_Request $request ) {
		$id     = absint( $request->get_param( 'post' ) );
		$url    = trim( (string) $request->get_param( 'url' ) );
		$ignore = (bool) $request->get_param( 'ignored' );

		$all = $this->core->all();
		if ( $id > 0 ) {
			$list = ( isset( $all['search_ignored'] ) && is_array( $all['search_ignored'] ) ) ? array_map( 'intval', $all['search_ignored'] ) : array();
			$list = array_values( array_diff( $list, array( $id ) ) );
			if ( $ignore ) {
				$list[] = $id;
			}
			$all['search_ignored'] = $list;
		} elseif ( '' !== $url ) {
			// URL-keyed: the only identity an unmapped page (the homepage on some
			// sites, an archive, a gone permalink) has. Same host only — this
			// ledger names OUR pages, and must not become a store of arbitrary
			// strings.
			$key  = Pages::key( esc_url_raw( $url ) );
			$host = strtolower( (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST ) );
			if ( '' === $key || strtolower( (string) wp_parse_url( $key, PHP_URL_HOST ) ) !== $host ) {
				return new \WP_Error( 'agentimus_bad_url', __( 'Invalid page address.', 'agentimus' ), array( 'status' => 400 ) );
			}
			$list = ( isset( $all['search_ignored_urls'] ) && is_array( $all['search_ignored_urls'] ) ) ? array_map( 'strval', $all['search_ignored_urls'] ) : array();
			$list = array_values( array_diff( $list, array( $key ) ) );
			if ( $ignore ) {
				$list[] = $key;
			}
			$all['search_ignored_urls'] = $list;
		} else {
			return new \WP_Error( 'agentimus_bad_post', __( 'Invalid post.', 'agentimus' ), array( 'status' => 400 ) );
		}
		$this->core->update( $all );

		return $this->report( $request ); // The worklist the caller is looking at, already refreshed.
	}

	/**
	 * GET /search/performance — the whole-picture read of the same snapshot the
	 * opportunities worklist is carved from: window totals, top queries, top
	 * pages, and which sources can answer.
	 *
	 * @param \WP_REST_Request $request The request.
	 * @return \WP_REST_Response
	 */
	public function performance( \WP_REST_Request $request ) {
		$state  = $this->source_state( $request );
		$out    = array(
			'sources'     => $state['sources'],
			'source'      => $state['source'],
			'performance' => null,
			'range'       => null,
			'trend'       => null,
		);

		if ( '' !== $state['source'] ) {
			$rows = Table::snapshot( $state['source'] );
			$out['performance'] = Performance::build( $rows );
			$out['performance']['top_pages'] = array_map( array( $this, 'name_page' ), $out['performance']['top_pages'] );
			if ( ! empty( $rows ) ) {
				$out['range'] = array(
					'start' => (string) $rows[0]['range_start'],
					'end'   => (string) $rows[0]['range_end'],
				);
			}
			// Google-only extras from the same producer the MCP tool reads —
			// the two doors must tell one story ({@see Report::google_extras}).
			$out['trend'] = 'google' === $state['source'] ? Report::google_extras() : null;
		}

		return rest_ensure_response( $out );
	}

	/**
	 * Give a top-page row a human name and an editor door.
	 *
	 * @param array $row A page row.
	 * @return array
	 */
	private function name_page( array $row ) {
		$id = (int) $row['page_id'];
		// Decoded, not escaped — the admin app escapes text itself.
		$row['title']    = $id > 0 ? html_entity_decode( (string) get_the_title( $id ), ENT_QUOTES, 'UTF-8' ) : '';
		$row['edit_url'] = $id > 0 ? (string) get_edit_post_link( $id, 'raw' ) : '';
		$path            = (string) wp_parse_url( (string) $row['page_url'], PHP_URL_PATH );
		$row['path']     = '' !== $path ? $path : '/';
		if ( '' === $row['title'] ) {
			$row['title'] = $row['path'];
		}
		return $row;
	}

	/**
	 * Gate: site owners only.
	 *
	 * @return bool
	 */
	public function can_manage() {
		return current_user_can( 'manage_options' );
	}

	/**
	 * GET /search/opportunities — the report for one source, plus enough state
	 * for the card to tell its honest story (which sources are connected,
	 * which have data yet, what the last poll said).
	 *
	 * @param \WP_REST_Request $request The request.
	 * @return \WP_REST_Response
	 */
	public function report( \WP_REST_Request $request ) {
		$state   = $this->source_state( $request );
		$sources = $state['sources'];
		$source  = $state['source'];

		$out = array(
			'sources'   => $sources,
			'source'    => $source,
			'report'    => null,
			'range'     => null,
			// Always sent, even with no report: a page held back must stay visible
			// (and restorable) whatever the engines are reporting today.
			'set_aside' => $this->set_aside_rows(),
		);

		if ( '' !== $source ) {
			$rows   = Table::snapshot( $source );
			$report = Opportunities::build( $rows, $this->set_aside(), $this->set_aside_urls() );

			$report['almost_there']    = array_map( array( $this, 'enrich' ), $report['almost_there'] );
			$report['seen_not_chosen'] = array_map( array( $this, 'enrich' ), $report['seen_not_chosen'] );

			$out['report'] = $report;
			if ( ! empty( $rows ) ) {
				$out['range'] = array(
					'start' => (string) $rows[0]['range_start'],
					'end'   => (string) $rows[0]['range_end'],
				);
			}
		}

		return rest_ensure_response( $out );
	}

	/**
	 * Which sources can answer, and which one this request reads: the asked-for
	 * source when it has data, else the richest that does (Google first — its
	 * report joins query×page natively, where Bing needs two endpoints).
	 *
	 * @param \WP_REST_Request $request The request.
	 * @return array{sources:array,source:string}
	 */
	private function source_state( \WP_REST_Request $request ) {
		// One resolver for both doors: the admin and an agent must never be told
		// different things about the same numbers ({@see Report}).
		return Report::source_state( (string) $request->get_param( 'source' ) );
	}

	/**
	 * The search worklist's own set-aside list.
	 *
	 * @return array<int,int>
	 */
	private function set_aside() {
		$all  = $this->core->all();
		$list = ( isset( $all['search_ignored'] ) && is_array( $all['search_ignored'] ) ) ? $all['search_ignored'] : array();
		return array_map( 'intval', $list );
	}

	/**
	 * Its URL-keyed twin — pages held back by address because no post ID exists
	 * to hold them by.
	 *
	 * @return array<int,string>
	 */
	private function set_aside_urls() {
		$all  = $this->core->all();
		$list = ( isset( $all['search_ignored_urls'] ) && is_array( $all['search_ignored_urls'] ) ) ? $all['search_ignored_urls'] : array();
		return array_map( 'strval', $list );
	}

	/**
	 * The set-aside pages as rows the UI can list and restore — shown inside the
	 * Search Opportunities section, so a page held back is always visible where
	 * the decision was made.
	 *
	 * @return array<int,array>
	 */
	private function set_aside_rows() {
		$rows = array();
		foreach ( $this->set_aside() as $id ) {
			$title = html_entity_decode( (string) get_the_title( $id ), ENT_QUOTES, 'UTF-8' );
			if ( '' === $title ) {
				continue; // A deleted post keeps no row (the id stays harmlessly in the list).
			}
			$rows[] = array(
				'id'       => $id,
				'title'    => $title,
				'url'      => (string) get_permalink( $id ),
				'edit_url' => (string) get_edit_post_link( $id, 'raw' ),
			);
		}
		// URL-keyed entries wear their card's name — "Homepage" for the one
		// unmapped page we can name, the path for the rest — and offer no
		// editor door, exactly like the card didn't.
		foreach ( $this->set_aside_urls() as $url ) {
			$path   = (string) wp_parse_url( $url, PHP_URL_PATH );
			$path   = '' !== $path ? $path : '/';
			$rows[] = array(
				'id'       => 0,
				'title'    => self::is_home_path( $path ) ? __( 'Homepage', 'agentimus' ) : $path,
				'url'      => $url,
				'edit_url' => '',
			);
		}
		return $rows;
	}

	/**
	 * Whether a path is the site root — subdirectory installs compare against
	 * their real home path, not "/".
	 *
	 * @param string $path URL path ('/' when bare).
	 * @return bool
	 */
	private static function is_home_path( $path ) {
		$home = untrailingslashit( (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH ) );
		return untrailingslashit( (string) $path ) === $home;
	}

	/**
	 * Dress a page card for the UI: the post's real title and edit address.
	 * Unmapped pages (an archive, a gone permalink) keep their URL as the name
	 * and simply offer no editor door.
	 *
	 * @param array $card An engine page card.
	 * @return array
	 */
	private function enrich( array $card ) {
		$id = (int) $card['page_id'];
		if ( $id > 0 ) {
			// Decoded, not escaped: get_the_title() returns HTML entities, and the
			// admin app escapes text itself — leaving them encoded renders the
			// literal "isn&#8217;t" on screen.
			$card['title'] = html_entity_decode( (string) get_the_title( $id ), ENT_QUOTES, 'UTF-8' );
			// One door per fix, each landing on the box that holds it: the SEO
			// title + AI description live in the sidebar box (agentimus-topics),
			// the link suggester in its own box, the readability grades in the
			// tabbed panel. A button that names the fix beats a generic "open".
			$edit             = (string) get_edit_post_link( $id, 'raw' );
			$card['edit_url'] = $edit . '#agentimus-topics';
			$card['links_url'] = $edit . '#agentimus-internal-links';
			$card['read_url']  = $edit . '#agentimus-panel';
			// The same citability verdicts the Optimize worklist gives, asked for
			// THIS page — so the "Also in Optimize" flag doesn't depend on the
			// page happening to sit in the recency sample ({@see Score::page_flags}).
			$card['optimize_flags'] = $this->score()->page_flags( $id );
		} else {
			$card['title']    = (string) wp_parse_url( (string) $card['page_url'], PHP_URL_PATH );
			$card['edit_url'] = '';
		}
		$path         = (string) wp_parse_url( (string) $card['page_url'], PHP_URL_PATH );
		$card['path'] = '' !== $path ? $path : '/';
		if ( ! (int) $card['page_id'] ) {
			// WHICH doorless case this is, so the card can instruct instead of
			// shrug — and only where the instruction is true. The homepage's
			// title really is the site title + tagline (that is what core
			// prints for the front page); an archive or a gone permalink has
			// no such single lever, and naming one would be a lie.
			$is_home          = self::is_home_path( $card['path'] );
			$card['doorless'] = $is_home ? 'home' : 'generic';
			if ( $is_home ) {
				// A name, not a bare "/": this is the one unmapped page whose
				// identity the plugin actually knows.
				$card['title']       = __( 'Homepage', 'agentimus' );
				$card['general_url'] = admin_url( 'options-general.php' );
			}
		}
		unset( $card['all_queries'] ); // Counted in `counts`; the wire carries only what renders.
		return $card;
	}

	/**
	 * The grader behind the per-card citability flags — one instance per
	 * request, so its per-post memo holds across both groups' cards.
	 *
	 * @return \Agentimus\Score
	 */
	private function score() {
		if ( null === $this->score ) {
			$this->score = new \Agentimus\Score( $this->core );
		}
		return $this->score;
	}
}

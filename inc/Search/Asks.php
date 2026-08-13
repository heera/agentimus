<?php
/**
 * The ask ledger — a note of every time we asked a search engine about one of
 * the site's pages, and what came back.
 *
 * WHY THIS EXISTS. Bing answers about one page per request, so a site's pages
 * are worked through a few at a time rather than all at once. Without a record
 * of who has been asked, "this page has no searches" and "nobody has asked
 * about this page yet" are the same empty space on a screen — and the first
 * sentence, told to an owner whose page is doing fine, is simply untrue. The
 * queries table cannot tell them apart, because both cases store no rows. This
 * one can:
 *
 *   no row here                → never asked
 *   row, status 'none'         → asked, the engine reported nothing
 *   row, status 'ok'           → asked, and its searches are in the queries table
 *   row, status 'error'        → asked, the engine refused; its words are kept
 *
 * It doubles as the queue: the page waiting longest goes next, so coverage
 * spreads evenly instead of re-asking the same busy handful for ever.
 *
 * @package Agentimus
 */

namespace Agentimus\Search;

defined( 'ABSPATH' ) || exit;

final class Asks {

	/** @var string Schema version — bump on any structural change. */
	const VERSION = '1';

	/** @var string Option recording the installed schema version. */
	const VERSION_OPTION = 'agentimus_search_asks_db_version';

	/** @var string Asked, and the engine named at least one search. */
	const STATUS_OK = 'ok';

	/** @var string Asked, and the engine had nothing to report for this page. */
	const STATUS_NONE = 'none';

	/** @var string Asked, and the engine refused. Its own words are kept. */
	const STATUS_ERROR = 'error';

	/**
	 * The fully-prefixed table name.
	 *
	 * @return string
	 */
	public static function name() {
		global $wpdb;
		return $wpdb->prefix . 'agentimus_search_asks';
	}

	/**
	 * Install only when the recorded version is stale.
	 *
	 * @return void
	 */
	public static function maybe_install() {
		if ( get_option( self::VERSION_OPTION ) === self::VERSION ) {
			return;
		}
		self::install();
	}

	/**
	 * Create or migrate the table.
	 *
	 * @return void
	 */
	public static function install() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table   = self::name();
		$collate = $wpdb->get_charset_collate();

		// dbDelta is whitespace-sensitive: two spaces after PRIMARY KEY, lowercase types.
		dbDelta( "CREATE TABLE $table (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			source varchar(10) NOT NULL DEFAULT '',
			page_key varchar(191) NOT NULL DEFAULT '',
			page_id bigint(20) unsigned NOT NULL DEFAULT 0,
			page_url text,
			asked_at datetime DEFAULT NULL,
			status varchar(10) NOT NULL DEFAULT '',
			found int(10) unsigned NOT NULL DEFAULT 0,
			error text,
			PRIMARY KEY  (id),
			UNIQUE KEY source_page (source, page_key),
			KEY asked (source, asked_at)
		) $collate;" );

		foreach ( array( 'source', 'page_key', 'page_id', 'page_url', 'asked_at', 'status', 'found', 'error' ) as $column ) {
			$found = $wpdb->get_var( $wpdb->prepare( "SHOW COLUMNS FROM $table LIKE %s", $column ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from our own prefix helper.
			if ( null === $found ) {
				return;
			}
		}

		update_option( self::VERSION_OPTION, self::VERSION );
	}

	/**
	 * The stable name for a page, shared with the set-aside ledger so one page
	 * has one identity everywhere in the plugin.
	 *
	 * @param int    $page_id Resolved post ID, or 0.
	 * @param string $url     The page URL as the engine spells it.
	 * @return string
	 */
	public static function key( $page_id, $url ) {
		return Opportunities::page_key( (int) $page_id, (string) $url );
	}

	/**
	 * Write down that we asked, and what happened.
	 *
	 * Called for EVERY outcome including the dull ones. A page the engine had
	 * nothing to say about is the single most important row in this table: it is
	 * the difference between an honest "no searches reached this page" and a
	 * guess dressed as one.
	 *
	 * @param string $source 'bing' or 'google'.
	 * @param string $url    The page URL asked about.
	 * @param int    $page_id Resolved post ID, or 0.
	 * @param string $status One of the STATUS_* constants.
	 * @param int    $found  How many rows the engine returned.
	 * @param string $error  The engine's own words, when it refused.
	 * @return void
	 */
	public static function record( $source, $url, $page_id, $status, $found = 0, $error = '' ) {
		global $wpdb;

		$table  = self::name();
		$source = sanitize_key( (string) $source );
		$key    = self::key( $page_id, $url );
		if ( '' === $source || '' === $key ) {
			return;
		}

		$wpdb->query( $wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- our own table.
			"INSERT INTO $table (source, page_key, page_id, page_url, asked_at, status, found, error)
			VALUES (%s, %s, %d, %s, %s, %s, %d, %s)
			ON DUPLICATE KEY UPDATE page_id = VALUES(page_id), page_url = VALUES(page_url),
				asked_at = VALUES(asked_at), status = VALUES(status), found = VALUES(found), error = VALUES(error)",
			$source,
			$key,
			(int) $page_id,
			(string) $url,
			gmdate( 'Y-m-d H:i:s' ),
			sanitize_key( (string) $status ),
			max( 0, (int) $found ),
			mb_substr( (string) $error, 0, 500 )
		) );
	}

	/**
	 * Everything this source has been asked, keyed by page.
	 *
	 * One read rather than one per page: the rotation and the screen both want
	 * the whole picture, and a site with ten thousand pages still fits in a map
	 * far smaller than the snapshot beside it.
	 *
	 * @param string $source 'bing' or 'google'.
	 * @return array<string,array{askedAt:string,status:string,found:int,error:string,pageId:int,url:string}>
	 */
	public static function map( $source ) {
		global $wpdb;
		$table = self::name();
		$rows  = $wpdb->get_results( $wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- our own table.
			"SELECT page_key, page_id, page_url, asked_at, status, found, error FROM $table WHERE source = %s",
			sanitize_key( (string) $source )
		), ARRAY_A );

		$out = array();
		foreach ( (array) $rows as $row ) {
			$out[ (string) $row['page_key'] ] = array(
				'askedAt' => (string) $row['asked_at'],
				'status'  => (string) $row['status'],
				'found'   => (int) $row['found'],
				'error'   => (string) $row['error'],
				'pageId'  => (int) $row['page_id'],
				'url'     => (string) $row['page_url'],
			);
		}
		return $out;
	}

	/**
	 * What we know about one page, or null when it has never been asked about.
	 *
	 * ⚠️ Null is a real answer here, not a missing one — it is the state the
	 * screen has to say out loud rather than show as an empty column.
	 *
	 * @param string $source  'bing' or 'google'.
	 * @param string $url     The page URL.
	 * @param int    $page_id Resolved post ID, or 0.
	 * @return array|null
	 */
	public static function state( $source, $url, $page_id = 0 ) {
		global $wpdb;
		$table = self::name();
		$row   = $wpdb->get_row( $wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- our own table.
			"SELECT page_id, page_url, asked_at, status, found, error FROM $table WHERE source = %s AND page_key = %s",
			sanitize_key( (string) $source ),
			self::key( $page_id, $url )
		), ARRAY_A );

		if ( ! $row ) {
			return null;
		}
		return array(
			'askedAt' => (string) $row['asked_at'],
			'status'  => (string) $row['status'],
			'found'   => (int) $row['found'],
			'error'   => (string) $row['error'],
			'pageId'  => (int) $row['page_id'],
			'url'     => (string) $row['page_url'],
		);
	}

	/**
	 * The content types the engines can see, and therefore the ones worth asking
	 * about. Public types only, and never attachments — an image's own page is
	 * not something an owner works on.
	 *
	 * @return array<int,string>
	 */
	public static function post_types() {
		$types = get_post_types( array( 'public' => true ), 'names' );
		unset( $types['attachment'] );
		return array_values( $types );
	}

	/**
	 * The next pages to ask this engine about.
	 *
	 * Never-asked pages first, newest first — a page published this week is the
	 * one an owner is most likely to be looking at. Then, if there is room left
	 * in the batch, the pages asked about longest ago. That second half is what
	 * stops the rotation circling the same busy handful for ever, which is
	 * exactly what the old fixed "ten busiest pages" did every single day.
	 *
	 * Deliberately two small indexed queries rather than loading every page the
	 * site has ever published: this runs inside a poll that may be sharing a web
	 * request, and a list nobody will read past the first few entries is a poor
	 * reason to touch ten thousand rows.
	 *
	 * @param string $source 'bing' or 'google'.
	 * @param int    $limit  How many to return.
	 * @return array<int,array{url:string,id:int}>
	 */
	public static function next( $source, $limit ) {
		global $wpdb;

		$limit = max( 0, (int) $limit );
		if ( 0 === $limit ) {
			return array();
		}
		$source = sanitize_key( (string) $source );
		$asks   = self::name();
		$types  = self::post_types();
		if ( empty( $types ) ) {
			return array();
		}

		$holders = implode( ',', array_fill( 0, count( $types ), '%s' ) );
		$args    = array_merge( array( $source ), $types, array( $limit ) );

		// A published page with no row in the ledger has never been asked about.
		// The key for a resolved post is 'p<ID>' — the same spelling the ledger
		// writes, and the set-aside list before it.
		$ids = $wpdb->get_col( $wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- our own table; every value bound.
			"SELECT p.ID FROM {$wpdb->posts} p
			WHERE p.post_status = 'publish' AND p.post_type IN ($holders)
				AND NOT EXISTS (
					SELECT 1 FROM $asks a WHERE a.source = %s AND a.page_key = CONCAT('p', p.ID)
				)
			ORDER BY p.post_date DESC
			LIMIT %d",
			array_merge( $types, array( $source, $limit ) )
		) );
		unset( $args, $holders );

		$out = array();
		foreach ( (array) $ids as $id ) {
			$out[] = array( 'url' => (string) get_permalink( (int) $id ), 'id' => (int) $id );
		}
		if ( count( $out ) >= $limit ) {
			return $out;
		}

		// Room left: the pages waiting longest for a second look. Includes pages
		// that are not posts at all — an archive Bing named, say — because once
		// asked about, they are part of the rotation like anything else.
		$rows = $wpdb->get_results( $wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- our own table.
			"SELECT page_url, page_id FROM $asks WHERE source = %s ORDER BY asked_at ASC LIMIT %d",
			$source,
			$limit - count( $out )
		), ARRAY_A );

		foreach ( (array) $rows as $row ) {
			if ( '' === (string) $row['page_url'] ) {
				continue; // The site-wide set has no page to ask about.
			}
			$out[] = array( 'url' => (string) $row['page_url'], 'id' => (int) $row['page_id'] );
		}
		return $out;
	}

	/**
	 * How many published pages this engine has never been asked about.
	 *
	 * The honest denominator behind "still working through your pages" — a
	 * sentence that means little without a number beside it.
	 *
	 * @param string $source 'bing' or 'google'.
	 * @return int
	 */
	public static function waiting( $source ) {
		global $wpdb;

		$types = self::post_types();
		if ( empty( $types ) ) {
			return 0;
		}
		$asks    = self::name();
		$holders = implode( ',', array_fill( 0, count( $types ), '%s' ) );

		return (int) $wpdb->get_var( $wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- our own table; every value bound.
			"SELECT COUNT(*) FROM {$wpdb->posts} p
			WHERE p.post_status = 'publish' AND p.post_type IN ($holders)
				AND NOT EXISTS (
					SELECT 1 FROM $asks a WHERE a.source = %s AND a.page_key = CONCAT('p', p.ID)
				)",
			array_merge( $types, array( sanitize_key( (string) $source ) ) )
		) );
	}

	/**
	 * How many real pages this engine has been asked about.
	 *
	 * The site-wide set is not a page and is not counted — it carries no page of
	 * its own, and counting it would put the coverage figure one ahead of the
	 * truth on a site that has been asked about nothing.
	 *
	 * @param string $source 'bing' or 'google'.
	 * @return int
	 */
	public static function asked( $source ) {
		global $wpdb;
		$table = self::name();
		// Keyed off the URL, not the key: the site-wide set has an empty URL but
		// still gets a key of its own, so counting keys would report one page
		// more than exists on a site nothing has been asked about.
		return (int) $wpdb->get_var( $wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- our own table.
			"SELECT COUNT(*) FROM $table WHERE source = %s AND page_url <> ''",
			sanitize_key( (string) $source )
		) );
	}

	/**
	 * Drop one source's ledger — its connection is gone, so what it was asked is
	 * no longer a fact about this site.
	 *
	 * @param string $source 'bing' or 'google'.
	 * @return void
	 */
	public static function forget( $source ) {
		global $wpdb;
		$wpdb->delete( self::name(), array( 'source' => sanitize_key( (string) $source ) ), array( '%s' ) );
	}
}

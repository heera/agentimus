<?php
/**
 * One shape for the sources an engine cited, and one answer to "which site is
 * this?".
 *
 * A cited source used to be a bare URL string, which quietly assumed every
 * engine hands back the address of the page it read. Gemini does not: a
 * grounded answer returns Google's own redirector
 * (`vertexaisearch.cloud.google.com/grounding-api-redirect/…`) for every chunk,
 * and names the real site in a sibling field. Reading only the URL therefore
 * printed "vertexaisearch.cloud.google.com" as the source of everything, and
 * made {@see Analyzer::domain_in_citations()} structurally unable to match the
 * owner's own domain — "never linked its site" was arithmetic, not a
 * measurement. His catch, 2026-08-24.
 *
 * So a source is now a pair: the `url` to open, and the `label` the engine gave
 * for the site it stands for ('' when the engine gave none, which is the honest
 * state for the engines that hand back a real address). Plain strings — every
 * row stored before this — normalize into the same pair with an empty label, so
 * old history keeps reading exactly as it always did.
 *
 * @package Agentimus
 */

namespace Agentimus\Visibility;

defined( 'ABSPATH' ) || exit;

final class Sources {

	/**
	 * Normalize a mixed list of citations into { url, label } records.
	 *
	 * Accepts what any provider or any stored row can hold: bare URL strings,
	 * already-normalized records, and (defensively) records missing a half.
	 * A record with neither half is dropped — it names nothing.
	 *
	 * @param array $list Citations as strings and/or records.
	 * @return array[] List of { url: string, label: string }.
	 */
	public static function normalize( array $list ) {
		$out = array();
		foreach ( $list as $item ) {
			if ( is_array( $item ) ) {
				$url   = isset( $item['url'] ) ? trim( (string) $item['url'] ) : '';
				$label = isset( $item['label'] ) ? trim( (string) $item['label'] ) : '';
			} else {
				$url   = trim( (string) $item );
				$label = '';
			}
			if ( '' === $url && '' === $label ) {
				continue;
			}
			$out[] = array(
				'url'   => $url,
				'label' => $label,
			);
		}
		return $out;
	}

	/**
	 * Every host one source could stand for, lowercased and stripped of `www.`:
	 * the label when it names a host, and the URL's own host.
	 *
	 * BOTH, deliberately — a wrapped source knows its site only through the
	 * label, an unwrapped one only through the URL, and a label that is a page
	 * TITLE rather than a host ("Sheikh Heera — Software Developer") names no
	 * host at all and must not be guessed into one.
	 *
	 * @param array|string $source A record or a bare URL.
	 * @return string[] Zero, one or two hosts.
	 */
	public static function hosts( $source ) {
		$records = self::normalize( array( $source ) );
		if ( empty( $records ) ) {
			return array();
		}
		$record = $records[0];

		$hosts = array();
		if ( self::is_host( $record['label'] ) ) {
			$hosts[] = self::bare( $record['label'] );
		}
		$from_url = self::host_from_url( $record['url'] );
		if ( '' !== $from_url ) {
			$hosts[] = $from_url;
		}
		return array_values( array_unique( array_filter( $hosts ) ) );
	}

	/**
	 * The identity a source is shown and deduped by.
	 *
	 * A wrapped source — one whose label names a DIFFERENT host than its URL —
	 * is deduped by that label, so three grounding chunks read from one site
	 * list that site once instead of three times. Everything else keeps its own
	 * URL as the key, because for an engine that hands back real addresses two
	 * pages on one domain are two different sources and each link is worth
	 * keeping.
	 *
	 * @param array|string $source A record or a bare URL.
	 * @return string Lowercased key ('' when the source names nothing).
	 */
	public static function key( $source ) {
		$records = self::normalize( array( $source ) );
		if ( empty( $records ) ) {
			return '';
		}
		$record = $records[0];

		$label = self::is_host( $record['label'] ) ? self::bare( $record['label'] ) : '';
		$url   = self::host_from_url( $record['url'] );
		if ( '' !== $label && $label !== $url ) {
			return $label;
		}
		return strtolower( '' !== $record['url'] ? $record['url'] : $record['label'] );
	}

	/**
	 * Whether a label is a bare hostname rather than prose. Grounding fills the
	 * title with the source's host ("aljazeera.com"), but nothing promises it:
	 * anything carrying a space, a slash or no dotted suffix is a page title and
	 * is left as the display name it is.
	 *
	 * @param string $label Label.
	 * @return bool
	 */
	private static function is_host( $label ) {
		$label = trim( (string) $label );
		if ( '' === $label || strlen( $label ) > 253 ) {
			return false;
		}
		return (bool) preg_match( '~^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)*\.[a-z]{2,}$~i', $label );
	}

	/**
	 * A host without its `www.` prefix, lowercased.
	 *
	 * @param string $host Host.
	 * @return string
	 */
	private static function bare( $host ) {
		return preg_replace( '~^www\.~', '', strtolower( trim( (string) $host ) ) );
	}

	/**
	 * The host of a URL — including a scheme-less one, where wp_parse_url finds
	 * no host and the leading token (minus any path or query) is the host.
	 *
	 * @param string $url URL.
	 * @return string Lowercased host, or ''.
	 */
	private static function host_from_url( $url ) {
		$url = trim( (string) $url );
		if ( '' === $url ) {
			return '';
		}
		$host = wp_parse_url( $url, PHP_URL_HOST );
		if ( ! is_string( $host ) || '' === $host ) {
			$host = preg_replace( '~[/?#].*$~', '', $url );
		}
		return self::bare( $host );
	}
}

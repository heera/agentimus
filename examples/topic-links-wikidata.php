<?php
/**
 * Agentimus — example: link topics to Wikidata (schema.org `about` → `sameAs`).
 *
 * Agentimus publishes each of a post's "Topics for AI" as a schema.org `about`
 * DefinedTerm in the page's JSON-LD. This snippet attaches an authoritative
 * `sameAs` URL to a topic — its Wikidata (or Wikipedia) entry — so an assistant
 * resolves the EXACT entity you mean: "Mercury" the planet vs the element,
 * "Java" the language vs the island.
 *
 * Agentimus deliberately never looks these up itself — it makes no front-end
 * network calls, and a wrong automatic match is worse than none. You supply a
 * small, curated topic → URL map, which is precise and predictable.
 *
 * HOW TO USE: drop this file in wp-content/mu-plugins/ (it loads automatically),
 * or copy the add_filter() call into your own theme/plugin. Edit $map to your
 * topics. To find a Wikidata id, search https://www.wikidata.org — the id is the
 * "Q…" in the entry's URL (e.g. WordPress → Q13166).
 *
 * This file is documentation/example only; Agentimus does not load it.
 *
 * @package Agentimus\Examples
 */

defined( 'ABSPATH' ) || exit;

add_filter(
	'agentimus_topic_links',
	function ( $urls, $topic ) {
		// Your curated map: topic text => authoritative URL (or an array of URLs).
		$map = array(
			'WordPress'               => 'https://www.wikidata.org/wiki/Q13166',
			'PHP'                     => 'https://www.wikidata.org/wiki/Q59',
			'Artificial intelligence' => 'https://www.wikidata.org/wiki/Q11660',
			// Two links are fine — e.g. the Wikidata entry AND your own canonical page:
			// 'Agentimus' => array( 'https://www.wikidata.org/wiki/…', 'https://heera.it/agentimus' ),
		);

		if ( isset( $map[ $topic ] ) ) {
			$urls = array_merge( $urls, (array) $map[ $topic ] );
		}
		return $urls;
	},
	10,
	2
);

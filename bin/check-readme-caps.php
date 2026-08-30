<?php
/**
 * Fail the build when readme.txt would be silently truncated on WordPress.org.
 *
 * ⚠️ THIS EXISTS BECAUSE KNOWING THE CAP WAS NOT ENOUGH. 1.37.0 shipped with a
 * 2,703-word Description and the directory cut it 169 words into the required
 * "External services" disclosure. The cap was known and quoted all evening —
 * what was wrong was the MEASUREMENT: `wc -w` between the Description's own two
 * headings reads 2,463, because WP.org folds every NON-STANDARD section into the
 * Description before applying the limit. A number measured the wrong way is
 * worse than no number, since it reassures.
 *
 * ⚠️⚠️ AND THE COUNTER ITSELF WAS THE SECOND LESSON. This script said the
 * 1.50.1 Changelog stood at 4,857 of 5,000 — WP.org truncated it on import.
 * `str_word_count()` counts only alphabetic runs: "1.50.1", "3,289" and a
 * bare " — " are all invisible to it, and a changelog is made of exactly
 * those. Splitting on whitespace read the same section at 5,019, which is the
 * side of the line WP.org saw. So the count here is WHITESPACE TOKENS: it runs
 * slightly HIGH against theirs (a list's "*" bullets and "= 1.x.x =" entry
 * headings count as tokens), which makes the nominal caps carry their own
 * small safety margin. A counter that errs must err toward the warning.
 *
 * Run:  php bin/check-readme-caps.php readme.txt
 * Exits 1 with a diff-able report when any cap is exceeded.
 */

$file = isset( $argv[1] ) ? $argv[1] : dirname( __DIR__ ) . '/readme.txt';
if ( ! is_readable( $file ) ) {
	fwrite( STDERR, "cannot read $file\n" );
	exit( 2 );
}
$text = file_get_contents( $file );

/** Sections WP.org knows by name. Everything else is folded into the Description. */
$standard = array( 'description', 'installation', 'frequently asked questions', 'screenshots', 'changelog', 'upgrade notice', 'faq' );

$parts       = preg_split( '/^==\s*(.+?)\s*==\s*$/m', $text, -1, PREG_SPLIT_DELIM_CAPTURE );
$description = 0;
$folded      = array();
$changelog   = 0;
$notice      = 0;

/** WP.org-shaped word count: whitespace tokens. See the header for why. */
function ws_words( $body ) {
	return count( preg_split( '/\s+/u', trim( strip_tags( $body ) ), -1, PREG_SPLIT_NO_EMPTY ) );
}

for ( $i = 1; $i < count( $parts ); $i += 2 ) {
	$raw  = $parts[ $i ];
	$name = strtolower( trim( $raw ) );
	$body = isset( $parts[ $i + 1 ] ) ? $parts[ $i + 1 ] : '';

	// `=== Plugin Name ===` captures as "= Name ="; its body is the header block
	// (Contributors, Tags, Requires…), which is metadata, not Description copy.
	if ( strpos( $raw, '=' ) === 0 ) {
		continue;
	}

	if ( 'description' === $name || ! in_array( $name, $standard, true ) ) {
		$n            = ws_words( $body );
		$description += $n;
		$folded[]     = array( trim( $raw ), $n );
	}
	if ( 'changelog' === $name ) {
		$changelog = ws_words( $body );
	}
	if ( 'upgrade notice' === $name ) {
		// The cap bites per ENTRY, and only the newest one is ever shown.
		if ( preg_match( '/^=\s*(.+?)\s*=\s*$(.*?)(?=^=\s|\z)/ms', ltrim( $body ), $m ) ) {
			$notice = strlen( trim( preg_replace( '/\s+/', ' ', $m[2] ) ) );
		}
	}
}

$caps = array(
	// ⚠️ Nominal caps, deliberately. Whitespace tokens run slightly HIGH against
	// WP.org's own count (bullets and entry headings are tokens here, markup
	// there), so the margin is built into the measurement rather than shaved
	// off the cap. Calibration points: the 1.50.1 Changelog read 5,019 here and
	// WP.org truncated it; the same import accepted a Description reading 2,457
	// here. The old str_word_count gate said 4,857 and 2,390 for those — LOW on
	// exactly the section that got cut.
	array( 'Description (all folded sections)', $description, 2500, 'words' ),
	array( 'Changelog',                          $changelog,   5000, 'words' ),
	array( 'Upgrade Notice (newest entry)',      $notice,       600, 'chars' ),
);

echo "readme.txt caps\n\n";
foreach ( $folded as $f ) {
	printf( "  folded into Description: %-28s %5d\n", $f[0], $f[1] );
}
echo "\n";

$failed = 0;
foreach ( $caps as $c ) {
	list( $label, $value, $cap, $unit ) = $c;
	$over = $value > $cap;
	if ( $over ) {
		$failed++;
	}
	printf( "  %-34s %5d / %d %-6s %s\n", $label, $value, $cap, $unit, $over ? 'OVER ✗' : 'ok' );
}

if ( $failed ) {
	echo "\nWordPress.org truncates silently — it does not refuse the import.\n";
	exit( 1 );
}
echo "\nAll within cap.\n";

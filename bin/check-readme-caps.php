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
 * Run:  php dev/check-readme-caps.php readme.txt
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
		$n            = str_word_count( strip_tags( $body ) );
		$description += $n;
		$folded[]     = array( trim( $raw ), $n );
	}
	if ( 'changelog' === $name ) {
		$changelog = str_word_count( strip_tags( $body ) );
	}
	if ( 'upgrade notice' === $name ) {
		// The cap bites per ENTRY, and only the newest one is ever shown.
		if ( preg_match( '/^=\s*(.+?)\s*=\s*$(.*?)(?=^=\s|\z)/ms', ltrim( $body ), $m ) ) {
			$notice = strlen( trim( preg_replace( '/\s+/', ' ', $m[2] ) ) );
		}
	}
}

$caps = array(
	// ⚠️ 2,400 NOT 2,500, and the difference is measured, not cautious. When
	// WordPress.org truncated 1.37.0 it cut at ITS 2,500-word mark; the text it
	// kept measures 2,449 by str_word_count here. So this counter runs ~2% low
	// against theirs — it counts the raw markdown, they count after their own
	// processing. A readme at "2,447 of 2,500" by this script was really sitting
	// ON their line, and stayed truncated. 2,400 here is ~2,450 there.
	array( 'Description (all folded sections)', $description, 2400, 'words' ),
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

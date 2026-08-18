<?php
/**
 * Per-page content-quality checks — the HTML analysis that grades a post for AI
 * readability. These lock the parser (headings/links/images/word counts from real,
 * messy markup) and each check's pass/warn boundary, so the editor panel never
 * mis-grades a page.
 *
 * @package Agentimus\Tests
 */

namespace Agentimus\Tests;

use Agentimus\PageCheck;
use PHPUnit\Framework\TestCase;

final class PageCheckTest extends TestCase {

	/** Invoke a private static check with a stats array. */
	private function check( string $method, array $stats ): array {
		$m = new \ReflectionMethod( PageCheck::class, $method );
		\_af_accessible( $m );
		return (array) $m->invoke( null, $stats );
	}

	/** The raw return — a check may decline to produce a row at all. */
	private function raw( string $method, array $stats ) {
		$m = new \ReflectionMethod( PageCheck::class, $method );
		\_af_accessible( $m );
		return $m->invoke( null, $stats );
	}

	/* -- Cited sources ------------------------------------------------------ */

	public function test_sources_wants_an_outbound_link_on_substantive_pages() {
		$short = $this->check( 'check_sources', array( 'words' => 100, 'outbound_links' => 0 ) );
		$this->assertSame( 'pass', $short['status'], 'Short pages need no references.' );

		$cited = $this->check( 'check_sources', array( 'words' => 800, 'outbound_links' => 2 ) );
		$this->assertSame( 'pass', $cited['status'] );

		$bare = $this->check( 'check_sources', array( 'words' => 800, 'outbound_links' => 0 ) );
		$this->assertSame( 'warn', $bare['status'] );
		$this->assertStringContainsString( 'sources', $bare['detail'] );

		// Sharper than evidence: figures alone satisfy evidence but NOT sources.
		$figures_only = array( 'words' => 800, 'figures' => 9, 'outbound_links' => 0 );
		$this->assertSame( 'pass', $this->check( 'check_evidence', $figures_only )['status'] );
		$this->assertSame( 'warn', $this->check( 'check_sources', $figures_only )['status'] );
	}

	/* -- Reading ease -------------------------------------------------------- */

	public function test_reading_ease_score_orders_plain_before_dense_prose() {
		$plain = PageCheck::stats( '<p>' . str_repeat( 'The cat sat on the mat. We like it here. ', 30 ) . '</p>', false );
		$dense = PageCheck::stats( '<p>' . str_repeat( 'Notwithstanding institutional considerations, epistemological ramifications predominantly characterise infrastructural interoperability initiatives. ', 30 ) . '</p>', false );
		$this->assertGreaterThan( 80, PageCheck::reading_ease( $plain ), 'Simple sentences grade easy.' );
		$this->assertLessThan( 30, PageCheck::reading_ease( $dense ), 'Polysyllabic run-ons grade hard.' );
	}

	public function test_reading_ease_check_bands_and_honest_skips() {
		$easy = array( 'english' => true, 'words' => 300, 'sentences' => 40, 'syllables' => 390 );
		$this->assertSame( 'pass', $this->check( 'check_reading_ease', $easy )['status'] );

		$hard = array( 'english' => true, 'words' => 300, 'sentences' => 10, 'syllables' => 660 );
		$row  = $this->check( 'check_reading_ease', $hard );
		$this->assertSame( 'warn', $row['status'] );
		$this->assertStringContainsString( 'plainer words', $row['detail'] );

		$bengali = $this->check( 'check_reading_ease', array( 'english' => false, 'words' => 900, 'sentences' => 5, 'syllables' => 5000 ) );
		$this->assertSame( 'pass', $bengali['status'], 'Non-English content is skipped, never mis-graded.' );
		$this->assertStringContainsString( 'only fits English', $bengali['detail'] );

		$short = $this->check( 'check_reading_ease', array( 'english' => true, 'words' => 40, 'sentences' => 2, 'syllables' => 60 ) );
		$this->assertSame( 'pass', $short['status'], 'Too short to grade.' );
	}

	public function test_reading_ease_forgives_repeated_subject_terms_not_thesaurus_prose() {
		// "vulnerability" ×40 in otherwise plain sentences: the raw formula grades
		// hard, but the word is the page's own subject — familiar by repetition.
		$topic = PageCheck::stats( '<p>' . str_repeat( 'The vulnerability hurts users. ', 40 ) . '</p>', false );
		$this->assertLessThan( 30, PageCheck::reading_ease( $topic ), 'Raw Flesch charges the topic term every time.' );
		$this->assertGreaterThan( 50, PageCheck::reading_ease_familiar( $topic ), 'Familiar pricing frees the plain prose around it.' );
		$this->assertSame( array( 'vulnerability' => 40 ), $topic['familiar_terms'] );
		$this->assertSame( array(), $topic['heavy_words'] );

		$row = $this->check( 'check_reading_ease', $topic + array( 'english' => true ) );
		$this->assertSame( 'pass', $row['status'] );
		$this->assertStringContainsString( 'vulnerability', $row['detail'], 'The pass names the terms it forgave.' );
	}

	public function test_stats_prices_recurring_long_words_as_familiar() {
		// Three uses make a subject term (discounted); one use stays a heavy word.
		$s = PageCheck::stats( '<p>vulnerability vulnerability vulnerability epistemology</p>', false );
		$this->assertSame( array( 'vulnerability' => 3 ), $s['familiar_terms'] );
		$this->assertSame( array( 'epistemology' => 1 ), $s['heavy_words'] );
		// Each familiar occurrence is repriced from its 6 estimated syllables to 2.
		$this->assertSame( $s['syllables'] - ( 6 - 2 ) * 3, $s['familiar_syllables'] );

		// Hyphenated terms keep their hyphen for display; case variants merge.
		$h = PageCheck::stats( '<p>AI-generated code. Ai-generated code. AI-generated code.</p>', false );
		$this->assertSame( array( 'ai-generated' => 3 ), $h['familiar_terms'] );
	}

	public function test_reading_ease_check_scores_with_the_familiar_adjustment() {
		// Topic-heavy but plainly written: raw fails the bar, adjusted clears it.
		$topic = array( 'english' => true, 'words' => 300, 'sentences' => 40, 'syllables' => 700, 'familiar_syllables' => 500, 'familiar_terms' => array( 'security' => 14, 'ecosystem' => 7 ), 'heavy_words' => array() );
		$row   = $this->check( 'check_reading_ease', $topic );
		$this->assertSame( 'pass', $row['status'] );
		$this->assertStringContainsString( 'security', $row['detail'] );

		// Verbose prose with no recurring subject gets no discount — and the warn
		// names the heaviest words so the author knows where the weight sits.
		$verbose = array( 'english' => true, 'words' => 300, 'sentences' => 40, 'syllables' => 700, 'familiar_syllables' => 700, 'familiar_terms' => array(), 'heavy_words' => array( 'notwithstanding' => 2, 'epistemological' => 1 ) );
		$row     = $this->check( 'check_reading_ease', $verbose );
		$this->assertSame( 'warn', $row['status'] );
		$this->assertStringContainsString( 'notwithstanding', $row['detail'] );

		// The difficulty band reads from the adjusted score — the fair number.
		$midway = array( 'english' => true, 'words' => 300, 'sentences' => 40, 'syllables' => 800, 'familiar_syllables' => 560, 'familiar_terms' => array( 'vulnerability' => 8 ), 'heavy_words' => array() );
		$row    = $this->check( 'check_reading_ease', $midway );
		$this->assertSame( 'warn', $row['status'] );
		$this->assertStringContainsString( 'college', $row['detail'], 'Raw −26 is university band; adjusted 41 is college.' );

		// A 49.5 floors to 49 on a warn row — never "score 50" on a failed check.
		$edge = array( 'english' => true, 'words' => 300, 'sentences' => 40, 'syllables' => 531, 'familiar_syllables' => 531, 'familiar_terms' => array(), 'heavy_words' => array() );
		$row  = $this->check( 'check_reading_ease', $edge );
		$this->assertSame( 'warn', $row['status'] );
		$this->assertStringContainsString( 'score 49', $row['detail'] );
	}

	public function test_reading_ease_grades_prose_not_code() {
		// A tutorial: plain prose around a snippet full of identifier soup. The
		// snippet counts as substance (words) but never as prose (reading ease).
		$html = '<p>' . str_repeat( 'Now we add the route. It maps the url. ', 15 ) . '</p>'
			. '<pre>App\Http\Middleware\InputValidator::registerApplicationConfiguration($environmentRepository);</pre>'
			. '<p>Call <code>wp_insert_post()</code> to save it.</p>';
		$s = PageCheck::stats( $html, false );

		$this->assertGreaterThan( $s['prose_words'], $s['words'], 'Code still counts toward substance.' );
		$this->assertSame( array(), $s['heavy_words'], 'Identifiers are not heavy words.' );
		$this->assertSame( array(), $s['familiar_terms'] );
		$this->assertGreaterThan( 80, PageCheck::reading_ease( $s ), 'Simple tutorial prose grades easy despite the snippet.' );

		// A page that is nearly all code has too little prose to grade at all.
		$codeonly = PageCheck::stats( '<p>The full example.</p><pre>' . str_repeat( 'configureMiddlewareValidationPipeline($container); ', 120 ) . '</pre>', false );
		$this->assertGreaterThan( 100, $codeonly['words'] );
		$row = $this->check( 'check_reading_ease', $codeonly + array( 'english' => true ) );
		$this->assertSame( 'pass', $row['status'] );
		$this->assertStringContainsString( 'Too short', $row['detail'] );
	}

	public function test_sentences_end_at_block_boundaries() {
		// Bullets and headings rarely carry a full stop — each block is still its
		// own sentence, not a run-on glued to its neighbours.
		$list = PageCheck::stats( '<p>Valuable skills:</p><ul><li>systems thinking</li><li>debugging skill</li></ul>', false );
		$this->assertSame( 3, $list['sentences'], 'Intro + two bullets, not one glued run-on.' );

		$heading = PageCheck::stats( '<h2>Why It Matters</h2><p>Because it does.</p>', false );
		$this->assertSame( 2, $heading['sentences'], 'A heading is its own sentence.' );

		// Punctuated blocks keep their count — the boundary never double-counts.
		$this->assertSame( 2, PageCheck::stats( '<p>Done.</p><p>Also done.</p>', false )['sentences'] );
		$this->assertSame( 3, PageCheck::stats( '<p>One. Two. Three.</p>', false )['sentences'] );

		// Empty blocks and bare dividers are not sentences.
		$this->assertSame( 1, PageCheck::stats( '<p></p><ul><li> — </li></ul><p>Real.</p>', false )['sentences'] );
	}

	public function test_syllable_estimator_is_sane() {
		$syl = function ( string $w ): int {
			$m = new \ReflectionMethod( PageCheck::class, 'syllables' );
			\_af_accessible( $m );
			return (int) $m->invoke( null, $w );
		};
		$this->assertSame( 1, $syl( 'cat' ) );
		$this->assertSame( 1, $syl( 'make' ), 'Silent e drops.' );
		$this->assertSame( 2, $syl( 'table' ), '-le keeps its syllable.' );
		$this->assertSame( 3, $syl( 'excellent' ) );
		$this->assertSame( 0, $syl( '2024' ), 'Numbers don’t skew the grade.' );
		$this->assertSame( 0, $syl( 'তারা' ), 'Non-Latin tokens count zero.' );
	}

	/* -- stats() parser --------------------------------------------------- */

	public function test_stats_parses_structure() {
		$html = '<h2>A</h2><h3>B</h3><p>one two three</p><a href="#">go now</a><img alt="x" src="a.jpg"><img src="b.jpg">';
		$s    = PageCheck::stats( $html, false );

		$this->assertSame( array( 2, 3 ), $s['headings'] );
		$this->assertSame( array( 3 ), $s['paragraphs'] );   // one paragraph, 3 words
		$this->assertSame( 1, $s['links'] );
		$this->assertSame( 2, $s['link_words'] );            // "go now"
		$this->assertSame( 2, $s['images'] );
		$this->assertSame( 1, $s['images_no_alt'] );         // the second img has no alt
		$this->assertSame( 7, $s['words'] );                 // A B one two three go now
	}

	public function test_stats_treats_empty_alt_as_intentional_decorative() {
		// alt="" is the WAI marker for a decorative image — not a missing-alt gap. Only a
		// truly absent alt attribute counts.
		$s = PageCheck::stats( '<img src="deco.jpg" alt=""><img src="hero.jpg" alt="A chart"><img src="x.jpg">', false );
		$this->assertSame( 3, $s['images'] );
		$this->assertSame( 1, $s['images_no_alt'] );         // only the third img (no alt attr)
	}

	public function test_stats_tolerates_empty_and_plain() {
		$this->assertSame( 0, PageCheck::stats( '', false )['words'] );
		$this->assertSame( array(), PageCheck::stats( '', false )['headings'] );
	}

	/* -- individual checks ------------------------------------------------ */

	public function test_thin_content_warns() {
		$this->assertSame( 'warn', $this->check( 'check_words', array( 'words' => 20 ) )['status'] );
		$this->assertSame( 'pass', $this->check( 'check_words', array( 'words' => 500 ) )['status'] );
	}

	public function test_headings_only_expected_on_long_content() {
		// Long content, no headings → warn.
		$this->assertSame( 'warn', $this->check( 'check_headings', array( 'words' => 800, 'headings' => array() ) )['status'] );
		// Short content, no headings → pass (doesn't need them).
		$this->assertSame( 'pass', $this->check( 'check_headings', array( 'words' => 120, 'headings' => array() ) )['status'] );
		// Long content WITH headings → pass.
		$this->assertSame( 'pass', $this->check( 'check_headings', array( 'words' => 800, 'headings' => array( 2, 2 ) ) )['status'] );
	}

	public function test_heading_order_flags_skipped_levels() {
		$this->assertSame( 'warn', $this->check( 'check_heading_order', array( 'headings' => array( 2, 4 ) ) )['status'] );
		$this->assertSame( 'pass', $this->check( 'check_heading_order', array( 'headings' => array( 2, 3, 3, 4 ) ) )['status'] );
		$this->assertSame( 'pass', $this->check( 'check_heading_order', array( 'headings' => array() ) )['status'] );
	}

	public function test_link_density_warns_when_nav_heavy() {
		// 60 words, 40 of them linked → 66% → warn.
		$this->assertSame( 'warn', $this->check( 'check_link_density', array( 'words' => 60, 'link_words' => 40 ) )['status'] );
		// Normal prose → pass.
		$this->assertSame( 'pass', $this->check( 'check_link_density', array( 'words' => 300, 'link_words' => 20 ) )['status'] );
		// Too short to judge → pass.
		$this->assertSame( 'pass', $this->check( 'check_link_density', array( 'words' => 30, 'link_words' => 25 ) )['status'] );
	}

	public function test_alt_text_warns_on_missing() {
		$this->assertSame( 'warn', $this->check( 'check_alt_text', array( 'images' => 3, 'images_no_alt' => 1 ) )['status'] );
		$this->assertSame( 'pass', $this->check( 'check_alt_text', array( 'images' => 3, 'images_no_alt' => 0 ) )['status'] );
		$this->assertSame( 'pass', $this->check( 'check_alt_text', array( 'images' => 0, 'images_no_alt' => 0 ) )['status'] );
	}

	public function test_summary_prefers_excerpt_or_lead() {
		$base = array( 'words' => 400, 'paragraphs' => array( 3 ), 'has_excerpt' => false );
		// Weak lead, no excerpt → warn.
		$this->assertSame( 'warn', $this->check( 'check_summary', $base )['status'] );
		// Manual excerpt → pass.
		$this->assertSame( 'pass', $this->check( 'check_summary', array_merge( $base, array( 'has_excerpt' => true ) ) )['status'] );
		// Substantive first paragraph → pass.
		$this->assertSame( 'pass', $this->check( 'check_summary', array_merge( $base, array( 'paragraphs' => array( 25 ) ) ) )['status'] );
		// Thin page → stands down (thin check owns it) → pass.
		$this->assertSame( 'pass', $this->check( 'check_summary', array( 'words' => 40, 'paragraphs' => array( 2 ), 'has_excerpt' => false ) )['status'] );
	}

	/* -- video legibility -------------------------------------------------- */

	public function test_stats_sees_players_captions_and_transcripts() {
		// A YouTube embed carries no words at all — the gap the check exists for.
		$embed = PageCheck::stats( '<figure class="wp-block-embed"><iframe src="https://www.youtube.com/embed/abc123" title="Talk"></iframe></figure><p>Watch the talk.</p>' );
		$this->assertSame( 1, $embed['videos'] );
		$this->assertFalse( $embed['has_transcript'] );

		// A bare iframe is never a video here, known host or not.
		$map = PageCheck::stats( '<iframe src="https://example.com/widget"></iframe>' );
		$this->assertSame( 0, $map['videos'] );

		// Self-hosted video, with captions already attached.
		$hosted = PageCheck::stats( '<video src="/wp-content/uploads/talk.mp4"><track kind="captions" src="/talk.vtt"></video>' );
		$this->assertSame( 1, $hosted['videos'] );
		$this->assertSame( 1, $hosted['video_captions'] );

		// A <track> with no src is a declaration, not a captions file.
		$empty_track = PageCheck::stats( '<video src="/talk.mp4"><track kind="captions" src=""></video>' );
		$this->assertSame( 0, $empty_track['video_captions'] );

		// Both ways an author writes a transcript.
		$this->assertTrue( PageCheck::stats( '<h2>Transcript</h2><p>So today…</p>' )['has_transcript'] );
		$this->assertTrue( PageCheck::stats( '<details><summary>Full transcript</summary><p>So today…</p></details>' )['has_transcript'] );

		// Regression, caught on a live site: an embed block holds a BARE URL until
		// WordPress's autoembed swaps in the player, and autoembed only runs inside
		// the the_content chain. Anything scanning a plain block render — the editor
		// screen deciding whether to offer the transcript field, the schema pass —
		// saw no iframe and concluded the post had no video at all.
		$unresolved = PageCheck::stats( '<figure class="wp-block-embed is-type-video is-provider-youtube"><div class="wp-block-embed__wrapper">https://www.youtube.com/watch?v=dQw4w9WgXcQ</div></figure>' );
		$this->assertSame( 1, $unresolved['videos'], 'An unresolved embed block is still a video.' );

		// …and once it IS resolved, the figure and its iframe are one video, not two.
		$resolved = PageCheck::stats( '<figure class="wp-block-embed is-type-video"><div class="wp-block-embed__wrapper"><iframe src="https://www.youtube.com/embed/dQw4w9WgXcQ"></iframe></div></figure>' );
		$this->assertSame( 1, $resolved['videos'] );

		// An embed block for something that isn't a video stays uncounted.
		$tweet = PageCheck::stats( '<figure class="wp-block-embed is-type-rich"><div class="wp-block-embed__wrapper">https://example.com/status/123</div></figure>' );
		$this->assertSame( 0, $tweet['videos'] );

		// WordPress's OWN classification is the authority: a provider missing from
		// our host list is still a video when the embed block says it is. This is
		// what makes the feature cover providers added after it was written.
		$unknown = PageCheck::stats( '<figure class="wp-block-embed is-type-video is-provider-newthing"><div class="wp-block-embed__wrapper">https://newthing.example/v/42</div></figure>' );
		$this->assertSame( 1, $unknown['videos'] );

		// …and once such an embed resolves, the figure and its player are one video.
		$unknown_resolved = PageCheck::stats( '<figure class="wp-block-embed is-type-video"><div class="wp-block-embed__wrapper"><iframe src="https://newthing.example/embed/42"></iframe></div></figure>' );
		$this->assertSame( 1, $unknown_resolved['videos'] );

		// A hand-written <iframe> outside any embed block is NOT graded — whatever
		// an author put in their own markup is their business, not this plugin's.
		$this->assertSame( 0, PageCheck::stats( '<iframe src="https://player.vimeo.com/video/76979871"></iframe>' )['videos'] );
		$this->assertSame( 0, PageCheck::stats( '<iframe src="https://www.facebook.com/plugins/page.php?href=x"></iframe>' )['videos'] );

		// A <video>/<audio> ELEMENT is still graded: that is what core's own media
		// blocks render, and it is a player by definition rather than by guesswork.
		$this->assertSame( 1, PageCheck::stats( '<video src="https://example.com/talk.mp4"></video>' )['videos'] );

		// Merely talking about transcripts is not having one.
		$this->assertFalse(
			PageCheck::stats( '<p>You should always publish a transcript alongside your video, because assistants cannot hear.</p>' )['has_transcript'],
			'Prose that mentions transcripts must not read as a transcript.'
		);
	}

	public function test_the_media_row_asks_whether_an_agent_can_tell_what_it_is() {
		// No media — the row stands down.
		$this->assertSame( 'pass', $this->check( 'check_media', array( 'videos' => 0, 'audios' => 0, 'words' => 20 ) )['status'] );

		// The gap: a page that IS the player, and says nothing about it.
		$bare = $this->check( 'check_media', array( 'videos' => 1, 'audios' => 0, 'words' => 30, 'media_described' => 0 ) );
		$this->assertSame( 'warn', $bare['status'] );
		$this->assertStringContainsString( 'say what it is about', $bare['detail'] );
		// It must NOT demand a transcript — that belongs to whatever tool already
		// owns it, and asking for one is how this feature went wrong the first time.
		$this->assertStringNotContainsString( 'transcript', strtolower( $bare['detail'] ) );

		// Three separate things settle it, and the row names which one did.
		$described = $this->check( 'check_media', array( 'videos' => 2, 'audios' => 0, 'words' => 5, 'media_described' => 2 ) );
		$this->assertSame( 'pass', $described['status'] );
		$this->assertStringContainsString( 'line of context', $described['detail'] );

		$transcribed = $this->check( 'check_media', array( 'videos' => 1, 'audios' => 0, 'words' => 5, 'media_described' => 0, 'has_transcript' => true ) );
		$this->assertSame( 'pass', $transcribed['status'] );

		$written = $this->check( 'check_media', array( 'videos' => 1, 'audios' => 0, 'words' => 400, 'media_described' => 0 ) );
		$this->assertSame( 'pass', $written['status'] );

	}

	public function test_audio_is_graded_exactly_like_video() {
		// A podcast episode has the same problem and is the commonest case of all;
		// it was excluded from this feature entirely until the redesign.
		$row = $this->check( 'check_media', array( 'videos' => 0, 'audios' => 1, 'words' => 30, 'media_described' => 0 ) );
		$this->assertSame( 'warn', $row['status'] );
		$this->assertStringContainsString( 'audio item', $row['detail'] );
	}

	public function test_a_partly_described_page_says_how_many_are_left() {
		$row = $this->check( 'check_media', array( 'videos' => 3, 'audios' => 0, 'words' => 10, 'media_described' => 1 ) );
		$this->assertSame( 'warn', $row['status'] );
		$this->assertStringContainsString( '2 of 3 videos', $row['detail'] );
	}

	public function test_media_notes_never_prop_up_the_length_row() {
		// His call, and the right one: notes make a page LEGIBLE, not SUBSTANTIAL.
		// Letting captions clear a bar calibrated for prose would turn a page that is
		// five videos and five one-liners green — the opposite of what this grades.
		// The media row credits that work; this row keeps telling the truth.
		$row = $this->check( 'check_words', array( 'words' => 27, 'media_described' => 5 ) );

		$this->assertSame( 'warn', $row['status'] );
		$this->assertStringContainsString( '27 words', $row['detail'] );
		$this->assertStringNotContainsString( 'describing the media', $row['detail'] );

		// And the page's own words still carry it when there are enough of them.
		$this->assertSame( 'pass', $this->check( 'check_words', array( 'words' => 400 ) )['status'] );
	}

	public function test_the_media_row_outlives_the_thin_content_row() {
		// 120 words clears MIN_WORDS, so the length check passes it — while nothing
		// still says what the video holds. The two rows must disagree here, or the gap
		// this feature exists for goes unreported.
		$s = array( 'videos' => 1, 'audios' => 0, 'words' => 120, 'media_described' => 0, 'paragraphs' => array( 40 ) );
		$this->assertSame( 'pass', $this->check( 'check_words', $s )['status'] );
		$this->assertSame( 'warn', $this->check( 'check_media', $s )['status'] );
	}

	/* -- citability signals ----------------------------------------------- */

	public function test_stats_counts_figures_and_outbound_sources() {
		$html = '<p>In 2024, about 42% grew. '
			. '<a href="https://external.example/report">source</a> '
			. '<a href="https://mysite.test/x">internal</a> '
			. '<a href="/rel">relative</a></p>';
		$s = PageCheck::stats( $html, false, 'mysite.test' );
		$this->assertSame( 2, $s['figures'] );        // "2024" and "42%"
		$this->assertSame( 1, $s['outbound_links'] ); // only the off-site host counts
	}

	public function test_evidence_wants_figures_or_sources_on_substantial_pages() {
		// Short page → not expected → pass.
		$this->assertSame( 'pass', $this->check( 'check_evidence', array( 'words' => 80, 'figures' => 0, 'outbound_links' => 0 ) )['status'] );
		// Substantial with figures → pass.
		$this->assertSame( 'pass', $this->check( 'check_evidence', array( 'words' => 400, 'figures' => 3, 'outbound_links' => 0 ) )['status'] );
		// Substantial with a cited source → pass.
		$this->assertSame( 'pass', $this->check( 'check_evidence', array( 'words' => 400, 'figures' => 0, 'outbound_links' => 1 ) )['status'] );
		// Substantial with nothing concrete → warn.
		$this->assertSame( 'warn', $this->check( 'check_evidence', array( 'words' => 400, 'figures' => 0, 'outbound_links' => 0 ) )['status'] );
	}

	public function test_passages_flags_one_over_long_block() {
		// A single over-long paragraph on a substantial page → warn.
		$this->assertSame( 'warn', $this->check( 'check_passages', array( 'words' => 400, 'paragraphs' => array( 40, 200, 30 ) ) )['status'] );
		// Reasonable paragraphs → pass.
		$this->assertSame( 'pass', $this->check( 'check_passages', array( 'words' => 400, 'paragraphs' => array( 60, 80, 50 ) ) )['status'] );
		// Short page → pass regardless.
		$this->assertSame( 'pass', $this->check( 'check_passages', array( 'words' => 60, 'paragraphs' => array( 60 ) ) )['status'] );
	}

	public function test_freshness_flags_stale_substantial_pages() {
		// Old + substantial → warn.
		$this->assertSame( 'warn', $this->check( 'check_freshness', array( 'words' => 400, 'age_days' => 800 ) )['status'] );
		// Recently updated → pass.
		$this->assertSame( 'pass', $this->check( 'check_freshness', array( 'words' => 400, 'age_days' => 30 ) )['status'] );
		// Age unknown → nothing to claim → pass.
		$this->assertSame( 'pass', $this->check( 'check_freshness', array( 'words' => 400, 'age_days' => 0 ) )['status'] );
		// Thin page → the thin check owns it → pass.
		$this->assertSame( 'pass', $this->check( 'check_freshness', array( 'words' => 50, 'age_days' => 900 ) )['status'] );
	}

	public function test_featured_image_expected_only_where_supported() {
		// Type/theme without featured-image support → honest skip → pass.
		$this->assertSame( 'pass', $this->check( 'check_featured_image', array( 'featured_expected' => false, 'featured' => false ) )['status'] );
		// Supported and set → pass.
		$this->assertSame( 'pass', $this->check( 'check_featured_image', array( 'featured_expected' => true, 'featured' => true ) )['status'] );
		// Supported and missing → warn, and the detail says what's lost.
		$row = $this->check( 'check_featured_image', array( 'featured_expected' => true, 'featured' => false ) );
		$this->assertSame( 'warn', $row['status'] );
		$this->assertStringContainsString( 'link previews', $row['detail'] );
	}

	public function test_freshness_exempts_evergreen_content() {
		// Old + substantial would warn, but an evergreen-marked post is timeless → pass.
		$stale = array( 'words' => 400, 'age_days' => 800 );
		$this->assertSame( 'warn', $this->check( 'check_freshness', $stale )['status'] );
		$stale['evergreen'] = true;
		$this->assertSame( 'pass', $this->check( 'check_freshness', $stale )['status'] );
	}

	/* -- summary() -------------------------------------------------------- */

	public function test_summary_counts_by_status() {
		$rows = array(
			array( 'status' => 'pass' ),
			array( 'status' => 'warn' ),
			array( 'status' => 'warn' ),
		);
		$this->assertSame( array( 'pass' => 1, 'warn' => 2, 'fail' => 0 ), PageCheck::summary( $rows ) );
	}

	/* -- Naming the offender ------------------------------------------------- */

	/**
	 * ⭐ THE ROW HAS TO SAY WHICH PARAGRAPH. It used to report max() alone — so a
	 * page with three long blocks warned about "~239 words", the owner fixed that
	 * one, and the warning returned saying "~184". Nothing in the sentence had
	 * told them there was ever more than one. Walked live on heera.it 2026-08-18;
	 * it cost a whole round of edits.
	 */
	public function test_a_page_with_several_long_blocks_says_how_many_and_where_each_starts() {
		$long_a = 'Alpha opens the first overlong block here ' . str_repeat( 'and it keeps going on ', 40 );
		$long_b = 'Bravo opens the second overlong block here ' . str_repeat( 'and it also keeps going ', 40 );
		$stats  = PageCheck::stats( '<p>' . $long_a . '</p><p>short one</p><p>' . $long_b . '</p>', false );

		$row = $this->check( 'check_passages', $stats );

		$this->assertSame( 'warn', $row['status'] );
		$this->assertSame( '2 long blocks', $row['label'], 'The label must not say "One" when there are two.' );
		$this->assertStringContainsString( '2 paragraphs run over', $row['detail'], 'The count is the whole point.' );
		$this->assertStringContainsString( 'Alpha opens the first overlong block', $row['detail'] );
		$this->assertStringContainsString( 'Bravo opens the second overlong block', $row['detail'] );
	}

	/** One offender still reads as one, and still says where it starts. */
	public function test_a_single_long_block_names_its_opening_words() {
		$stats = PageCheck::stats( '<p>Charlie opens the only overlong block here ' . str_repeat( 'and on it goes ', 45 ) . '</p>', false );

		$row = $this->check( 'check_passages', $stats );

		$this->assertSame( 'warn', $row['status'] );
		$this->assertSame( 'One long block', $row['label'] );
		$this->assertStringContainsString( 'Charlie opens the only overlong block', $row['detail'] );
	}

	/**
	 * ⛔ A caller holding only counts (any hand-built stats array) must still get
	 * the plain sentence — never an empty "It starts ." where the words would be.
	 */
	public function test_a_stats_array_without_the_openings_still_reads_as_a_sentence() {
		$row = $this->check( 'check_passages', array( 'words' => 900, 'paragraphs' => array( 40, 300 ) ) );

		$this->assertSame( 'warn', $row['status'] );
		$this->assertStringContainsString( '~300 words', $row['detail'] );
		$this->assertStringNotContainsString( '“”', $row['detail'], 'An empty quote is worse than no quote.' );
	}

	/** The picture is named, because "1 of 3" cannot be acted on. */
	public function test_missing_alt_text_names_the_file() {
		$stats = PageCheck::stats(
			'<p>Body.</p><img src="https://x.test/wp-content/uploads/river-at-dusk.png?v=2"><img src="/a/described.jpg" alt="A described one">',
			false
		);

		$row = $this->check( 'check_alt_text', $stats );

		$this->assertSame( 'warn', $row['status'] );
		$this->assertStringContainsString( 'river-at-dusk.png', $row['detail'], 'Name the file; the owner is scanning a media library.' );
		$this->assertStringContainsString( '1 of 2 images has no alt text', $row['detail'], 'One missing takes the singular verb.' );
		$this->assertStringNotContainsString( '?v=2', $row['detail'], 'A query string is noise in a file name.' );
		$this->assertStringNotContainsString( 'described.jpg', $row['detail'], 'Only the ones actually missing alt text.' );
	}

	/** ⛔ No "(s)". A count of one reads as one. */
	public function test_counts_are_written_as_singular_or_plural_never_with_a_bracketed_s() {
		$one_link = $this->check( 'check_sources', array( 'words' => 800, 'outbound_links' => 1 ) );
		$this->assertStringContainsString( '1 outbound link ', $one_link['detail'] );
		$this->assertStringNotContainsString( '(s)', $one_link['detail'] );

		$one_heading = $this->check( 'check_headings', array( 'words' => 900, 'headings' => array( 2 ) ) );
		$this->assertStringContainsString( '1 heading gives', $one_heading['detail'] );
		$this->assertStringNotContainsString( '(s)', $one_heading['detail'] );

		$many = $this->check( 'check_headings', array( 'words' => 900, 'headings' => array( 2, 2, 3 ) ) );
		$this->assertStringContainsString( '3 headings give', $many['detail'] );
	}

	/** A skipped heading level names the heading it happens at. */
	public function test_a_heading_jump_names_the_heading() {
		$stats = PageCheck::stats( '<h2>Getting started</h2><p>a</p><h4>Swapping implementations here</h4>', false );

		$row = $this->check( 'check_heading_order', $stats );

		$this->assertSame( 'warn', $row['status'] );
		$this->assertStringContainsString( 'H2 → H4', $row['detail'] );
		$this->assertStringContainsString( 'Swapping implementations here', $row['detail'], '"H2 → H4" alone is unactionable on a page with nine headings.' );
	}

	/** ⛔ And a caller with levels but no text still gets a whole sentence. */
	public function test_a_heading_jump_without_text_still_reads_cleanly() {
		$row = $this->check( 'check_heading_order', array( 'headings' => array( 2, 4 ) ) );

		$this->assertSame( 'warn', $row['status'] );
		$this->assertStringContainsString( 'H2 → H4', $row['detail'] );
		$this->assertStringNotContainsString( '“”', $row['detail'] );
		$this->assertStringNotContainsString( '.  ', $row['detail'], 'A missing clause must not leave a double space.' );
	}

	/**
	 * ⚠️ "1 of 1 images has no alt text" — both numbers said one, the noun said
	 * many, and the file name then said it a second time. Seen on a real post
	 * (heera.it, 2026-08-18). Each count combination gets its own true sentence.
	 */
	public function test_the_alt_text_count_reads_as_a_sentence_at_every_ratio() {
		$only = $this->check( 'check_alt_text', PageCheck::stats( '<p>x</p><img src="/u/GetSetGo.png">', false ) );
		$this->assertStringNotContainsString( '1 of 1', $only['detail'] );
		$this->assertStringStartsWith( 'No description on', $only['detail'], 'With one image, naming it IS the sentence.' );

		$all = $this->check( 'check_alt_text', PageCheck::stats( '<p>x</p><img src="/u/a.png"><img src="/u/b.png"><img src="/u/c.png">', false ) );
		$this->assertStringContainsString( 'None of the 3 images has alt text', $all['detail'] );
		$this->assertStringNotContainsString( '3 of 3', $all['detail'] );

		$some = $this->check( 'check_alt_text', PageCheck::stats( '<p>x</p><img src="/u/a.png"><img src="/u/b.png"><img src="/u/c.png" alt="c">', false ) );
		$this->assertStringContainsString( '2 of 3 images have no alt text', $some['detail'], 'Two missing takes the plural verb.' );
	}

	/**
	 * ⭐ ALT TEXT THAT IS ONLY THE FILE NAME IS AN ABSENCE WEARING THE ATTRIBUTE.
	 * The check used to see `alt` present and pass the page; an assistant reading
	 * "screen-shot-2016-09-15-at-5-00-13-am" learns nothing. His own post caught
	 * it (heera.it post 2276, 2026-08-18).
	 */
	public function test_alt_text_that_is_just_the_file_name_does_not_pass() {
		$row = $this->check( 'check_alt_text', PageCheck::stats(
			'<p>x</p><img src="https://heera.it/wp-content/uploads/2016/09/Screen-Shot-2016-09-15-at-5.00.13-AM-e1473894420914.png" alt="screen-shot-2016-09-15-at-5-00-13-am">',
			false
		) );

		$this->assertSame( 'warn', $row['status'], 'The attribute being present is not the same as the image being described.' );
		$this->assertStringContainsString( 'described only by its file name', $row['detail'] );
		// ⭐ THE FILE, NOT THE ALT. An owner cannot open a string: he read the alt
		// back, went to the media library and described a DIFFERENT picture.
		$this->assertStringContainsString( 'Screen-Shot-2016-09-15-at-5.00.13-AM.png', $row['detail'], 'Name the file the owner has to open.' );
		$this->assertStringNotContainsString( 'e1473894420914', $row['detail'], 'WordPress\'s edit suffix is not part of the name the library lists.' );
	}

	/**
	 * ⛔ THE FALSE POSITIVE THIS MUST NEVER HAVE. A descriptive file name with a
	 * matching descriptive alt is GOOD writing, not a slug — the tell is that a
	 * description has spaces in it.
	 */
	public function test_a_real_description_matching_its_file_name_still_passes() {
		$row = $this->check( 'check_alt_text', PageCheck::stats( '<p>x</p><img src="/u/red-fox-in-snow.jpg" alt="Red fox in snow">', false ) );

		$this->assertSame( 'pass', $row['status'] );
	}

	/** WordPress size and edit suffixes must not hide a file-name alt. */
	public function test_a_resized_file_name_is_still_recognised() {
		$row = $this->check( 'check_alt_text', PageCheck::stats( '<p>x</p><img src="/u/holiday-shot-760x480.jpg" alt="holiday-shot">', false ) );

		$this->assertSame( 'warn', $row['status'] );
	}

	/** Both kinds on one page are both reported, in one sentence each. */
	public function test_a_missing_description_and_a_file_name_alt_are_both_named() {
		$row = $this->check( 'check_alt_text', PageCheck::stats( '<p>x</p><img src="/u/plain.png"><img src="/u/my-photo-2.jpg" alt="my-photo-2">', false ) );

		$this->assertSame( 'warn', $row['status'] );
		$this->assertStringContainsString( 'plain.png', $row['detail'] );
		$this->assertStringContainsString( 'my-photo-2', $row['detail'] );
	}

	/** The closing instruction agrees with how many there are. */
	public function test_the_replace_instruction_agrees_with_the_count() {
		$two = $this->check( 'check_alt_text', PageCheck::stats( '<p>x</p><img src="/u/dsc-0091.jpg" alt="dsc-0091"><img src="/u/img-4432.png" alt="img-4432">', false ) );

		$this->assertStringContainsString( 'Replace their alt text', $two['detail'] );
		$this->assertStringNotContainsString( 'Replace its alt text', $two['detail'] );
		$this->assertStringContainsString( 'dsc-0091.jpg', $two['detail'], 'Both files are named, not just counted.' );
	}

	/**
	 * ⛔ THE SECOND FALSE POSITIVE, found by scanning his live site before this
	 * shipped: alt="table" on table.png. It matches the file name and has no
	 * spaces, but a person may simply have typed the word — "you copied the file
	 * name" is an accusation we cannot support for a single common word. Only a
	 * multi-part slug is machine-made beyond doubt.
	 */
	public function test_a_single_word_alt_is_never_called_a_file_name() {
		foreach ( array( 'table', 'logo', 'diagram', 'screenshot' ) as $word ) {
			$row = $this->check( 'check_alt_text', PageCheck::stats( '<p>x</p><img src="/u/' . $word . '.png" alt="' . $word . '">', false ) );
			$this->assertSame( 'pass', $row['status'], sprintf( 'alt="%s" is terse, not proof of a copied file name.', $word ) );
		}

		// The multi-part slugs it exists to catch are untouched by that guard.
		foreach ( array( 'dsc-0091', 'img-4432', 'holiday-shot' ) as $slug ) {
			$row = $this->check( 'check_alt_text', PageCheck::stats( '<p>x</p><img src="/u/' . $slug . '.jpg" alt="' . $slug . '">', false ) );
			$this->assertSame( 'warn', $row['status'], sprintf( 'alt="%s" is a file name.', $slug ) );
		}
	}

	/* -- The featured image's own description ------------------------------- */

	/**
	 * ⭐ THE GAP THE PANEL NEVER COVERED. The featured image is drawn by the
	 * THEME, so it never appears in the content this class parses — a picture
	 * with no description sailed past every check. His catch, 2026-08-18.
	 *
	 * ⛔ The claim is deliberately about the ATTACHMENT, not the rendered page.
	 * Judging the render would need an HTTP request from a panel that runs on
	 * every editor load, and it cannot be inferred either: the-alpha substitutes
	 * the post title inline in single.php rather than through a filter, so
	 * get_the_post_thumbnail() in wp-admin returns alt="" and would accuse a page
	 * that IS described. "No description of its own" is true either way.
	 */
	public function test_a_featured_image_with_no_description_is_reported() {
		$row = $this->check( 'check_featured_alt', array(
			'featured_expected' => true,
			'featured'          => true,
			'featured_alt'      => '',
			'featured_file'     => 'laravel-development.jpg',
		) );

		$this->assertSame( 'warn', $row['status'] );
		$this->assertStringContainsString( 'laravel-development.jpg', $row['detail'], 'Name the file the owner has to open.' );
		$this->assertStringContainsString( 'screen readers', $row['detail'], 'It is not an AI-only concern.' );
	}

	/** A described featured image passes, and a file-name alt does not. */
	public function test_the_featured_image_description_is_judged_like_any_other() {
		$good = $this->check( 'check_featured_alt', array(
			'featured_expected' => true, 'featured' => true,
			'featured_alt' => 'The Laravel framework logo on a red banner', 'featured_file' => 'laravel-development.jpg',
		) );
		$this->assertSame( 'pass', $good['status'] );

		$slug = $this->check( 'check_featured_alt', array(
			'featured_expected' => true, 'featured' => true,
			'featured_alt' => 'dsc-0091', 'featured_file' => 'dsc-0091.jpg',
		) );
		$this->assertSame( 'warn', $slug['status'] );
		$this->assertStringContainsString( 'described only by its file name', $slug['detail'] );
	}

	/**
	 * ⭐ HIS CATCH, 2026-08-19, on a live post about Composer supply-chain
	 * security: the row advised "shorter sentences and plainer words" over prose
	 * averaging eleven words a sentence. Half of that could not be followed —
	 * there was nothing to shorten — and on that page the sentences cost the
	 * score 11 points while the vocabulary cost it 180. A row that asks for work
	 * the page has already done teaches an owner to skim it.
	 */
	public function test_the_reading_ease_row_names_the_half_that_is_actually_off() {
		$dense_but_short = $this->check( 'check_reading_ease', array(
			'english'      => true,
			'prose_words'  => 753,
			'words'        => 785,
			'sentences'    => 67,   // 11 words a sentence — nothing to shorten.
			'syllables'    => 1604, // …and 2.13 syllables a word, which is the story.
			'heavy_words'  => array( 'immutability' => 2, 'organizations' => 2 ),
		) );

		$this->assertSame( 'warn', $dense_but_short['status'] );
		$this->assertStringContainsString( 'already short', $dense_but_short['detail'] );
		$this->assertStringContainsString( '11 words on average', $dense_but_short['detail'] );
		$this->assertStringNotContainsString( 'Shorter sentences', $dense_but_short['detail'], '⛔ Never ask for work the page has already done.' );

		// …and where the sentences ARE long, the original advice stands.
		$long_sentences = $this->check( 'check_reading_ease', array(
			'english'     => true,
			'prose_words' => 800,
			'words'       => 800,
			'sentences'   => 20,   // 40 words a sentence.
			'syllables'   => 1400, // 1.75 — ordinary vocabulary.
		) );
		$this->assertStringContainsString( 'Shorter sentences', $long_sentences['detail'] );
	}

	/**
	 * ⭐⭐ THE FALSE PASS, and it took reading the served page to see it. The
	 * owner wrote a description; the theme serves the post title instead. The
	 * media library looks perfect, every assistant and screen reader gets the
	 * article's title where the picture should be described, and the old check —
	 * which could only see the library — called it a pass.
	 */
	public function test_a_theme_that_ignores_the_description_is_named_as_the_problem() {
		$row = $this->check( 'check_featured_alt', array(
			'featured_expected' => true,
			'featured'          => true,
			'featured_alt'      => 'The Laravel framework logo on a red banner',
			'featured_file'     => 'laravel-development.jpg',
			'served_alt'        => \Agentimus\ThemeImageProbe::USES_TITLE,
			'served_alt_bare'   => \Agentimus\ThemeImageProbe::USES_TITLE,
		) );

		$this->assertSame( 'warn', $row['status'] );
		$this->assertStringContainsString( 'never reaches a reader', $row['detail'] );
		// ⛔ And it says WHOSE fault it is. Telling an owner to describe a picture
		// they already described is advice that cannot be followed.
		$this->assertStringContainsString( 'theme fix', $row['detail'] );
	}

	/**
	 * With no description of its own, what the page SERVES decides the verdict:
	 * an empty alt is a real failure, and a theme standing the post title in its
	 * place is a lesser one — the picture is undescribed, but something is there.
	 */
	public function test_an_undescribed_picture_is_judged_by_what_the_page_serves() {
		$empty = $this->check( 'check_featured_alt', array(
			'featured_expected' => true, 'featured' => true, 'featured_alt' => '',
			'featured_file'     => 'laravel-development.jpg',
			'served_alt'        => \Agentimus\ThemeImageProbe::USES_LIBRARY,
			'served_alt_bare'   => \Agentimus\ThemeImageProbe::USES_NOTHING,
		) );
		$this->assertSame( 'fail', $empty['status'], 'Read, and served with nothing at all — that is provable now.' );
		$this->assertStringContainsString( 'no description at all', $empty['detail'] );

		$title = $this->check( 'check_featured_alt', array(
			'featured_expected' => true, 'featured' => true, 'featured_alt' => '',
			'featured_file'     => 'laravel-development.jpg',
			'served_alt'        => \Agentimus\ThemeImageProbe::USES_LIBRARY,
			'served_alt_bare'   => \Agentimus\ThemeImageProbe::USES_TITLE,
		) );
		$this->assertSame( 'warn', $title['status'] );
		$this->assertStringContainsString( 'title in its place', $title['detail'] );
	}

	/**
	 * ⛔ FAIL-OPEN. Nothing has read the served page, so the check says the one
	 * thing it can prove and never a worse verdict on the strength of a fetch
	 * that did not happen.
	 */
	public function test_with_no_reading_of_the_page_the_claim_stays_the_narrow_one() {
		$row = $this->check( 'check_featured_alt', array(
			'featured_expected' => true, 'featured' => true, 'featured_alt' => '',
			'featured_file'     => 'laravel-development.jpg',
		) );

		$this->assertSame( 'warn', $row['status'], 'Never fail on an unread page.' );
		$this->assertStringContainsString( 'no description of its own', $row['detail'] );
	}

	/** A pass says more only when the page was actually read. */
	public function test_the_pass_claims_the_served_page_only_when_it_was_read() {
		$described = array(
			'featured_expected' => true, 'featured' => true,
			'featured_alt'      => 'The Laravel framework logo on a red banner',
			'featured_file'     => 'laravel-development.jpg',
		);
		$this->assertStringNotContainsString( 'serve', $this->check( 'check_featured_alt', $described )['detail'] );

		$described['served_alt'] = \Agentimus\ThemeImageProbe::USES_LIBRARY;
		$read = $this->check( 'check_featured_alt', $described );
		$this->assertSame( 'pass', $read['status'] );
		$this->assertStringContainsString( 'pages serve it', $read['detail'] );
	}

	/**
	 * ⛔ NO ROW when there is nothing to judge. A green "nothing to check here"
	 * sitting under "No featured image" is two rows discussing one absence.
	 */
	public function test_the_row_is_absent_rather_than_green_when_there_is_no_featured_image() {
		$this->assertNull( $this->raw( 'check_featured_alt', array( 'featured_expected' => true, 'featured' => false ) ) );
		$this->assertNull( $this->raw( 'check_featured_alt', array( 'featured_expected' => false ) ) );
		// A caller holding only counts must not be told its image is undescribed.
		$this->assertNull( $this->raw( 'check_featured_alt', array( 'featured_expected' => true, 'featured' => true ) ) );
	}
}

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
		$m->setAccessible( true );
		return (array) $m->invoke( null, $stats );
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
			$m->setAccessible( true );
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
}

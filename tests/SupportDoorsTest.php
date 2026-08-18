<?php
/**
 * The two doors out of the plugin, and the one thing that silently breaks them.
 *
 * ⭐ A kind that names a template file which is not in the repository sends every
 * reporter who picks it to a GitHub page that shrugs — no form, no label, and no
 * hint to the maintainer that a whole category of reports stopped arriving. That
 * failure is invisible from inside the plugin, which is exactly why it is worth a
 * test: the two halves live in different languages, in different directories, and
 * nothing else joins them up.
 *
 * @package Agentimus\Tests
 */

namespace Agentimus\Tests;

use Agentimus\Support;
use PHPUnit\Framework\TestCase;

final class SupportDoorsTest extends TestCase {

	/** Where the issue forms live, from this file. */
	private function templates_dir() {
		return dirname( __DIR__ ) . '/.github/ISSUE_TEMPLATE';
	}

	public function test_every_kind_points_at_a_template_that_exists() {
		$dir = $this->templates_dir();
		$this->assertDirectoryExists( $dir, 'The issue forms are what carry the labels — without them the kind means nothing.' );

		foreach ( Support::kinds() as $kind ) {
			$this->assertFileExists(
				$dir . '/' . $kind['template'],
				sprintf( 'The "%s" kind opens %s, which is not in the repository.', $kind['id'], $kind['template'] )
			);
		}
	}

	public function test_every_template_declares_a_label() {
		// ⛔ An unlabelled issue falls through every filter the maintainer has —
		// which is worse than the category not being offered at all.
		foreach ( Support::kinds() as $kind ) {
			$yaml = (string) file_get_contents( $this->templates_dir() . '/' . $kind['template'] );
			$this->assertMatchesRegularExpression(
				'/^labels: \[".+"\]/m',
				$yaml,
				sprintf( '%s declares no labels, so anything filed through it arrives untriaged.', $kind['template'] )
			);
		}
	}

	public function test_the_environment_field_id_matches_what_the_url_fills() {
		// The plugin pre-fills `env`. A template that renamed the field would
		// still open — it would just quietly drop the setup block on the floor.
		//
		// ⚠️ This test used to gate on the word "Setup" appearing in the file, so
		// renaming that label to "Environment details" made it skip every
		// template and assert nothing. PHPUnit called it risky, which is the only
		// reason it was noticed. Gate on the STRUCTURE the plugin depends on —
		// which templates carry the field — never on a word somebody may reword.
		$carries = array( 'bug.yml', 'integration.yml', 'idea.yml', 'other.yml' );

		foreach ( $carries as $file ) {
			$yaml = (string) file_get_contents( $this->templates_dir() . '/' . $file );
			$this->assertMatchesRegularExpression(
				'/^    id: env$/m',
				$yaml,
				sprintf( '%s has no `env` field, so the setup block the plugin sends lands nowhere.', $file )
			);
		}

		// docs.yml deliberately carries none: which page is wrong does not turn
		// on the reporter's PHP version.
		$docs = (string) file_get_contents( $this->templates_dir() . '/docs.yml' );
		$this->assertDoesNotMatchRegularExpression( '/^    id: env$/m', $docs );
	}

	public function test_blank_issues_are_disabled() {
		// The cheapest anti-junk control there is, and the one that makes every
		// report arrive through a form that already asks the useful questions.
		$config = (string) file_get_contents( $this->templates_dir() . '/config.yml' );
		$this->assertStringContainsString( 'blank_issues_enabled: false', $config );
	}

	public function test_the_forum_is_offered_as_a_contact_link() {
		// The other door has to exist on GitHub's own chooser too — somebody who
		// arrives at the repo directly should still be pointed at the forum.
		$config = (string) file_get_contents( $this->templates_dir() . '/config.yml' );
		$this->assertStringContainsString( 'wordpress.org/support/plugin/agentimus', $config );
	}

	public function test_the_issue_url_carries_a_template_and_never_a_labels_parameter() {
		$url = Support::issue_url( 'bug', false );

		$this->assertStringContainsString( 'template=bug.yml', $url );
		// ⛔ GitHub 404s `labels=` for anyone without triage permission on the
		// repo — which is every person this feature exists for. The label must
		// come from the template, never the query string.
		$this->assertStringNotContainsString( 'labels=', $url );
		$this->assertStringContainsString( '/issues/new', $url );
	}

	public function test_the_facts_survive_a_missing_template() {
		// ⭐ Found by walking it: with the forms unpushed, GitHub ignored both
		// `template` and `env` and dropped the reporter on a blank issue — the
		// setup block evaporated and neither side would ever have known. `body`
		// is what a blank form fills from, so the versions still arrive.
		$url = Support::issue_url( 'bug', false );

		$this->assertStringContainsString( 'body=', $url );
		$this->assertStringContainsString( rawurlencode( 'Agentimus' ), $url );
	}

	public function test_the_payload_carries_a_finished_url_for_every_kind() {
		// ⚠️⚠️ THE REGRESSION THIS EXISTS FOR. The dialog used to build the URL
		// itself, so the format lived in PHP and in the Vue computed at once —
		// and when `body` was added here, the tests went green while the button
		// on screen kept emitting the old two-parameter link. A test that
		// green-lights an unused path is worse than no test. The payload now
		// carries finished strings, so what is asserted here is what ships.
		$payload = Support::payload();

		$this->assertArrayHasKey( 'urls', $payload );
		foreach ( Support::kinds() as $kind ) {
			$this->assertArrayHasKey( $kind['id'], $payload['urls'] );
			foreach ( array( 'site', 'lean' ) as $variant ) {
				$url = $payload['urls'][ $kind['id'] ][ $variant ];
				$this->assertStringContainsString( 'template=' . $kind['template'], $url );
				$this->assertStringContainsString( 'body=', $url, 'The blank-form fallback must be on every link, not just the one the tests happened to call.' );
				$this->assertStringNotContainsString( 'labels=', $url );
			}
		}
	}

	public function test_the_site_address_is_in_one_variant_and_not_the_other() {
		$urls = Support::payload()['urls']['bug'];

		$this->assertStringContainsString( rawurlencode( 'Site' ), $urls['site'] );
		$this->assertStringNotContainsString( rawurlencode( 'Site  ' ), $urls['lean'] );
	}

	public function test_an_unknown_kind_still_opens_a_form() {
		// A stale deep link, or a filter adding a kind: it must land on the
		// catch-all rather than on a blank page.
		$this->assertStringContainsString( 'template=other.yml', Support::issue_url( 'nonsense', false ) );
	}
}

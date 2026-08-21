<?php
/**
 * Set-aside, against a real grade store.
 *
 * The unit tests pin the decision and the declared shape. This one walks the
 * path an agent actually walks: publish something the checks flag, let the sweep
 * read it, set it aside, and then ask the SAME question `read-content-issues`
 * answers — because the whole point is that one surface's write is the other
 * surface's reading. A set-aside that the worklist keeps listing has done
 * nothing; a count that moves while the buckets do not is two answers to one
 * question.
 *
 * ⚠️ Driven through {@see Triage}, not through wp_get_ability(). The write tier
 * only registers while the owner's switch is on and the registry is built once
 * per process, so in a full-suite run an earlier test has already built it with
 * writes off — the ability being absent there is the feature, not a fault. The
 * tool is a thin wrapper over these calls; its gate is asserted separately below.
 *
 * @package Agentimus\Tests\Integration
 */

namespace Agentimus\Tests\Integration;

use Agentimus\Abilities\Registrar;
use Agentimus\Abilities\Triage;
use Agentimus\Grades;
use Agentimus\Settings;
use Agentimus\Worklist;

final class SetAsideDbTest extends DbTestCase {

	public function set_up(): void {
		parent::set_up();
		delete_option( Grades::VERSION_OPTION );
		Grades::install();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$settings = new Settings();
		$all      = $settings->all(); // Partial updates reset unset settings — merge into all().
		$all['enable_mcp_server']   = true;
		$all['enable_agent_writes'] = true;
		$settings->update( $all );
	}

	/** A published post thin enough that the content checks flag it. */
	private function flagged( $title = 'Thin' ) {
		return (int) self::factory()->post->create( array(
			'post_status'  => 'publish',
			'post_title'   => $title,
			'post_content' => 'Too short.',
		) );
	}

	/** Read the whole site, the way the cron does. */
	private function sweep() {
		( new Worklist( new Settings() ) )->sweep( 200 );
	}

	private function triage() {
		return new Triage( new Settings() );
	}

	/** The ids the worklist puts in one bucket, as an agent would read them. */
	private function bucket( $filter ) {
		$out = ( new Worklist( new Settings() ) )->issues( $filter, 1, 30 );
		return array_map( 'intval', array_column( $out['items'], 'id' ) );
	}

	/* ------------------------------------------------------- the round trip */

	/**
	 * ⭐ THE WHOLE FEATURE IN ONE TEST: the page leaves the list an agent works
	 * from, lands in the one the owner reviews, and the counts move with it —
	 * both directions, from the same call.
	 */
	public function test_a_page_set_aside_leaves_the_worklist_and_comes_back() {
		$id = $this->flagged();
		$this->sweep();

		$this->assertContains( $id, $this->bucket( 'fixable' ), 'Precondition: the page has to be ON the list before setting it aside can mean anything.' );
		$before = ( new Worklist( new Settings() ) )->counts();

		$out = $this->triage()->set_aside( $id, true );
		$this->assertNotWPError( $out );
		$this->assertTrue( $out['aside'] );
		$this->assertTrue( $out['changed'] );
		$this->assertSame( $id, $out['postId'] );
		$this->assertSame( 'Thin', $out['title'], 'The answer names the page, so an agent can say which one it acted on.' );

		$this->assertNotContains( $id, $this->bucket( 'fixable' ), 'Set aside and still on the worklist is a write that did nothing.' );
		$this->assertContains( $id, $this->bucket( 'setAside' ), 'It has to be VISIBLE in the owner’s own list — this is never a hidden ledger.' );
		$this->assertSame( $before['fixable'] - 1, $out['counts']['fixable'] );
		$this->assertSame( $before['setAside'] + 1, $out['counts']['setAside'] );

		// …and back.
		$back = $this->triage()->set_aside( $id, false );
		$this->assertNotWPError( $back );
		$this->assertFalse( $back['aside'] );
		$this->assertTrue( $back['changed'] );
		$this->assertContains( $id, $this->bucket( 'fixable' ) );
		$this->assertSame( $before['fixable'], $back['counts']['fixable'], 'Putting it back returns the count it took away.' );
	}

	/**
	 * ⛔ The counts in the answer must be the SAME counts read-content-issues
	 * reports — his law, one quantity one owner. An agent that trusts a tally the
	 * write tool computed for itself will contradict the screen beside it.
	 */
	public function test_the_counts_it_answers_with_are_the_worklists_own() {
		$this->flagged( 'One' );
		$this->flagged( 'Two' );
		$id = $this->flagged( 'Three' );
		$this->sweep();

		$out       = $this->triage()->set_aside( $id, true );
		$worklist  = ( new Worklist( new Settings() ) )->issues( 'fixable', 1, 30 );

		$this->assertSame( $worklist['counts'], $out['counts'], 'Two surfaces counting the same thing must read one count.' );
	}

	/** A second identical call is not a second act, and must not report as one. */
	public function test_setting_aside_twice_reports_the_second_call_honestly() {
		$id = $this->flagged();
		$this->sweep();

		$this->assertTrue( $this->triage()->set_aside( $id, true )['changed'] );

		$again = $this->triage()->set_aside( $id, true );
		$this->assertNotWPError( $again, 'Asking for a state it is already in is not an error.' );
		$this->assertTrue( $again['aside'] );
		$this->assertFalse( $again['changed'], 'A no-op reporting as a change is how an agent claims work it did not do.' );
		$this->assertCount( 1, (array) ( new Settings() )->get( 'optimize_ignored', array() ), 'It must not be written twice.' );
	}

	/** The store is the last word: the id is really in the owner's option. */
	public function test_it_writes_the_owners_own_list_and_nothing_else() {
		$id     = $this->flagged();
		$before = ( new Settings() )->all();
		$this->sweep();

		$this->triage()->set_aside( $id, true );

		$after = ( new Settings() )->all();
		$this->assertSame( array( $id ), array_map( 'intval', (array) $after['optimize_ignored'] ) );

		// ⛔ And the search worklist's own ledger is untouched: "don't grade this
		// for quoting" and "don't suggest search fixes for this" are separate
		// judgements, and one call must never make both.
		$this->assertSame( (array) $before['search_ignored'], (array) $after['search_ignored'] );
	}

	/**
	 * ⛔ REGRESSION, caught live on heera.it 2026-08-22. The answer used a bare
	 * get_the_title() while the worklist decoded — so post 1024 came back as
	 * "Php Dynamic Getter &#038; Setter" from this tool and "Php Dynamic Getter &
	 * Setter" from read-content-issues. An agent does not render HTML: it repeats
	 * what it is handed, into prose and sometimes back into a write.
	 */
	public function test_it_names_the_page_exactly_as_the_worklist_names_it() {
		$id = (int) self::factory()->post->create( array(
			'post_status'  => 'publish',
			'post_title'   => 'Getters & Setters <em>by</em> Overloading',
			'post_content' => 'Too short.',
		) );
		$this->sweep();

		$row = null;
		foreach ( ( new Worklist( new Settings() ) )->issues( 'fixable', 1, 30 )['items'] as $item ) {
			if ( (int) $item['id'] === $id ) {
				$row = $item;
			}
		}
		$this->assertNotNull( $row, 'Precondition: the page has to be on the list to compare the two names.' );

		$out = $this->triage()->set_aside( $id, true );

		$this->assertSame( $row['title'], $out['title'], 'Two surfaces naming one page must say the same name.' );
		$this->assertStringNotContainsString( '&#0', $out['title'], 'Entity-encoded — an agent will repeat this verbatim.' );
		$this->assertStringNotContainsString( '<em>', $out['title'], 'Markup has no meaning to a caller that does not render it.' );
		$this->assertSame( 'Getters & Setters by Overloading', $out['title'] );
	}

	/* ----------------------------------------------------------- refusals */

	public function test_it_refuses_a_page_that_is_not_published() {
		$draft = (int) self::factory()->post->create( array( 'post_status' => 'draft' ) );

		$out = $this->triage()->set_aside( $draft, true );

		$this->assertWPError( $out );
		$this->assertSame( 'agentimus_not_published', $out->get_error_code() );
		$this->assertSame( array(), (array) ( new Settings() )->get( 'optimize_ignored', array() ), 'A refusal must not half-write.' );
	}

	public function test_it_refuses_an_id_no_post_carries() {
		$out = $this->triage()->set_aside( 999999, true );
		$this->assertWPError( $out );
		$this->assertSame( 'agentimus_not_found', $out->get_error_code() );
	}

	/**
	 * ⭐ A page parked and then unpublished must still be clearable — otherwise
	 * the entry is stranded on the list with no call able to reach it.
	 */
	public function test_a_parked_page_can_be_put_back_after_it_is_unpublished() {
		$id = $this->flagged();
		$this->sweep();
		$this->triage()->set_aside( $id, true );

		wp_update_post( array( 'ID' => $id, 'post_status' => 'draft' ) );

		$out = $this->triage()->set_aside( $id, false );
		$this->assertNotWPError( $out, 'A parked entry must always be clearable.' );
		$this->assertSame( array(), (array) ( new Settings() )->get( 'optimize_ignored', array() ) );
	}

	/* -------------------------------------------------------- the exposure */

	public function test_it_reaches_the_mcp_server_only_while_writes_are_allowed() {
		$tools = ( new Registrar( new Settings() ) )->mcp_abilities();
		$this->assertContains( 'agentimus/set-aside-page', $tools );

		$settings = new Settings();
		$all      = $settings->all();
		$all['enable_agent_writes'] = false;
		$settings->update( $all );

		$off = ( new Registrar( new Settings() ) )->mcp_abilities();
		$this->assertNotContains( 'agentimus/set-aside-page', $off, 'Off must mean it does not exist on any surface.' );
		$this->assertContains( 'agentimus/read-content-issues', $off, 'Reading the worklist is not a write.' );
	}
}

<?php
/**
 * GET /visibility/answer/<id> — one stored check, whole.
 *
 * The door behind the verdict chip. Until it existed the only way to see what
 * an engine actually said was a hover bubble holding the first 600 characters:
 * unselectable, unreachable on a phone, and gone the moment the pointer moved.
 * The answer is the evidence behind a verdict, so it gets a surface you can
 * read — and a route of its own, because a list read must not carry every
 * answer just to draw a summary.
 *
 * @package Agentimus\Tests\Integration
 */

namespace Agentimus\Tests\Integration;

use Agentimus\Visibility\Store;
use Agentimus\Visibility\Table;

final class VisibilityAnswerRestTest extends RestTestCase {

	private const ROUTE = '/agentimus/v1/visibility/answer';

	public function set_up(): void {
		parent::set_up();
		Table::install();
		global $wpdb;
		$wpdb->query( 'TRUNCATE TABLE ' . Table::name() );
	}

	/** One stored check; returns its row id. */
	private function stored( array $overrides = array() ) {
		Store::insert(
			array_merge(
				array(
					'run_id'      => 1,
					'brand'       => 'Acme',
					'provider'    => 'gemini',
					'model'       => 'gemini-2.5-flash',
					'prompt'      => 'who makes acme tools',
					'mentioned'   => true,
					'cited'       => true,
					'position'    => 1,
					'competitors' => array(),
					'answer'      => 'Acme is made by Acme Corp. ' . str_repeat( 'It has done so since 1953. ', 60 ),
					'sources'     => array( array( 'url' => 'https://vertexaisearch.cloud.google.com/grounding-api-redirect/A', 'label' => 'acme.test' ) ),
					'error'       => '',
				),
				$overrides
			)
		);
		global $wpdb;
		return (int) $wpdb->insert_id;
	}

	public function test_the_route_exists_at_all() {
		$this->assertArrayHasKey( self::ROUTE . '/(?P<id>\d+)', rest_get_server()->get_routes(), 'The chip opens a dialog; it needs a door to knock on.' );
	}

	/** Someone else's citation history is nobody else's business. */
	public function test_only_someone_who_runs_this_site_can_read_an_answer() {
		$id = $this->stored();
		foreach ( array( 0, $this->subscriber, $this->editor ) as $user ) {
			wp_set_current_user( $user );
			$this->assertContains(
				rest_do_request( new \WP_REST_Request( 'GET', self::ROUTE . '/' . $id ) )->get_status(),
				array( 401, 403 )
			);
		}
	}

	public function test_it_returns_the_answer_in_full_with_what_it_cited() {
		wp_set_current_user( $this->admin );
		$id = $this->stored();

		$response = rest_do_request( new \WP_REST_Request( 'GET', self::ROUTE . '/' . $id ) );
		$this->assertSame( 200, $response->get_status() );

		$data = $response->get_data();
		$this->assertTrue( $data['hasFullAnswer'] );
		$this->assertStringContainsString( 'since 1953', $data['answer'], 'the whole answer, not its opening' );
		$this->assertGreaterThan( mb_strlen( $data['excerpt'] ), mb_strlen( $data['answer'] ) );
		$this->assertSame( 'who makes acme tools', $data['prompt'] );
		$this->assertSame( 'gemini', $data['provider'] );
		$this->assertTrue( $data['cited'] );
		$this->assertSame( 'acme.test', $data['sources'][0]['label'], 'the site a wrapped source stands for' );
	}

	/** A check that never finished has no answer — it has a reason. */
	public function test_a_failed_check_carries_its_error() {
		wp_set_current_user( $this->admin );
		$id = $this->stored( array( 'answer' => '', 'error' => 'You exceeded your current quota.', 'mentioned' => false, 'cited' => false ) );

		$data = rest_do_request( new \WP_REST_Request( 'GET', self::ROUTE . '/' . $id ) )->get_data();
		$this->assertSame( 'You exceeded your current quota.', $data['error'] );
		$this->assertFalse( $data['hasFullAnswer'] );
	}

	/** History can be cleared, and rows age out of the retention window. */
	public function test_a_check_that_is_gone_is_a_404_not_an_empty_answer() {
		wp_set_current_user( $this->admin );
		$response = rest_do_request( new \WP_REST_Request( 'GET', self::ROUTE . '/999999' ) );
		$this->assertSame( 404, $response->get_status() );
	}
}

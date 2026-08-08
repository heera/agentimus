<?php
/**
 * GET /worklist/rows — the findings hand-off's other half.
 *
 * A finding names exact pages; the worklist payload carries only its
 * shortlist. This route fetches the named rows so "Show me that page" always
 * lands on the page it counted — the day it didn't, heera.it showed a finding
 * claiming one page and a list showing none.
 *
 * @package Agentimus\Tests\Integration
 */

namespace Agentimus\Tests\Integration;

final class WorklistRowsRestTest extends RestTestCase {

	public function test_named_published_posts_come_back_as_rows() {
		wp_set_current_user( $this->admin );

		$a = self::factory()->post->create( array(
			'post_title'   => 'Alpha post',
			'post_content' => '<p>Enough words to grade — a plain paragraph about a plain thing.</p>',
			'post_status'  => 'publish',
		) );
		$b = self::factory()->post->create( array(
			'post_title'   => 'Beta post',
			'post_content' => '<p>Another gradable paragraph, short and clear.</p>',
			'post_status'  => 'publish',
		) );

		$req = new \WP_REST_Request( 'GET', '/agentimus/v1/worklist/rows' );
		$req->set_param( 'ids', "$a,$b" );
		$res = rest_get_server()->dispatch( $req );

		$this->assertSame( 200, $res->get_status() );
		$rows = $res->get_data()['rows'];
		$this->assertCount( 2, $rows );
		$this->assertSame( array( $a, $b ), array_column( $rows, 'id' ), 'rows arrive in the order asked for' );
	}

	/**
	 * A page that stopped being published drops out rather than coming back as
	 * a row — the screen's picked-empty copy is what explains the absence, and
	 * it can only be honest if absence is what the route actually reports.
	 */
	public function test_unpublished_and_garbage_ids_drop_out_silently() {
		wp_set_current_user( $this->admin );

		$live  = self::factory()->post->create( array( 'post_status' => 'publish', 'post_content' => '<p>Still here.</p>' ) );
		$draft = self::factory()->post->create( array( 'post_status' => 'draft' ) );

		$req = new \WP_REST_Request( 'GET', '/agentimus/v1/worklist/rows' );
		$req->set_param( 'ids', "$live,$draft,abc,0" );
		$res = rest_get_server()->dispatch( $req );

		$this->assertSame( 200, $res->get_status() );
		$rows = $res->get_data()['rows'];
		$this->assertCount( 1, $rows );
		$this->assertSame( $live, $rows[0]['id'] );
	}

	public function test_no_usable_ids_is_an_empty_answer_not_an_error() {
		wp_set_current_user( $this->admin );

		$req = new \WP_REST_Request( 'GET', '/agentimus/v1/worklist/rows' );
		$req->set_param( 'ids', 'abc,,0' );
		$res = rest_get_server()->dispatch( $req );

		$this->assertSame( 200, $res->get_status() );
		$this->assertSame( array(), $res->get_data()['rows'] );
	}
}

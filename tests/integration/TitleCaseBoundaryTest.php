<?php
class TitleCaseBoundaryTest extends WP_UnitTestCase {
	public function test_the_model_gets_title_case_and_the_owner_does_not() {
		$json = wp_json_encode( array(
			'title'   => 'a practical guide to backups',
			'content' => '<p>Body text that is long enough to pass.</p>',
			'excerpt' => 'x',
		) );
		$fromModel = \Agentimus\Assistant::parse_draft( $json );
		$this->assertSame( 'A Practical Guide to Backups', $fromModel['title'],
			'Model output still gets the standing rule.' );

		$typed = \Agentimus\Assistant::sanitize_draft( array(
			'title'   => 'a practical guide to backups',
			'content' => '<p>Body text that is long enough to pass.</p>',
		) );
		$this->assertSame( 'a practical guide to backups', $typed['title'],
			'A title the owner typed is left exactly as typed.' );
	}
	public function test_an_owner_edited_outline_keeps_its_own_casing() {
		$edited = \Agentimus\Assistant::sanitize_outline( array(
			'title'    => 'my banner image for the post',
			'sections' => array( array( 'heading' => 'One' ) ),
		) );
		$this->assertSame( 'my banner image for the post', $edited['title'] );
	}
}

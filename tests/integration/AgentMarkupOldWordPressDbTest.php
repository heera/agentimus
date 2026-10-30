<?php
/**
 * Below WP 6.5 there is no HTML parser, so AgentMarkup asks kses instead. kses also
 * tidies harmless bytes (it quotes attributes and encodes "&"), and tidying must not
 * read as unsafe.
 *
 * ⭐ Found by CI's WP 6.0 job: comparing kses's output byte for byte refused ordinary
 * edits, because an <img> tag rarely comes back exactly as it went in.
 *
 * @package Agentimus\Tests\Integration
 */

namespace Agentimus\Tests\Integration;

use Agentimus\Abilities\AgentMarkup;

final class AgentMarkupOldWordPressDbTest extends DbTestCase {

	public function set_up(): void {
		parent::set_up();
		if ( method_exists( 'WP_HTML_Tag_Processor', 'next_token' ) ) {
			$this->markTestSkipped( 'This WordPress has the parser; AgentMarkupDbTest covers it.' );
		}
	}

	/** @return array<string,array{string}> */
	public function harmless() {
		return array(
			'an ampersand'         => array( 'Tips & Tricks' ),
			'a less-than in text'  => array( '2 < 3' ),
			'an image'             => array( '<figure><img src="https://example.org/a.png" alt="A picture" width="10"/></figure>' ),
			'an unquoted attribute' => array( '<img src=a.png alt=picture>' ),
			'a link with a query'  => array( '<a href="https://example.org/?a=1&b=2">x</a>' ),
			'block markup'         => array( "<!-- wp:image {\"id\":12} -->\n<figure class=\"wp-block-image\"><img src=\"a.png\" alt=\"\"/></figure>\n<!-- /wp:image -->" ),
		);
	}

	/** @dataProvider harmless */
	public function test_tidying_is_not_refused( $html ) {
		$this->assertNull( AgentMarkup::refusal( $html ) );
	}

	/** @return array<string,array{string}> */
	public function unsafe_markup() {
		return array(
			'script'           => array( '<p>x</p><script>alert(1)</script>' ),
			'event handler'    => array( '<img src="a.png" onerror="alert(1)">' ),
			'javascript: link' => array( '<a href="javascript:alert(1)">x</a>' ),
			'entity scheme'    => array( '<a href="&#106;avascript:alert(1)">x</a>' ),
			'hidden scheme'    => array( '<a href="java&#10;script:alert(1)">x</a>' ),
			'iframe'           => array( '<iframe src="https://evil.test"></iframe>' ),
			'svg'              => array( '<svg onload="alert(1)"></svg>' ),
			'style element'    => array( '<style>body{display:none}</style>' ),
		);
	}

	/** @dataProvider unsafe_markup */
	public function test_what_kses_removes_is_refused( $html ) {
		$refusal = AgentMarkup::refusal( $html );
		$this->assertWPError( $refusal );
		$this->assertSame( 'agentimus_unsafe_markup', $refusal->get_error_code() );
	}
}

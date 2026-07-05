<?php
/**
 * Visibility provider HTTP layer — output-token caps (so a run's per-check cost is
 * bounded) and the shape guard that turns a malformed 2xx into an error instead of
 * a fabricated "not mentioned" data point, while letting a legitimately empty
 * answer (a refusal) through. Exercised against the harness's HTTP stub.
 *
 * @package Agentimus\Tests
 */

namespace Agentimus\Tests;

use Agentimus\Visibility\Providers\Anthropic;
use Agentimus\Visibility\Providers\Gemini;
use Agentimus\Visibility\Providers\OpenAI;
use Agentimus\Visibility\Providers\Perplexity;
use PHPUnit\Framework\TestCase;

final class VisibilityProviderTest extends TestCase {

	protected function setUp(): void {
		\_af_reset_options();
	}

	protected function tearDown(): void {
		\_af_reset_options();
	}

	/** Queue one canned HTTP response for the next wp_remote_post. */
	private function queue( $code, array $body ) {
		$GLOBALS['_af_http_queue'][] = array(
			'response' => array( 'code' => $code ),
			'body'     => json_encode( $body ),
			'headers'  => array(),
		);
	}

	/** The decoded JSON body of the last request the provider sent. */
	private function last_body() {
		return json_decode( $GLOBALS['_af_http_last']['args']['body'], true );
	}

	/* -- Output-token caps: every provider bounds the answer length -------- */

	public function test_openai_chat_sends_a_positive_output_token_cap() {
		$this->queue( 200, array( 'choices' => array( array( 'message' => array( 'content' => 'hi' ) ) ) ) );
		( new OpenAI() )->query( 'q', 'k', 'gpt-4o-mini' );
		$body = $this->last_body();
		$this->assertArrayHasKey( 'max_tokens', $body );
		$this->assertGreaterThan( 0, $body['max_tokens'] );
	}

	public function test_openai_web_search_sends_a_positive_output_token_cap() {
		$this->queue( 200, array( 'output' => array( array( 'type' => 'message', 'content' => array( array( 'type' => 'output_text', 'text' => 'hi' ) ) ) ) ) );
		( new OpenAI() )->query( 'q', 'k', 'gpt-4.1', true );
		$body = $this->last_body();
		$this->assertArrayHasKey( 'max_output_tokens', $body );
		$this->assertGreaterThan( 0, $body['max_output_tokens'] );
	}

	public function test_perplexity_sends_a_positive_output_token_cap() {
		$this->queue( 200, array( 'choices' => array( array( 'message' => array( 'content' => 'hi' ) ) ) ) );
		( new Perplexity() )->query( 'q', 'k', 'sonar' );
		$body = $this->last_body();
		$this->assertArrayHasKey( 'max_tokens', $body );
		$this->assertGreaterThan( 0, $body['max_tokens'] );
	}

	public function test_gemini_sends_a_positive_output_token_cap() {
		$this->queue( 200, array( 'candidates' => array( array( 'content' => array( 'parts' => array( array( 'text' => 'hi' ) ) ) ) ) ) );
		( new Gemini() )->query( 'q', 'k', 'gemini-2.0-flash' );
		$body = $this->last_body();
		$this->assertArrayHasKey( 'generationConfig', $body );
		$this->assertGreaterThan( 0, $body['generationConfig']['maxOutputTokens'] );
	}

	/* -- Malformed 2xx → error; a shaped-but-empty answer is valid --------- */

	public function test_a_200_with_an_unexpected_shape_is_an_error_not_a_fake_empty_answer() {
		// A proxy/HTML error page, or a changed response format: HTTP 200, but none of
		// the expected answer containers. Recording it as "not mentioned" would poison
		// the metric, so it must surface as an error.
		$this->queue( 200, array( 'unexpected' => true ) );
		$result = ( new OpenAI() )->query( 'q', 'k', 'gpt-4o-mini' );
		$this->assertNotSame( '', $result['error'] );
		$this->assertSame( '', $result['text'] );
	}

	public function test_a_shaped_but_empty_answer_is_a_valid_not_mentioned() {
		// The expected container is present but the model returned empty content (e.g.
		// a safety refusal). That is a legitimate "not mentioned" — never an error.
		$this->queue( 200, array( 'choices' => array( array( 'message' => array( 'content' => '' ) ) ) ) );
		$result = ( new OpenAI() )->query( 'q', 'k', 'gpt-4o-mini' );
		$this->assertSame( '', $result['error'] );
		$this->assertSame( '', $result['text'] );
	}

	public function test_anthropic_missing_container_is_an_error() {
		$this->queue( 200, array( 'gateway' => 'html error page' ) );
		$result = ( new Anthropic() )->query( 'q', 'k', 'claude-opus-4-8' );
		$this->assertNotSame( '', $result['error'] );
	}

	public function test_anthropic_empty_content_refusal_is_not_an_error() {
		$this->queue( 200, array( 'content' => array() ) );
		$result = ( new Anthropic() )->query( 'q', 'k', 'claude-opus-4-8' );
		$this->assertSame( '', $result['error'] );
	}
}

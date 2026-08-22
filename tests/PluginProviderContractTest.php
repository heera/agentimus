<?php
/**
 * The contract every plugin provider keeps — run over the WHOLE roster, so a
 * provider added next month is held to it without anyone remembering to.
 *
 * The Woo provider was written by hand and checked by hand. Seven more like it
 * is seven chances to get the same rule slightly wrong, and the rule that
 * matters is the one nobody sees fail: an authenticated address in a public
 * document sends assistants at a locked door and tells every reader something
 * untrue about the site.
 *
 * So these tests do not care what a provider describes. They care that:
 *
 *   1. an absent plugin says NOTHING — no resource, no post types;
 *   2. every advertised address is a public, read-only GET;
 *   3. ⛔ no address is ever an authenticated management API, whatever the
 *      provider asked for;
 *   4. ids are unique and match the roster;
 *   5. the shape is complete enough for the registry to accept it.
 *
 * @package Agentimus\Tests
 */

namespace Agentimus\Tests;

use Agentimus\Integrations\Plugins\Provider;
use PHPUnit\Framework\TestCase;

final class PluginProviderContractTest extends TestCase {

	protected function setUp(): void {
		\_af_reset_options();
	}

	protected function tearDown(): void {
		\_af_reset_options();
		unset( $GLOBALS['_af_post_types_exist'], $GLOBALS['_af_post_type_objects'] );
	}

	/** Every provider in the roster, as class name => class name. */
	private function roster(): array {
		$out = array();
		foreach ( Provider::ROSTER as $class ) {
			$out[ $class ] = $class;
		}
		return $out;
	}

	public function test_the_roster_is_not_empty() {
		$this->assertNotEmpty( Provider::ROSTER, 'A roster nobody is in describes nothing.' );
	}

	/**
	 * ⭐ The safe state. On a site without the plugin, a provider must say nothing
	 * at all — not an empty resource, not a stray post type. Every provider is
	 * asked here, and none of these plugins is installed in this suite.
	 */
	public function test_an_absent_plugin_is_described_by_silence() {
		foreach ( $this->roster() as $class ) {
			$this->assertFalse( $class::present(), $class . ' must not think it is here.' );
			$this->assertSame( array(), $class::resource(), $class . ' must describe nothing when absent.' );
			$this->assertSame(
				array( 'post' ),
				$class::fold_post_types( array( 'post' ), array( 'post', 'page', 'product', 'fluent-products' ) ),
				$class . ' must not advertise content for a plugin that is not here.'
			);
		}
	}

	public function test_every_provider_has_its_own_id_and_a_card() {
		$seen = array();
		foreach ( $this->roster() as $class ) {
			$card = $class::describe();

			$this->assertNotSame( '', (string) $class::ID, $class . ' needs an id.' );
			$this->assertSame( $class::ID, $card['id'], $class . ': the card and the id must agree.' );
			$this->assertNotSame( '', trim( (string) $card['name'] ), $class . ' needs a name.' );
			$this->assertNotSame( '', trim( (string) $card['blurb'] ), $class . ' needs its one line.' );
			$this->assertArrayHasKey( 'present', $card );

			$this->assertArrayNotHasKey( $class::ID, $seen, 'Two providers share the id ' . $class::ID . '.' );
			$seen[ $class::ID ] = true;
		}
	}

	/**
	 * The plugin's own page, which its card links to.
	 *
	 * ⛔ '' IS ALLOWED and is the base class default: a provider whose address
	 * nobody has verified renders no control at all, which is the honest state.
	 * What is NOT allowed is a value that is not an address — a bare host, a
	 * typo'd scheme, a path — because that is what silently ships a dead link.
	 *
	 * ⚠️ This is a SHAPE check and cannot tell you the address is the right one.
	 * Every value on the roster today came from the plugin's own `Plugin URI:`
	 * header or was fetched and read first (2026-08-22). ⛔ Adding a provider
	 * means doing that again — this test will not do it for you.
	 */
	public function test_a_provider_home_is_an_address_or_nothing() {
		foreach ( $this->roster() as $class ) {
			$card = $class::describe();
			$this->assertArrayHasKey( 'home', $card, $class . ': the card carries the plugin\'s own page.' );

			$home = (string) $card['home'];
			if ( '' === $home ) {
				continue;
			}

			$this->assertMatchesRegularExpression(
				'#^https?://[^/\s]+#',
				$home,
				$class . ': "' . $home . '" is not an address a card can open.'
			);
		}
	}

	/**
	 * ⛔ THE RULE. Whatever a provider hands up, what reaches a discovery document
	 * is read-only and needs no login — and an authenticated management API is
	 * never named. Checked against the shape the base class produces, with a
	 * provider that deliberately misbehaves.
	 */
	public function test_the_base_refuses_an_endpoint_that_is_not_public_and_read_only() {
		$resource = TestMisbehavingProvider::resource();

		$urls = array_column( $resource['endpoints'], 'url' );
		$this->assertSame(
			array( '/wp-json/good/v1/things' ),
			$urls,
			'Only the public read-only address survives; the locked one is dropped rather than advertised.'
		);
		foreach ( $resource['endpoints'] as $endpoint ) {
			$this->assertSame( 'none', $endpoint['auth'] );
			$this->assertSame( array( 'GET' ), $endpoint['methods'] );
			$this->assertSame( 'rest', $endpoint['type'] );
		}
	}

	public function test_a_provider_with_no_public_address_registers_nothing() {
		$collector = new class() {
			public $got = array();
			public function register( $resource ) {
				$this->got[] = $resource;
			}
		};

		TestSilentProvider::provide( $collector );

		$this->assertSame( array(), $collector->got, 'Present, but nothing public to say — so it says nothing.' );
	}

	public function test_a_present_provider_folds_in_only_the_types_this_site_really_has() {
		$folded = TestSilentProvider::fold_post_types( array( 'post' ), array( 'post', 'thing' ) );
		$this->assertSame( array( 'post', 'thing' ), $folded, 'The type the site registered joins the list.' );

		$folded = TestSilentProvider::fold_post_types( array( 'post' ), array( 'post' ) );
		$this->assertSame( array( 'post' ), $folded, 'A type this site does not have is never advertised.' );
	}

	public function test_the_registry_accepts_what_a_provider_builds() {
		$normalized = \Agentimus\Discovery\Resource::normalize( TestMisbehavingProvider::resource() );

		$this->assertSame( 'test-misbehaving', $normalized['id'] );
		$this->assertSame( 'commerce', $normalized['type'] );
		$this->assertCount( 1, $normalized['endpoints'] );
	}

	/**
	 * ⚠️ THE ONE THAT BIT ME. FluentCommunity declared type "community", which is
	 * not in the vocabulary, and the registry threw the WHOLE resource away. On a
	 * live site it simply never appeared. A type is one word in one method, which
	 * is exactly the kind of thing a person gets wrong and a test should not let
	 * through.
	 *
	 * ⭐ A third party no longer loses its row for that word — an undeclared kind
	 * is marked `x-` and published. ⛔ But that is a courtesy to somebody else's
	 * plugin, not a licence for OUR providers: we ship the vocabulary, so a
	 * provider of ours needing a new kind adds the word to it. An `x-` token from
	 * our own roster would be this plugin inventing a word for a vendor.
	 */
	public function test_every_provider_declares_a_type_the_registry_will_accept() {
		foreach ( $this->roster() as $class ) {
			$m = new \ReflectionMethod( $class, 'type' );
			\_af_accessible( $m );
			$type = (string) $m->invoke( null );

			$this->assertContains(
				$type,
				\Agentimus\Discovery\Resource::TYPES,
				$class . ' declares type "' . $type . '", which is not one of the kinds we ship. Use a known kind, or add the word to Resource::TYPES.'
			);
		}
	}

	/**
	 * ⛔ A vendor's own voice wins. When a plugin — or an adapter someone wrote
	 * for it — has already advertised an address, we say nothing rather than say
	 * it twice: on his own site both the FluentCart adapter and our provider name
	 * /wp/v2/fluent-products, and a reader seeing one address under two names
	 * cannot tell which to believe.
	 */
	public function test_a_provider_stands_down_when_a_vendor_already_named_the_address() {
		$taken = new class() {
			public $got = array();
			public function register( $resource ) {
				$this->got[] = $resource;
			}
			public function resources() {
				return array(
					'someone-elses' => array(
						'endpoints' => array( array( 'url' => '/wp-json/good/v1/things' ) ),
					),
				);
			}
		};

		TestMisbehavingProvider::provide( $taken );

		$this->assertSame( array(), $taken->got, 'Theirs stands; we add no second voice.' );
	}
	/**
	 * ⭐ INSTALLED IS NOT DESCRIBED. The card read "Described" for every plugin on
	 * the roster the moment it was installed, including four whose data sits
	 * entirely behind a login. A tick that is always on is not a fact.
	 */
	public function test_an_absent_plugin_never_claims_to_be_described() {
		foreach ( $this->roster() as $class ) {
			$this->assertFalse( $class::describe()['describes'], $class . ' cannot describe anything while absent.' );
		}
	}

	public function test_present_with_nothing_public_is_not_described() {
		$card = TestSilentProvider::describe();
		$this->assertTrue( $card['present'], 'It is here.' );
		$this->assertFalse( $card['describes'], 'Here, with nothing public — so it must not claim to be described.' );

		$GLOBALS['_af_post_types_exist'] = array( 'thing' );
		$this->assertTrue(
			TestSilentProvider::describe()['describes'],
			'Once the site really has its content type, it IS describing something.'
		);
	}

	public function test_a_public_address_alone_counts_as_described() {
		$this->assertTrue( TestMisbehavingProvider::describe()['describes'] );
	}

	/* ---- standing down is not describing --------------------------------- */

	/**
	 * ⭐⭐ THE CARD MUST REPORT THE DOCUMENT, NOT THE INTENTION. A provider stands
	 * down when a plugin's own voice already names its address — that is the rule
	 * `provide()` keeps, and the whole point of it is that we then say NOTHING.
	 * The card counted the resource we would have registered, so a plugin
	 * described entirely by its vendor's own adapter still wore our tick.
	 *
	 * It went unnoticed on wpftest because the one provider it happens to affect
	 * also folds in a post type, which is a real description and keeps the tick
	 * honest by luck. A provider that stood down with no public post type — the
	 * next one written — would have claimed a description it never made.
	 */
	public function test_a_provider_that_stood_down_does_not_claim_the_description() {
		$rival = new TestRegistry(
			array(
				'someone-else' => array(
					'endpoints' => array( array( 'url' => '/wp-json/good/v1/things' ) ),
				),
			)
		);

		$this->assertFalse(
			TestMisbehavingProvider::describe( $rival )['describes'],
			'Another plugin owns that address, so provide() says nothing — and neither may the card.'
		);
	}

	/**
	 * ⚠️ The mirror image, and the reason the rule skips our own row: this same
	 * question is asked AFTER a full collection, when our address IS in the
	 * document — under our own id. Reading that as a rival would make every
	 * provider report that it had stood down, turning the whole roster off.
	 */
	public function test_finding_our_own_row_is_not_finding_a_rival() {
		$ours = new TestRegistry(
			array(
				'test-misbehaving' => array(
					'endpoints' => array( array( 'url' => '/wp-json/good/v1/things' ) ),
				),
			)
		);

		$this->assertTrue(
			TestMisbehavingProvider::describe( $ours )['describes'],
			'That row is ours. We are the description, not a duplicate of it.'
		);
	}

	/**
	 * Standing down silences the RESOURCE, not the post types: a provider whose
	 * address someone else owns can still be folding its content into what the
	 * site advertises, and that is a description worth the tick.
	 */
	public function test_standing_down_still_leaves_a_folded_post_type_described() {
		$GLOBALS['_af_post_types_exist'] = array( 'thing' );
		$rival                           = new TestRegistry(
			array(
				'someone-else' => array(
					'endpoints' => array( array( 'url' => '/wp-json/good/v1/things' ) ),
				),
			)
		);

		$this->assertTrue(
			TestFoldingProvider::describe( $rival )['describes'],
			'The address is theirs; the content type is still ours to advertise.'
		);
	}

	/** With no view of the document, our own answer is the only honest one. */
	public function test_without_a_registry_the_card_reports_what_we_would_say() {
		$this->assertTrue( TestMisbehavingProvider::describe( null )['describes'] );
	}

	/**
	 * ⚠️ THE ROUTE IS READ, NEVER GUESSED. Easy Digital Downloads registers the
	 * type `download` and serves it at `edd-downloads`; a provider that built the
	 * address out of the type's name would have advertised a 404 on every EDD
	 * site and looked entirely right doing it.
	 *
	 * ⭐ EDD is no longer in the roster (removed 2026-08-17), and this rule is
	 * proved against a LOCAL provider so it cannot leave with a vendor. The
	 * anecdote above stays because it is the reason the rule exists, not a fact
	 * about what we ship.
	 */
	public function test_a_type_route_comes_from_the_type_and_not_from_its_name() {
		$GLOBALS['_af_post_types_exist']  = array( 'download' );
		$GLOBALS['_af_post_type_objects'] = array(
			'download' => array( 'public' => true, 'show_in_rest' => true, 'rest_base' => 'edd-downloads' ),
		);

		$urls = array_column( $this->endpoints_of( TestTypeRouteProvider::class ), 'url' );
		$this->assertSame( array( '/wp-json/wp/v2/edd-downloads' ), $urls );
	}

	/**
	 * ⛔ The vendor decides. A type its own plugin registered as private, or kept
	 * out of the REST API, is never named here however public its name sounds.
	 */
	public function test_a_type_its_plugin_keeps_private_is_never_named() {
		$GLOBALS['_af_post_types_exist']  = array( 'download' );
		$GLOBALS['_af_post_type_objects'] = array(
			'download' => array( 'public' => false, 'show_in_rest' => true, 'rest_base' => 'edd-downloads' ),
		);
		$this->assertSame( array(), $this->endpoints_of( TestTypeRouteProvider::class ), 'Private stays private.' );

		$GLOBALS['_af_post_type_objects'] = array(
			'download' => array( 'public' => true, 'show_in_rest' => false, 'rest_base' => 'edd-downloads' ),
		);
		$this->assertSame( array(), $this->endpoints_of( TestTypeRouteProvider::class ), 'No REST route, no address to give.' );
	}

	/**
	 * ⭐ HOW WE KNOW A PLUGIN IS HERE, proved once instead of nine times. Every
	 * provider now declares the names it recognises and the base does the asking,
	 * so this covers the whole roster's presence rule.
	 */
	public function test_presence_is_decided_by_the_declared_names() {
		$this->assertFalse( TestNamedProvider::present(), 'Neither name is here yet.' );

		define( 'AGENTIMUS_TEST_VENDOR_VERSION', '1.0.0' );
		$this->assertTrue( TestNamedProvider::present(), 'A declared constant is enough on its own.' );

		$this->assertTrue( TestClassNamedProvider::present(), 'So is a declared class.' );
		$this->assertFalse( TestUnnamedProvider::present(), 'A provider that names nothing is never here.' );
	}

	/**
	 * ⚠️ THE ONE THAT WAS ALREADY WRONG IN SHIPPED CODE. FluentSupport's fallback
	 * named `FLUENTSUPPORT_PLUGIN_PATH`, a constant no release of that plugin has
	 * ever defined — invisible, because the class probe beside it worked. A test
	 * cannot know a vendor's real names, so it checks the two things it can: that
	 * a provider recognises the plugin by SOMETHING, and that every name is a name
	 * (the typo class is caught by reading the vendor's bootstrap, and each
	 * provider's docblock cites the file and line it was read from).
	 */
	public function test_every_provider_knows_the_plugin_by_a_name_of_its_own() {
		foreach ( $this->roster() as $class ) {
			$names = array_merge( (array) $class::CLASSES, (array) $class::CONSTANTS );

			$this->assertNotEmpty( $names, $class . ' must recognise its plugin by at least one class or constant.' );
			foreach ( $names as $name ) {
				$this->assertIsString( $name, $class . ' declared a name that is not a string.' );
				$this->assertNotSame( '', trim( $name ), $class . ' declared an empty name.' );
				$this->assertDoesNotMatchRegularExpression( '/\s/', $name, $class . ' declared "' . $name . '", which cannot be a class or a constant.' );
			}
			foreach ( (array) $class::CONSTANTS as $constant ) {
				$this->assertSame(
					strtoupper( $constant ),
					$constant,
					$class . ' declares constant "' . $constant . '" — constants are upper case, so this one is probably not the vendor\'s.'
				);
			}
		}
	}

	/**
	 * ⭐⭐ A PROVIDER NAMES AN ADDRESS; IT DOES NOT DECIDE THE SITE PUBLISHES IT.
	 *
	 * ⚠️ FluentCommunity's spaces route is the case that taught this. It answers a
	 * stranger only while that community's access level is "public", so we were
	 * advertising members-only communities as open doors. It was fixed here first,
	 * by reading FluentCommunity's own settings helper — and that fix was REMOVED
	 * on purpose: it protected one vendor we had read the source of, and said
	 * nothing about the plugins Agentimus finds by itself.
	 *
	 * The rule now lives in {@see \Agentimus\Discovery\Reachability}, which knows
	 * no plugin from any other, and the publication gate is
	 * {@see \Agentimus\Discovery\Envelope::published_resources()}. This test only
	 * holds the line that a provider is allowed to name its address plainly.
	 */
	public function test_a_provider_names_its_address_and_lets_the_site_prove_it() {
		$this->assertSame(
			array( \Agentimus\Integrations\Plugins\FluentCommunity::SPACES_URL ),
			array_column( $this->endpoints_of( \Agentimus\Integrations\Plugins\FluentCommunity::class ), 'url' ),
			'No vendor-specific gate here any more — the general check decides.'
		);
	}

	/** A provider's endpoints as it hands them up, before the base makes them safe. */
	private function endpoints_of( $class ) {
		$m = new \ReflectionMethod( $class, 'endpoints' );
		\_af_accessible( $m );
		return (array) $m->invoke( null );
	}
}

/**
 * Reads its address from a post type's own registration rather than building it
 * from the type's name. Local to this file so the rule survives any vendor
 * leaving the roster — see test_a_type_route_comes_from_the_type_and_not_from_its_name.
 */
final class TestTypeRouteProvider extends Provider {
	const ID = 'test-type-route';
	protected static function name() {
		return 'Type Route';
	}
	protected static function blurb() {
		return 'Its address comes from the type, not the type\'s name.';
	}
	protected static function endpoints() {
		return self::type_endpoint( 'download', 'Browse the downloads.' );
	}
}

/** Recognises its plugin by a constant nothing has defined yet. */
final class TestNamedProvider extends Provider {
	const ID        = 'test-named';
	const CONSTANTS = array( 'AGENTIMUS_TEST_VENDOR_VERSION' );
	protected static function name() {
		return 'Named';
	}
	protected static function blurb() {
		return 'Known by the constant its plugin defines.';
	}
}

/** Recognises its plugin by a class — this test file's own, so it is really there. */
final class TestClassNamedProvider extends Provider {
	const ID      = 'test-class-named';
	const CLASSES = array( TestRegistry::class );
	protected static function name() {
		return 'Class Named';
	}
	protected static function blurb() {
		return 'Known by the class its plugin ships.';
	}
}

/** Declares nothing, so it is never anywhere. */
final class TestUnnamedProvider extends Provider {
	const ID = 'test-unnamed';
	protected static function name() {
		return 'Unnamed';
	}
	protected static function blurb() {
		return 'Recognises nothing.';
	}
}

/** A provider that asks for a locked door alongside a public one. */
final class TestMisbehavingProvider extends Provider {
	const ID = 'test-misbehaving';
	public static function present() {
		return true;
	}
	protected static function name() {
		return 'Misbehaving';
	}
	protected static function blurb() {
		return 'Asks for more than it should.';
	}
	protected static function type() {
		return 'commerce';
	}
	protected static function endpoints() {
		return array(
			array( 'url' => '/wp-json/good/v1/things', 'description' => 'Public.' ),
			array( 'url' => '/wp-json/locked/v3/things', 'auth' => 'basic', 'description' => 'Needs a key.' ),
		);
	}
}

/** Present, with BOTH a public address and a content type of its own. */
final class TestFoldingProvider extends Provider {
	const ID = 'test-folding';
	public static function present() {
		return true;
	}
	protected static function name() {
		return 'Folding';
	}
	protected static function blurb() {
		return 'An address someone else may own, and content of its own.';
	}
	protected static function endpoints() {
		return array( array( 'url' => '/wp-json/good/v1/things', 'description' => 'Public.' ) );
	}
	protected static function post_types() {
		return array( 'thing' );
	}
}

/** The collector, as much of it as the stand-down rule reads. */
final class TestRegistry {

	/** @var array<string,array> */
	private $resources;

	/**
	 * @param array<string,array> $resources Resources keyed by id, as the real one keys them.
	 */
	public function __construct( array $resources ) {
		$this->resources = $resources;
	}

	/** @return array<string,array> */
	public function resources() {
		return $this->resources;
	}
}

/** Present, with content but no public address — the honest common case. */
final class TestSilentProvider extends Provider {
	const ID = 'test-silent';
	public static function present() {
		return true;
	}
	protected static function name() {
		return 'Silent';
	}
	protected static function blurb() {
		return 'Here, with nothing public to offer.';
	}
	protected static function post_types() {
		return array( 'thing' );
	}
}

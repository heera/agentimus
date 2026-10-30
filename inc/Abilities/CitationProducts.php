<?php
/**
 * The products a citation check asks about, for agents: read them, add one, add to one.
 *
 * ⛔ ADD ONLY. The owner's screen confirms before a product is removed, and a tool
 * that could replace a product's lists could empty it, which is the same removal by
 * another name. So nothing here can take away a product, a question or a rival, and
 * `active`, the schedule and the provider keys are out of reach.
 *
 * @package Agentimus
 */

namespace Agentimus\Abilities;

use Agentimus\AgentAccess\Events;
use Agentimus\AgentAccess\Module;
use Agentimus\AgentAccess\Store;
use Agentimus\Settings;
use Agentimus\Visibility\Settings as VisibilitySettings;

defined( 'ABSPATH' ) || exit;

final class CitationProducts {

	/** Calls that change something, per connection, per hour. */
	const HOURLY_LIMIT = 20;

	/** @var Settings */
	private $settings;

	/** @var VisibilitySettings */
	private $visibility;

	/**
	 * @param Settings                $settings   Core settings.
	 * @param VisibilitySettings|null $visibility Citation settings.
	 */
	public function __construct( Settings $settings, ?VisibilitySettings $visibility = null ) {
		$this->settings   = $settings;
		$this->visibility = $visibility ? $visibility : new VisibilitySettings();
	}

	/**
	 * Every product, without the provider keys that live beside them.
	 *
	 * @return array{products: array, questions: int, maxProducts: int, maxQuestions: int}
	 */
	public function read() {
		$products = (array) $this->visibility->public_view()['targets'];
		$count    = 0;
		foreach ( $products as $product ) {
			$count += count( (array) $product['prompts'] );
		}
		return array(
			'products'     => $products,
			'questions'    => $count,
			'maxProducts'  => VisibilitySettings::MAX_TARGETS,
			'maxQuestions' => VisibilitySettings::MAX_PROMPTS,
		);
	}

	/**
	 * Add a product, or add to one found by name.
	 *
	 * @param array $input { name, category?, domain?, add_competitors?, add_prompts? }.
	 * @return array|\WP_Error { created, name, addedQuestions, addedCompetitors, questionsNow, changed, message }.
	 */
	public function save( array $input ) {
		if ( ! $this->settings->enabled( 'agent_writes_settings' ) ) {
			return new \WP_Error(
				'agentimus_settings_writes_off',
				__( 'Agents may not change settings on this site. The owner can allow it under MCP Server, with “Let agents change settings”. Nothing was changed.', 'agentimus' ),
				array( 'status' => 403 )
			);
		}

		$name = trim( sanitize_text_field( (string) ( $input['name'] ?? '' ) ) );
		if ( '' === $name ) {
			return new \WP_Error( 'agentimus_product_name', __( '`name` is required: the product to add, or the one to add to.', 'agentimus' ), array( 'status' => 400 ) );
		}

		$targets = (array) $this->visibility->get( 'targets', array() );
		$at      = null;
		foreach ( $targets as $i => $target ) {
			if ( 0 === strcasecmp( (string) $target['name'], $name ) ) {
				$at = $i;
				break;
			}
		}

		$created = null === $at;
		if ( $created && count( $targets ) >= VisibilitySettings::MAX_TARGETS ) {
			return self::full( VisibilitySettings::MAX_TARGETS, __( 'products', 'agentimus' ) );
		}

		$before  = $created ? array( 'name' => $name, 'category' => '', 'domain' => '', 'competitors' => array(), 'prompts' => array() ) : $targets[ $at ];
		$product = $before;

		foreach ( array( 'category', 'domain' ) as $field ) {
			if ( isset( $input[ $field ] ) && '' !== trim( (string) $input[ $field ] ) ) {
				$product[ $field ] = (string) $input[ $field ];
			}
		}
		$product['competitors'] = self::merged( (array) $before['competitors'], (array) ( $input['add_competitors'] ?? array() ) );
		$product['prompts']     = self::merged( (array) $before['prompts'], (array) ( $input['add_prompts'] ?? array() ) );

		if ( count( $product['prompts'] ) > VisibilitySettings::MAX_PROMPTS ) {
			return self::full( VisibilitySettings::MAX_PROMPTS, __( 'questions for a product', 'agentimus' ) );
		}
		if ( count( $product['competitors'] ) > VisibilitySettings::MAX_COMPETITORS ) {
			return self::full( VisibilitySettings::MAX_COMPETITORS, __( 'rivals for a product', 'agentimus' ) );
		}

		if ( ! $created && $product === $before ) {
			return $this->answer( false, false, (string) $before['name'], 0, 0, __( 'This product already has all of that. Nothing was changed.', 'agentimus' ) );
		}

		$limited = self::over_limit();
		if ( null !== $limited ) {
			return $limited;
		}

		if ( $created ) {
			$targets[] = $product;
		} else {
			$targets[ $at ] = $product;
		}
		$this->visibility->update( array( 'targets' => array_values( $targets ) ) );

		$questions = count( $product['prompts'] ) - count( (array) $before['prompts'] );
		$rivals    = count( $product['competitors'] ) - count( (array) $before['competitors'] );

		if ( Module::enabled( $this->settings ) ) {
			Store::record(
				Events::KIND_SETTING_CHANGED,
				get_current_user_id(),
				Module::current_credential(),
				sprintf( 'citation product %s: +%d questions, +%d rivals', mb_substr( (string) $product['name'], 0, 40 ), $questions, $rivals )
			);
		}

		return $this->answer(
			$created,
			true,
			(string) $product['name'],
			$questions,
			$rivals,
			__( 'Saved, and no check was run. Each new question makes every later citation run use more of the owner’s AI credit. Tell the owner what was added.', 'agentimus' )
		);
	}

	/**
	 * @param bool   $created   Whether this made a new product.
	 * @param bool   $changed   Whether anything was written.
	 * @param string $name      Product.
	 * @param int    $questions Questions added.
	 * @param int    $rivals    Rivals added.
	 * @param string $message   Plain sentence.
	 * @return array
	 */
	private function answer( $created, $changed, $name, $questions, $rivals, $message ) {
		return array(
			'created'          => $created,
			'changed'          => $changed,
			'name'             => $name,
			'addedQuestions'   => $questions,
			'addedCompetitors' => $rivals,
			'questionsNow'     => $this->read()['questions'],
			'message'          => $message,
		);
	}

	/**
	 * A list with new entries on the end, without repeats in any case.
	 *
	 * @param array $current Stored list.
	 * @param array $added   Entries to add.
	 * @return array
	 */
	private static function merged( array $current, array $added ) {
		$seen = array_map( 'mb_strtolower', array_map( 'strval', $current ) );
		foreach ( $added as $item ) {
			$item = trim( sanitize_text_field( (string) $item ) );
			if ( '' !== $item && ! in_array( mb_strtolower( $item ), $seen, true ) ) {
				$current[] = $item;
				$seen[]    = mb_strtolower( $item );
			}
		}
		return array_values( $current );
	}

	/**
	 * @param int    $max  The cap.
	 * @param string $what What it caps.
	 * @return \WP_Error
	 */
	private static function full( $max, $what ) {
		return new \WP_Error(
			'agentimus_products_full',
			sprintf(
				/* translators: 1: the limit, 2: what is limited, e.g. "questions for a product". */
				__( 'This site allows at most %1$d %2$s, and this would go past that. Nothing was changed. Removing any is up to the owner, on the Citations screen.', 'agentimus' ),
				$max,
				$what
			),
			array( 'status' => 409 )
		);
	}

	/**
	 * Count a changing call against this connection's hourly limit.
	 *
	 * @return \WP_Error|null
	 */
	private static function over_limit() {
		$key   = 'agentimus_citation_writes_' . md5( get_current_user_id() . '|' . Module::current_credential() );
		$count = (int) get_transient( $key );
		if ( $count >= self::HOURLY_LIMIT ) {
			return new \WP_Error(
				'agentimus_citation_rate_limited',
				sprintf(
					/* translators: %d: changes allowed per hour. */
					__( 'This connection has changed citation products %d times in the last hour, which is the limit. Nothing was changed. Try again later.', 'agentimus' ),
					self::HOURLY_LIMIT
				),
				array( 'status' => 429 )
			);
		}
		set_transient( $key, $count + 1, HOUR_IN_SECONDS );
		return null;
	}
}

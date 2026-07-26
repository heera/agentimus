<?php
/**
 * Fill the gaps — the drafting loop. Takes the next few items missing a field
 * and asks the site's AI for each one, through the SAME per-field draft methods
 * the editor's buttons use ({@see \Agentimus\Assist}), so a bulk draft can
 * never say something a single draft wouldn't. Results are parked as proposals
 * ({@see Proposals}) — this class writes no live content, ever.
 *
 * Failure posture: one item failing is an entry in the errors list, not the end
 * of the run — EXCEPT "your provider can't read images", which would fail every
 * remaining alt item identically, so the run stops asking after the first.
 *
 * @package Agentimus
 */

namespace Agentimus\Bulk;

use Agentimus\Assist;

defined( 'ABSPATH' ) || exit;

final class Runner {

	/**
	 * Paid calls per user per minute while a bulk run is in flight. The Assist
	 * cap (20/min) is sized for a human clicking buttons; a bulk run is the
	 * owner explicitly asking for a batch, so it gets more headroom — through
	 * the same choke point, never around it. Filterable.
	 */
	const BULK_RATE_MAX = 60;

	/** @var Assist */
	private $assist;

	/** @var Scanner */
	private $scanner;

	/**
	 * @param Assist  $assist  The drafting seam.
	 * @param Scanner $scanner The census.
	 */
	public function __construct( Assist $assist, Scanner $scanner ) {
		$this->assist  = $assist;
		$this->scanner = $scanner;
	}

	/**
	 * Draft proposals for the next batch of items missing a field.
	 *
	 * @param string        $field   Field id (validated by the caller).
	 * @param int           $limit   Items this request (clamped to the batch size).
	 * @param int[]         $exclude Items that already failed this run — never re-picked.
	 * @param callable|null $drafter Test seam: fn( int $id ) → value|WP_Error. Defaults to the Assist draft.
	 * @return array{generated:array,errors:array,remaining:int,vision:bool}
	 */
	public function run( $field, $limit, array $exclude = array(), $drafter = null ) {
		$limit   = max( 1, min( (int) $limit, Scanner::BATCH_SIZE ) );
		$drafter = $drafter ? $drafter : $this->drafter( $field );
		$ids     = $this->scanner->missing_ids( $field, $limit, $exclude );

		// The owner asked for a batch — widen the per-minute spend ceiling for the
		// duration of this request only, still through the one rate-limited seam.
		$bump = static function () {
			/**
			 * Filter the per-user AI call budget (calls per minute) while a bulk run
			 * is drafting. 0 disables the cap.
			 *
			 * @param int $max Calls allowed per window.
			 */
			return (int) apply_filters( 'agentimus_bulk_rate_max', self::BULK_RATE_MAX );
		};
		add_filter( 'agentimus_assist_rate_max', $bump );

		$generated = array();
		$errors    = array();
		$vision    = true;

		foreach ( $ids as $id ) {
			$value = call_user_func( $drafter, $id );

			if ( is_wp_error( $value ) ) {
				$errors[] = array(
					'id'      => (int) $id,
					'title'   => (string) get_the_title( $id ),
					'message' => $value->get_error_message(),
				);
				// No configured model can read images → every later alt item fails the
				// same way. Say it once and stop spending the owner's time on it.
				if ( 'agentimus_ai_no_vision' === $value->get_error_code() ) {
					$vision = false;
					break;
				}
				continue;
			}

			Proposals::save( $id, $field, $value );
			$row = Proposals::row( $id, $field );
			if ( null !== $row ) {
				$generated[] = $row;
			}
		}

		remove_filter( 'agentimus_assist_rate_max', $bump );

		return array(
			'generated' => $generated,
			'errors'    => $errors,
			'remaining' => $this->scanner->missing_count( $field ),
			'vision'    => $vision,
		);
	}

	/**
	 * The real drafter for a field — one Assist call per item.
	 *
	 * @param string $field Field id.
	 * @return callable fn( int $id ) → value|WP_Error
	 */
	private function drafter( $field ) {
		$assist = $this->assist;

		if ( 'alt' === $field ) {
			return static function ( $id ) use ( $assist ) {
				return $assist->draft_alt( $id );
			};
		}

		return static function ( $id ) use ( $assist, $field ) {
			$post = get_post( $id );
			if ( ! $post ) {
				return new \WP_Error( 'agentimus_not_found', __( 'That content is not available.', 'agentimus' ) );
			}
			return 'topics' === $field ? $assist->draft_topics( $post ) : $assist->draft_description( $post );
		};
	}
}

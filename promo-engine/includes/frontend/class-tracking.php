<?php
/**
 * AJAX endpoints for popup analytics events.
 *
 * @package Promo_Engine
 */

namespace Promo_Engine\Frontend;

use Promo_Engine\Analytics\Events;
use Promo_Engine\Repository;

defined( 'ABSPATH' ) || exit;

/**
 * Receives popup view/click beacons from the frontend.
 */
class Tracking {

	/**
	 * Promotions repository.
	 *
	 * @var Repository
	 */
	private Repository $repository;

	/**
	 * Constructor.
	 *
	 * @param Repository $repository Promotions repository.
	 */
	public function __construct( Repository $repository ) {
		$this->repository = $repository;
	}

	/**
	 * Hook AJAX actions (logged-in and guests).
	 */
	public function register(): void {
		add_action( 'wp_ajax_pe_track', array( $this, 'handle' ) );
		add_action( 'wp_ajax_nopriv_pe_track', array( $this, 'handle' ) );
	}

	/**
	 * Handle a tracking beacon.
	 */
	public function handle(): void {
		check_ajax_referer( 'pe-track', 'nonce' );

		$event    = isset( $_POST['event'] ) ? sanitize_key( wp_unslash( $_POST['event'] ) ) : '';
		$promo_id = isset( $_POST['promo_id'] ) ? absint( wp_unslash( $_POST['promo_id'] ) ) : 0;

		$map = array(
			'view'  => Events::TYPE_POPUP_VIEW,
			'click' => Events::TYPE_POPUP_CLICK,
		);
		if ( ! isset( $map[ $event ] ) || ! $promo_id ) {
			wp_send_json_error( null, 400 );
		}

		// Only accept events for promotions that actually run a popup.
		$valid = false;
		foreach ( $this->repository->get_running() as $promo ) {
			if ( $promo->id === $promo_id && $promo->popup_enabled ) {
				$valid = true;
				break;
			}
		}
		if ( ! $valid ) {
			wp_send_json_error( null, 400 );
		}

		Events::log( $map[ $event ], $promo_id );
		wp_send_json_success();
	}
}

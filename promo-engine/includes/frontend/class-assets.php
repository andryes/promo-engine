<?php
/**
 * Frontend assets.
 *
 * @package Promo_Engine
 */

namespace Promo_Engine\Frontend;

use Promo_Engine\Install;
use Promo_Engine\Repository;

defined( 'ABSPATH' ) || exit;

/**
 * Enqueues the frontend stylesheet/script and localizes runtime data.
 */
class Assets {

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
	 * Hook everything.
	 */
	public function register(): void {
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	/**
	 * Enqueue assets.
	 */
	public function enqueue(): void {
		wp_enqueue_style( 'promo-engine', PROMO_ENGINE_URL . 'assets/css/frontend.css', array(), PROMO_ENGINE_VERSION );
		wp_enqueue_script( 'promo-engine', PROMO_ENGINE_URL . 'assets/js/frontend.js', array(), PROMO_ENGINE_VERSION, true );

		$popup_promo = null;
		if ( ! is_checkout() ) {
			foreach ( $this->repository->get_running() as $promo ) {
				if ( $promo->popup_enabled && ( null === $popup_promo || $promo->priority > $popup_promo->priority ) ) {
					$popup_promo = $promo;
				}
			}
		}

		$deals_page = Install::deals_page_id();

		wp_localize_script(
			'promo-engine',
			'promoEngine',
			array(
				'ajaxUrl'  => admin_url( 'admin-ajax.php' ),
				'nonce'    => wp_create_nonce( 'pe-track' ),
				'dealsUrl' => $deals_page ? get_permalink( $deals_page ) : '',
				'popup'    => $popup_promo
					? array(
						'id'   => $popup_promo->id,
						'ends' => $popup_promo->ends_at ? $popup_promo->ends_at * 1000 : 0,
					)
					: null,
				'i18n'     => array(
					'days'  => __( 'd', 'promo-engine' ),
					'ended' => __( 'The deal has ended', 'promo-engine' ),
				),
			)
		);
	}
}

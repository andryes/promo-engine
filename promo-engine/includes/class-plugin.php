<?php
/**
 * Main plugin class.
 *
 * @package Promo_Engine
 */

namespace Promo_Engine;

defined( 'ABSPATH' ) || exit;

/**
 * Wires all plugin components together.
 */
final class Plugin {

	/**
	 * Singleton instance.
	 *
	 * @var Plugin|null
	 */
	private static ?Plugin $instance = null;

	/**
	 * Active promotions repository.
	 *
	 * @var Repository
	 */
	public Repository $repository;

	/**
	 * Get the singleton.
	 *
	 * @return Plugin
	 */
	public static function instance(): Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Boot all components. Runs on plugins_loaded once WooCommerce is present.
	 */
	public function init(): void {
		load_plugin_textdomain( 'promo-engine', false, dirname( plugin_basename( PROMO_ENGINE_FILE ) ) . '/languages' );

		$this->repository = new Repository();
		$this->repository->register();

		Install::maybe_upgrade();

		( new Admin\Promotion_CPT() )->register();
		( new Admin\Demo_Seeder( $this->repository ) )->register();
		( new Analytics\Events( $this->repository ) )->register();

		( new Cart\Cart_Hooks( $this->repository ) )->register();
		( new Cart\Order_Hooks() )->register();

		( new Frontend\Assets( $this->repository ) )->register();
		( new Frontend\Deals_Page( $this->repository ) )->register();
		( new Frontend\Popup( $this->repository ) )->register();
		( new Frontend\Mini_Cart() )->register();
		( new Frontend\Checkout_Summary() )->register();
		( new Frontend\Tracking( $this->repository ) )->register();

		if ( is_admin() ) {
			( new Admin\Meta_Boxes() )->register();
			( new Admin\Analytics_Page() )->register();
		}
	}
}

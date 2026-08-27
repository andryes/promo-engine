<?php
/**
 * Analytics events storage.
 *
 * @package Promo_Engine
 */

namespace Promo_Engine\Analytics;

use Promo_Engine\Engine\Cart_Line;
use Promo_Engine\Engine\Promotion;
use Promo_Engine\Repository;

defined( 'ABSPATH' ) || exit;

/**
 * Custom events table + loggers.
 *
 * Event types: popup_view, popup_click, add_to_cart, order.
 * created_at is stored in the SITE timezone so date filters and daily
 * grouping in the admin match what the admin sees.
 */
class Events {

	public const TYPE_POPUP_VIEW  = 'popup_view';
	public const TYPE_POPUP_CLICK = 'popup_click';
	public const TYPE_ADD_TO_CART = 'add_to_cart';
	public const TYPE_ORDER       = 'order';

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
	 * Hook the add-to-cart logger.
	 */
	public function register(): void {
		add_action( 'woocommerce_add_to_cart', array( $this, 'log_add_to_cart' ), 10, 4 );
	}

	/**
	 * Table name.
	 *
	 * @return string
	 */
	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'pe_events';
	}

	/**
	 * Create / update the events table.
	 */
	public static function install_table(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table   = self::table();
		$charset = $wpdb->get_charset_collate();

		dbDelta(
			"CREATE TABLE {$table} (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				event_type VARCHAR(20) NOT NULL,
				promo_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
				product_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
				order_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
				revenue DECIMAL(12,2) NOT NULL DEFAULT 0,
				discount DECIMAL(12,2) NOT NULL DEFAULT 0,
				created_at DATETIME NOT NULL,
				PRIMARY KEY  (id),
				KEY promo_type_date (promo_id, event_type, created_at),
				KEY type_date (event_type, created_at),
				KEY order_id (order_id)
			) {$charset};"
		);
	}

	/**
	 * Insert one event.
	 *
	 * @param string               $type Event type.
	 * @param int                  $promo_id Promotion ID.
	 * @param array<string, mixed> $args Optional: product_id, order_id, revenue, discount.
	 */
	public static function log( string $type, int $promo_id, array $args = array() ): void {
		global $wpdb;

		if ( ! in_array( $type, array( self::TYPE_POPUP_VIEW, self::TYPE_POPUP_CLICK, self::TYPE_ADD_TO_CART, self::TYPE_ORDER ), true ) ) {
			return;
		}

		/**
		 * Allows muting analytics writes (e.g. for smoke tests / staging checks).
		 *
		 * @param bool $disabled Default false.
		 */
		if ( apply_filters( 'promo_engine_disable_tracking', false ) ) {
			return;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom analytics table, write path.
		$wpdb->insert(
			self::table(),
			array(
				'event_type' => $type,
				'promo_id'   => $promo_id,
				'product_id' => (int) ( $args['product_id'] ?? 0 ),
				'order_id'   => (int) ( $args['order_id'] ?? 0 ),
				'revenue'    => round( (float) ( $args['revenue'] ?? 0 ), 2 ),
				'discount'   => round( (float) ( $args['discount'] ?? 0 ), 2 ),
				'created_at' => current_time( 'mysql' ),
			),
			array( '%s', '%d', '%d', '%d', '%f', '%f', '%s' )
		);
	}

	/**
	 * Log an add_to_cart event for every running promotion covering the product.
	 *
	 * @param string $cart_item_key Cart item key.
	 * @param int    $product_id    Product ID.
	 * @param int    $quantity      Quantity.
	 * @param int    $variation_id  Variation ID.
	 */
	public function log_add_to_cart( string $cart_item_key, int $product_id, int $quantity, int $variation_id = 0 ): void {
		$line = Cart_Line::from_array(
			array(
				'product_id'   => $variation_id ? $variation_id : $product_id,
				'parent_id'    => $variation_id ? $product_id : 0,
				'category_ids' => array_map( 'intval', wc_get_product_term_ids( $product_id, 'product_cat' ) ),
				'tag_ids'      => array_map( 'intval', wc_get_product_term_ids( $product_id, 'product_tag' ) ),
			)
		);

		foreach ( $this->repository->get_running() as $promo ) {
			if ( Promotion::TYPE_CART === $promo->type || ! $promo->applies_to( $line ) ) {
				continue;
			}
			self::log( self::TYPE_ADD_TO_CART, $promo->id, array( 'product_id' => $product_id ) );
		}
	}
}

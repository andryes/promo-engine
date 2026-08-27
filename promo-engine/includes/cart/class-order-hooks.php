<?php
/**
 * Order integration: persist promo data to orders, log order events.
 *
 * @package Promo_Engine
 */

namespace Promo_Engine\Cart;

use Promo_Engine\Analytics\Events;

defined( 'ABSPATH' ) || exit;

/**
 * Copies the promo summary onto orders and records analytics events.
 */
class Order_Hooks {

	/**
	 * Hook everything (classic checkout + Store API checkout).
	 */
	public function register(): void {
		add_action( 'woocommerce_checkout_create_order', array( $this, 'add_order_meta' ), 10, 1 );
		add_action( 'woocommerce_store_api_checkout_update_order_meta', array( $this, 'add_order_meta' ), 10, 1 );
		add_action( 'woocommerce_checkout_create_order_line_item', array( $this, 'add_item_meta' ), 10, 3 );
		add_action( 'woocommerce_checkout_order_processed', array( $this, 'log_order' ), 10, 1 );
		add_action( 'woocommerce_store_api_checkout_order_processed', array( $this, 'log_order_object' ), 10, 1 );
	}

	/**
	 * Store the promo summary on the order.
	 *
	 * @param \WC_Order $order Order.
	 */
	public function add_order_meta( \WC_Order $order ): void {
		$summary = Cart_Hooks::session_summary();
		if ( ! $summary || empty( $summary['promo_totals'] ) ) {
			return;
		}
		$order->update_meta_data( '_pe_promotions', $summary['promo_totals'] );
		$order->update_meta_data( '_pe_total_saved', round( (float) $summary['total_saved'], 2 ) );
	}

	/**
	 * Store per-line promo discounts on order line items.
	 *
	 * @param \WC_Order_Item_Product $item          Order line item.
	 * @param string                 $cart_item_key Cart item key.
	 * @param array<string, mixed>   $values        Cart item values.
	 */
	public function add_item_meta( \WC_Order_Item_Product $item, string $cart_item_key, array $values ): void {
		$summary = Cart_Hooks::session_summary();
		$line    = $summary['line_discounts'][ $cart_item_key ] ?? null;
		if ( $line ) {
			$item->update_meta_data( '_pe_discounts', $line );
		}
		$promos = $summary['line_promos'][ $cart_item_key ] ?? null;
		if ( $promos ) {
			$item->update_meta_data( '_pe_promos', $promos );
		}
	}

	/**
	 * Log order events (classic checkout entry point).
	 *
	 * @param int $order_id Order ID.
	 */
	public function log_order( int $order_id ): void {
		$order = wc_get_order( $order_id );
		if ( $order ) {
			$this->log_order_object( $order );
		}
	}

	/**
	 * Log one "order" event per involved promotion.
	 *
	 * Revenue attributed to a promotion = final total of the order lines
	 * the promotion touched; discount = the promotion's total discount.
	 *
	 * @param \WC_Order $order Order.
	 */
	public function log_order_object( \WC_Order $order ): void {
		$promo_totals = $order->get_meta( '_pe_promotions' );
		if ( ! is_array( $promo_totals ) || ! $promo_totals ) {
			return;
		}

		// Guard against double logging (both hooks may fire).
		if ( $order->get_meta( '_pe_events_logged' ) ) {
			return;
		}
		$order->update_meta_data( '_pe_events_logged', '1' );
		$order->save();

		$revenue_by_promo = array();
		foreach ( $order->get_items() as $item ) {
			if ( ! $item instanceof \WC_Order_Item_Product ) {
				continue;
			}
			$promos = $item->get_meta( '_pe_promos' );
			if ( ! is_array( $promos ) || ! $promos ) {
				$discounts = $item->get_meta( '_pe_discounts' );
				$promos    = is_array( $discounts ) ? array_keys( $discounts ) : array();
			}
			foreach ( $promos as $promo_id ) {
				$revenue_by_promo[ (int) $promo_id ] = ( $revenue_by_promo[ (int) $promo_id ] ?? 0.0 ) + (float) $item->get_total();
			}
		}

		foreach ( $promo_totals as $promo_id => $info ) {
			Events::log(
				Events::TYPE_ORDER,
				(int) $promo_id,
				array(
					'order_id' => $order->get_id(),
					'revenue'  => $revenue_by_promo[ (int) $promo_id ] ?? 0.0,
					'discount' => (float) ( $info['amount'] ?? 0 ),
				)
			);
		}
	}
}

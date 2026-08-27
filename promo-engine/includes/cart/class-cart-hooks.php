<?php
/**
 * Cart integration: runs the engine and applies prices.
 *
 * @package Promo_Engine
 */

namespace Promo_Engine\Cart;

use Promo_Engine\Engine\Cart_Line;
use Promo_Engine\Engine\Discount_Engine;
use Promo_Engine\Engine\Result;
use Promo_Engine\Repository;

defined( 'ABSPATH' ) || exit;

/**
 * Applies engine prices during cart totals calculation and renders
 * per-item discount info in cart/mini-cart line items.
 */
class Cart_Hooks {

	public const SESSION_KEY = 'pe_summary';

	/**
	 * Promotions repository.
	 *
	 * @var Repository
	 */
	private Repository $repository;

	/**
	 * Original (pre-promotion, sale-aware) unit prices captured on the
	 * first calculation pass of the request: cart item key => price.
	 * Guards against double application when totals are recalculated
	 * several times within one request.
	 *
	 * @var array<string, float>
	 */
	private static array $base_prices = array();

	/**
	 * Last engine result of this request.
	 *
	 * @var Result|null
	 */
	private static ?Result $last_result = null;

	/**
	 * Re-entrancy guard.
	 *
	 * @var bool
	 */
	private static bool $calculating = false;

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
		add_action( 'woocommerce_before_calculate_totals', array( $this, 'apply_discounts' ), 999 );
		add_action( 'woocommerce_before_mini_cart', array( $this, 'ensure_calculated' ) );
		add_filter( 'woocommerce_cart_item_price', array( $this, 'item_price_html' ), 20, 3 );
		add_filter( 'woocommerce_cart_item_subtotal', array( $this, 'item_subtotal_html' ), 20, 3 );
		add_filter( 'woocommerce_cart_item_name', array( $this, 'item_name_badges' ), 20, 3 );
	}

	/**
	 * The engine result for the current request, if totals were calculated.
	 *
	 * @return Result|null
	 */
	public static function last_result(): ?Result {
		return self::$last_result;
	}

	/**
	 * Summary stored in the WC session (survives into checkout/order hooks).
	 *
	 * @return array<string, mixed>
	 */
	public static function session_summary(): array {
		if ( ! function_exists( 'WC' ) || ! WC()->session ) {
			return array();
		}
		$summary = WC()->session->get( self::SESSION_KEY );
		return is_array( $summary ) ? $summary : array();
	}

	/**
	 * The mini-cart can be rendered in requests that never calculated totals
	 * (wc-ajax=get_refreshed_fragments restores totals from the session
	 * without recalculating): force one calculation so engine prices and
	 * badges are present in the rendered fragment.
	 */
	public function ensure_calculated(): void {
		if ( null === self::$last_result && function_exists( 'WC' ) && WC()->cart && ! WC()->cart->is_empty() ) {
			WC()->cart->calculate_totals();
		}
	}

	/**
	 * Main entry: run the engine and set line prices.
	 *
	 * @param \WC_Cart $cart Cart.
	 */
	public function apply_discounts( \WC_Cart $cart ): void {
		if ( self::$calculating ) {
			return;
		}
		if ( is_admin() && ! wp_doing_ajax() ) {
			return;
		}
		self::$calculating = true;

		try {
			$this->run( $cart );
		} finally {
			self::$calculating = false;
		}
	}

	/**
	 * Build lines, run the engine, apply prices, store the summary.
	 *
	 * @param \WC_Cart $cart Cart.
	 */
	private function run( \WC_Cart $cart ): void {
		$contents = $cart->get_cart();
		if ( ! $contents ) {
			self::$last_result = null;
			$this->store_summary( null );
			return;
		}

		$promos = $this->repository->get_active();
		$lines  = array();
		$map    = array(); // key => cart item.

		foreach ( $contents as $key => $item ) {
			$product = $item['data'] ?? null;
			if ( ! $product instanceof \WC_Product ) {
				continue;
			}

			// Capture the untouched (sale-aware) price once per request.
			if ( ! isset( self::$base_prices[ $key ] ) ) {
				self::$base_prices[ $key ] = (float) $product->get_price();
			}

			$parent_id = $product->get_parent_id();
			$term_src  = $parent_id ? $parent_id : $product->get_id();

			$lines[] = Cart_Line::from_array(
				array(
					'key'          => (string) $key,
					'product_id'   => $product->get_id(),
					'parent_id'    => $parent_id,
					'qty'          => (int) $item['quantity'],
					'base_price'   => self::$base_prices[ $key ],
					'category_ids' => array_map( 'intval', wc_get_product_term_ids( $term_src, 'product_cat' ) ),
					'tag_ids'      => array_map( 'intval', wc_get_product_term_ids( $term_src, 'product_tag' ) ),
				)
			);
			$map[ $key ] = $item;
		}

		if ( ! $lines ) {
			self::$last_result = null;
			$this->store_summary( null );
			return;
		}

		$result = ( new Discount_Engine() )->calculate( $lines, $promos, time() );

		foreach ( $result->lines as $line ) {
			$item = $map[ $line->key ] ?? null;
			if ( $item && $item['data'] instanceof \WC_Product ) {
				$item['data']->set_price( $line->unit_price );
			}
		}

		self::$last_result = $result;
		$this->store_summary( $result );
	}

	/**
	 * Persist a compact summary for checkout rendering and order hooks.
	 *
	 * @param Result|null $result Engine result.
	 */
	private function store_summary( ?Result $result ): void {
		if ( ! WC()->session ) {
			return;
		}

		// Keep the summary when there is a next-tier hint even with no
		// discount applied yet — the mini-cart progress bar needs it.
		if ( ! $result || ( ! $result->promo_totals && ! $result->next_tier ) ) {
			WC()->session->set( self::SESSION_KEY, array() );
			return;
		}

		$line_discounts = array();
		$line_promos    = array();
		foreach ( $result->lines as $line ) {
			if ( $line->discounts ) {
				$line_discounts[ $line->key ] = $line->discounts;
			}
			if ( $line->applied ) {
				$line_promos[ $line->key ] = array_keys( $line->applied );
			}
		}

		WC()->session->set(
			self::SESSION_KEY,
			array(
				'promo_totals'         => $result->promo_totals,
				'cart_applied'         => $result->cart_applied,
				'next_tier'            => $result->next_tier,
				'base_subtotal'        => $result->base_subtotal,
				'subtotal_before_cart' => $result->subtotal_before_cart,
				'subtotal'             => $result->subtotal,
				'total_saved'          => $result->total_saved(),
				'line_discounts'       => $line_discounts,
				'line_promos'          => $line_promos,
			)
		);
	}

	/**
	 * Cart line for a cart item key from the last result.
	 *
	 * @param string $key Cart item key.
	 * @return Cart_Line|null
	 */
	private function result_line( string $key ): ?Cart_Line {
		return self::$last_result ? self::$last_result->line( $key ) : null;
	}

	/**
	 * Show "<del>old</del> <ins>new</ins>" for discounted line prices.
	 *
	 * @param string               $price_html Original HTML.
	 * @param array<string, mixed> $cart_item  Cart item.
	 * @param string               $key        Cart item key.
	 * @return string
	 */
	public function item_price_html( string $price_html, array $cart_item, string $key ): string {
		$line = $this->result_line( $key );
		if ( ! $line || ! $line->discounts || $line->unit_price >= $line->base_price ) {
			return $price_html;
		}
		return wc_format_sale_price(
			wc_get_price_to_display( $cart_item['data'], array( 'price' => $line->base_price ) ),
			wc_get_price_to_display( $cart_item['data'], array( 'price' => $line->unit_price ) )
		);
	}

	/**
	 * Same treatment for the line subtotal.
	 *
	 * @param string               $subtotal_html Original HTML.
	 * @param array<string, mixed> $cart_item     Cart item.
	 * @param string               $key           Cart item key.
	 * @return string
	 */
	public function item_subtotal_html( string $subtotal_html, array $cart_item, string $key ): string {
		$line = $this->result_line( $key );
		if ( ! $line || ! $line->discounts || $line->unit_price >= $line->base_price ) {
			return $subtotal_html;
		}
		return wc_format_sale_price(
			wc_get_price_to_display( $cart_item['data'], array( 'price' => $line->base_price, 'qty' => $line->qty ) ),
			wc_get_price_to_display( $cart_item['data'], array( 'price' => $line->unit_price, 'qty' => $line->qty ) )
		);
	}

	/**
	 * Append small promotion badges under the item name.
	 *
	 * @param string               $name      Item name HTML.
	 * @param array<string, mixed> $cart_item Cart item.
	 * @param string               $key       Cart item key.
	 * @return string
	 */
	public function item_name_badges( string $name, array $cart_item, string $key ): string {
		// The checkout review is cramped (and themes restyle it heavily) and
		// already shows the per-promotion savings breakdown — skip badges there.
		if ( is_checkout() ) {
			return $name;
		}
		$line = $this->result_line( $key );
		if ( ! $line || ! $line->discounts || ! self::$last_result ) {
			return $name;
		}

		$labels = array();
		foreach ( array_keys( $line->discounts ) as $promo_id ) {
			$info = self::$last_result->promo_totals[ $promo_id ] ?? null;
			if ( $info ) {
				$labels[] = '<span class="pe-badge-inline">' . esc_html( $info['name'] ) . '</span>';
			}
		}
		if ( ! $labels ) {
			return $name;
		}
		return $name . '<span class="pe-item-badges">' . implode( ' ', $labels ) . '</span>';
	}
}

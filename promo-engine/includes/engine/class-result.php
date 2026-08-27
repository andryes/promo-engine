<?php
/**
 * Discount engine calculation result.
 *
 * @package Promo_Engine
 */

namespace Promo_Engine\Engine;

defined( 'ABSPATH' ) || exit;

/**
 * Aggregated outcome of one engine run.
 */
class Result {

	/**
	 * Cart lines with final unit prices and per-promo discounts.
	 *
	 * @var Cart_Line[]
	 */
	public array $lines = array();

	/**
	 * Items subtotal before any promotions.
	 *
	 * @var float
	 */
	public float $base_subtotal = 0.0;

	/**
	 * Items subtotal after item/group stages, before cart-level discounts.
	 *
	 * @var float
	 */
	public float $subtotal_before_cart = 0.0;

	/**
	 * Final items subtotal.
	 *
	 * @var float
	 */
	public float $subtotal = 0.0;

	/**
	 * Cart-level promotions that fired:
	 * array of [ 'promo_id', 'name', 'percent', 'amount' ].
	 *
	 * @var array<int, array{promo_id: int, name: string, percent: float, amount: float}>
	 */
	public array $cart_applied = array();

	/**
	 * Total discount per promotion across the cart:
	 * promo_id => [ 'name', 'type', 'amount' ].
	 *
	 * @var array<int, array{name: string, type: string, amount: float}>
	 */
	public array $promo_totals = array();

	/**
	 * Next unreached cart threshold (for progress UI), or null:
	 * [ 'promo_id', 'name', 'min', 'percent', 'missing' ].
	 *
	 * @var array{promo_id: int, name: string, min: float, percent: float, missing: float}|null
	 */
	public ?array $next_tier = null;

	/**
	 * Total saved across the cart.
	 *
	 * @return float
	 */
	public function total_saved(): float {
		return max( 0.0, $this->base_subtotal - $this->subtotal );
	}

	/**
	 * Find a line by its cart item key.
	 *
	 * @param string $key Cart item key.
	 * @return Cart_Line|null
	 */
	public function line( string $key ): ?Cart_Line {
		foreach ( $this->lines as $line ) {
			if ( $line->key === $key ) {
				return $line;
			}
		}
		return null;
	}
}

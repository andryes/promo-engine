<?php
/**
 * Cart line value object used by the discount engine.
 *
 * @package Promo_Engine
 */

namespace Promo_Engine\Engine;

defined( 'ABSPATH' ) || exit;

/**
 * One cart line (product × quantity) as the engine sees it.
 */
class Cart_Line {

	/**
	 * Cart item key (opaque, echoed back in results).
	 *
	 * @var string
	 */
	public string $key = '';

	/**
	 * Product (or variation) ID.
	 *
	 * @var int
	 */
	public int $product_id = 0;

	/**
	 * Parent product ID for variations, 0 otherwise.
	 *
	 * @var int
	 */
	public int $parent_id = 0;

	/**
	 * Quantity.
	 *
	 * @var int
	 */
	public int $qty = 1;

	/**
	 * Current (sale-aware) unit price before any promotions.
	 *
	 * @var float
	 */
	public float $base_price = 0.0;

	/**
	 * product_cat term IDs.
	 *
	 * @var int[]
	 */
	public array $category_ids = array();

	/**
	 * product_tag term IDs.
	 *
	 * @var int[]
	 */
	public array $tag_ids = array();

	/**
	 * Final unit price after the engine ran (set by the engine).
	 *
	 * @var float
	 */
	public float $unit_price = 0.0;

	/**
	 * Per-promotion discount applied to this line, in currency,
	 * for the whole line (all units): promo_id => amount.
	 *
	 * @var array<int, float>
	 */
	public array $discounts = array();

	/**
	 * Build an instance from an associative array.
	 *
	 * @param array<string, mixed> $data Field values.
	 * @return self
	 */
	public static function from_array( array $data ): self {
		$line = new self();
		foreach ( $data as $key => $value ) {
			if ( property_exists( $line, $key ) ) {
				$line->{$key} = $value;
			}
		}
		if ( ! isset( $data['unit_price'] ) ) {
			$line->unit_price = $line->base_price;
		}
		return $line;
	}

	/**
	 * Record a discount amount against a promotion.
	 *
	 * @param int   $promo_id Promotion ID.
	 * @param float $amount   Discount amount (whole line, currency).
	 */
	public function add_discount( int $promo_id, float $amount ): void {
		if ( $amount <= 0 ) {
			return;
		}
		$this->discounts[ $promo_id ] = ( $this->discounts[ $promo_id ] ?? 0.0 ) + $amount;
	}

	/**
	 * Line total at the current unit price.
	 *
	 * @return float
	 */
	public function total(): float {
		return $this->unit_price * $this->qty;
	}

	/**
	 * Line total before promotions.
	 *
	 * @return float
	 */
	public function base_total(): float {
		return $this->base_price * $this->qty;
	}
}

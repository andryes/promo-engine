<?php
/**
 * Promotion value object used by the discount engine.
 *
 * Deliberately free of WordPress dependencies so the engine
 * can be unit-tested standalone.
 *
 * @package Promo_Engine
 */

namespace Promo_Engine\Engine;

defined( 'ABSPATH' ) || exit;

/**
 * Plain data holder describing one promotion.
 */
class Promotion {

	public const TYPE_PERCENT = 'percent';
	public const TYPE_FIXED   = 'fixed';
	public const TYPE_BOGO    = 'bogo';
	public const TYPE_BUNDLE  = 'bundle';
	public const TYPE_CART    = 'cart_threshold';

	public const SCOPE_PRODUCTS = 'products';
	public const SCOPE_CATEGORY = 'category';
	public const SCOPE_TAG      = 'tag';
	public const SCOPE_CATALOG  = 'catalog';

	/**
	 * Item-level types applied at stage 1.
	 */
	public const ITEM_TYPES = array( self::TYPE_PERCENT, self::TYPE_FIXED );

	/**
	 * Group-level types applied at stage 2.
	 */
	public const GROUP_TYPES = array( self::TYPE_BOGO, self::TYPE_BUNDLE );

	/**
	 * Promotion (post) ID.
	 *
	 * @var int
	 */
	public int $id = 0;

	/**
	 * Human-readable name.
	 *
	 * @var string
	 */
	public string $name = '';

	/**
	 * Discount type, one of the TYPE_* constants.
	 *
	 * @var string
	 */
	public string $type = self::TYPE_PERCENT;

	/**
	 * Priority; the higher number wins.
	 *
	 * @var int
	 */
	public int $priority = 0;

	/**
	 * Whether the promotion combines with others.
	 *
	 * @var bool
	 */
	public bool $stacking = true;

	/**
	 * Max total discount for this promotion, percent of the price
	 * entering the stage. Null = no cap.
	 *
	 * @var float|null
	 */
	public ?float $cap_percent = null;

	/**
	 * Scope type, one of the SCOPE_* constants.
	 *
	 * @var string
	 */
	public string $scope_type = self::SCOPE_CATALOG;

	/**
	 * Product / term IDs the promotion targets (per scope type).
	 *
	 * @var int[]
	 */
	public array $scope_ids = array();

	/**
	 * Percent or fixed amount (per unit) for item-level types.
	 *
	 * @var float
	 */
	public float $amount = 0.0;

	/**
	 * BOGO: how many units the customer buys.
	 *
	 * @var int
	 */
	public int $bogo_buy_qty = 2;

	/**
	 * BOGO: how many (cheapest) units are free per group.
	 *
	 * @var int
	 */
	public int $bogo_get_qty = 1;

	/**
	 * Bundle: number of units in a bundle.
	 *
	 * @var int
	 */
	public int $bundle_qty = 2;

	/**
	 * Bundle: fixed price for the whole bundle.
	 *
	 * @var float
	 */
	public float $bundle_price = 0.0;

	/**
	 * Cart threshold tiers: array of [ 'min' => float, 'percent' => float ].
	 *
	 * @var array<int, array{min: float, percent: float}>
	 */
	public array $tiers = array();

	/**
	 * Start timestamp (UTC) or null.
	 *
	 * @var int|null
	 */
	public ?int $starts_at = null;

	/**
	 * End timestamp (UTC) or null.
	 *
	 * @var int|null
	 */
	public ?int $ends_at = null;

	/**
	 * Whether the promo popup is enabled for this promotion.
	 *
	 * @var bool
	 */
	public bool $popup_enabled = false;

	/**
	 * Max number of orders this promotion can be used in. 0 = unlimited.
	 *
	 * @var int
	 */
	public int $usage_limit = 0;

	/**
	 * How many orders have used this promotion so far.
	 *
	 * @var int
	 */
	public int $used_count = 0;

	/**
	 * Build an instance from an associative array (convenience for tests / repository).
	 *
	 * @param array<string, mixed> $data Field values.
	 * @return self
	 */
	public static function from_array( array $data ): self {
		$promo = new self();
		foreach ( $data as $key => $value ) {
			if ( property_exists( $promo, $key ) ) {
				$promo->{$key} = $value;
			}
		}
		return $promo;
	}

	/**
	 * Whether the promotion is running at the given moment.
	 *
	 * @param int $now UTC timestamp.
	 * @return bool
	 */
	public function is_running( int $now ): bool {
		if ( null !== $this->starts_at && $now < $this->starts_at ) {
			return false;
		}
		if ( null !== $this->ends_at && $now > $this->ends_at ) {
			return false;
		}
		return true;
	}

	/**
	 * Whether the promotion is still under its usage limit.
	 *
	 * @return bool
	 */
	public function has_uses_left(): bool {
		return $this->usage_limit <= 0 || $this->used_count < $this->usage_limit;
	}

	/**
	 * Whether the promotion targets the given cart line.
	 *
	 * @param Cart_Line $line Cart line.
	 * @return bool
	 */
	public function applies_to( Cart_Line $line ): bool {
		switch ( $this->scope_type ) {
			case self::SCOPE_PRODUCTS:
				return in_array( $line->product_id, $this->scope_ids, true )
					|| ( $line->parent_id && in_array( $line->parent_id, $this->scope_ids, true ) );
			case self::SCOPE_CATEGORY:
				return (bool) array_intersect( $line->category_ids, $this->scope_ids );
			case self::SCOPE_TAG:
				return (bool) array_intersect( $line->tag_ids, $this->scope_ids );
			case self::SCOPE_CATALOG:
				return true;
		}
		return false;
	}

	/**
	 * The highest tier reached for the given subtotal, or null.
	 *
	 * @param float $subtotal Cart items subtotal.
	 * @return array{min: float, percent: float}|null
	 */
	public function matched_tier( float $subtotal ): ?array {
		$matched = null;
		foreach ( $this->tiers as $tier ) {
			if ( $subtotal >= $tier['min'] && ( null === $matched || $tier['min'] > $matched['min'] ) ) {
				$matched = $tier;
			}
		}
		return $matched;
	}

	/**
	 * The next tier above the given subtotal (for "add $X to get −Y%"), or null.
	 *
	 * @param float $subtotal Cart items subtotal.
	 * @return array{min: float, percent: float}|null
	 */
	public function next_tier( float $subtotal ): ?array {
		$next = null;
		foreach ( $this->tiers as $tier ) {
			if ( $subtotal < $tier['min'] && ( null === $next || $tier['min'] < $next['min'] ) ) {
				$next = $tier;
			}
		}
		return $next;
	}
}

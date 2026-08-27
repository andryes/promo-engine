<?php
/**
 * The discount calculation engine.
 *
 * Pure PHP, no WordPress/WooCommerce dependencies — unit-testable standalone.
 *
 * Calculation pipeline (per the spec):
 *   Stage 1 — item-level discounts (percent / fixed) per line.
 *   Stage 2 — BOGO / bundles, computed on prices produced by stage 1.
 *   Stage 3 — cart-level threshold discount on the subtotal after stages 1–2,
 *             distributed proportionally across lines (so a WooCommerce
 *             coupon applied afterwards computes on the discounted prices).
 *
 * Combination rule (applied among promotions competing WITHIN a stage):
 *   - if at least one applicable promotion is non-stacking, only the one
 *     with the highest priority (ties broken by lower ID) applies;
 *   - otherwise all apply (percentages multiply);
 *   - stages themselves always compose — this is what the spec's own
 *     examples require (a non-stacking flash promo still combines with a
 *     BOGO from the next stage).
 *
 * Cap: each promotion carries a max-discount percent. When several
 * promotions apply in a stage, the strictest (lowest) cap bounds the total
 * discount produced by that stage, measured against the price entering it.
 *
 * @package Promo_Engine
 */

namespace Promo_Engine\Engine;

defined( 'ABSPATH' ) || exit;

/**
 * Stateless calculator: lines + promotions in, result out.
 */
class Discount_Engine {

	/**
	 * Run the calculation.
	 *
	 * @param Cart_Line[] $lines  Cart lines (mutated: unit_price, discounts).
	 * @param Promotion[] $promos Candidate promotions (active status; date
	 *                            window is checked here against $now).
	 * @param int         $now    Current UTC timestamp.
	 * @return Result
	 */
	public function calculate( array $lines, array $promos, int $now ): Result {
		$result        = new Result();
		$result->lines = $lines;

		$running = array_values(
			array_filter(
				$promos,
				static fn( Promotion $p ): bool => $p->is_running( $now )
			)
		);

		foreach ( $lines as $line ) {
			$line->unit_price = $line->base_price;
			$line->discounts  = array();
			$result->base_subtotal += $line->base_total();
		}

		$this->apply_item_stage( $lines, $running );
		$this->apply_group_stage( $lines, $running );

		foreach ( $lines as $line ) {
			$result->subtotal_before_cart += $line->total();
		}

		$this->apply_cart_stage( $lines, $running, $result );

		foreach ( $lines as $line ) {
			$result->subtotal += $line->total();
		}

		$this->collect_totals( $running, $result );

		return $result;
	}

	/**
	 * Sort promotions by priority (desc), ties by ID (asc) for determinism.
	 *
	 * @param Promotion[] $promos Promotions.
	 * @return Promotion[]
	 */
	private function sort_by_priority( array $promos ): array {
		usort(
			$promos,
			static function ( Promotion $a, Promotion $b ): int {
				if ( $a->priority !== $b->priority ) {
					return $b->priority <=> $a->priority;
				}
				return $a->id <=> $b->id;
			}
		);
		return $promos;
	}

	/**
	 * Resolve the combination rule for a set of competing promotions.
	 *
	 * @param Promotion[] $promos Applicable promotions (same stage).
	 * @return Promotion[] The promotions that actually apply, in order.
	 */
	private function resolve_competition( array $promos ): array {
		if ( ! $promos ) {
			return array();
		}
		$sorted        = $this->sort_by_priority( $promos );
		$has_exclusive = false;
		foreach ( $sorted as $promo ) {
			if ( ! $promo->stacking ) {
				$has_exclusive = true;
				break;
			}
		}
		return $has_exclusive ? array( $sorted[0] ) : $sorted;
	}

	/**
	 * Stage 1: percent / fixed discounts per line.
	 *
	 * @param Cart_Line[] $lines   Cart lines.
	 * @param Promotion[] $running Running promotions.
	 */
	private function apply_item_stage( array $lines, array $running ): void {
		$item_promos = array_filter(
			$running,
			static fn( Promotion $p ): bool => in_array( $p->type, Promotion::ITEM_TYPES, true )
		);
		if ( ! $item_promos ) {
			return;
		}

		foreach ( $lines as $line ) {
			$applicable = array_filter(
				$item_promos,
				static fn( Promotion $p ): bool => $p->applies_to( $line )
			);
			$applied    = $this->resolve_competition( $applicable );
			if ( ! $applied ) {
				continue;
			}

			$entering = $line->unit_price;
			$price    = $entering;
			$given    = array(); // promo_id => per-unit discount.

			foreach ( $applied as $promo ) {
				if ( Promotion::TYPE_PERCENT === $promo->type ) {
					$discount = $price * $promo->amount / 100;
				} else {
					$discount = min( $promo->amount, $price );
				}
				if ( $discount <= 0 ) {
					continue;
				}
				$price             -= $discount;
				$given[ $promo->id ] = ( $given[ $promo->id ] ?? 0.0 ) + $discount;
			}

			$price = $this->apply_cap( $entering, $price, $applied, $given );

			$line->unit_price = $price;
			foreach ( $given as $promo_id => $per_unit ) {
				$line->add_discount( (int) $promo_id, $per_unit * $line->qty );
			}
		}
	}

	/**
	 * Clip the stage discount to the strictest cap among applied promotions.
	 *
	 * Recorded per-promo discounts are scaled proportionally when clipping.
	 *
	 * @param float                $entering Price entering the stage.
	 * @param float                $price    Price after the stage's discounts.
	 * @param Promotion[]          $applied  Promotions that were applied.
	 * @param array<int|string, float> $given Per-promo discount amounts (by reference semantics via array).
	 * @return float Final price after capping (never below 0).
	 */
	private function apply_cap( float $entering, float $price, array $applied, array &$given ): float {
		$caps = array();
		foreach ( $applied as $promo ) {
			if ( null !== $promo->cap_percent ) {
				$caps[] = $promo->cap_percent;
			}
		}

		$price    = max( 0.0, $price );
		$discount = $entering - $price;

		if ( $caps && $discount > 0 ) {
			$max_discount = $entering * min( $caps ) / 100;
			if ( $discount > $max_discount ) {
				$scale = $discount > 0 ? $max_discount / $discount : 0;
				foreach ( $given as $promo_id => $amount ) {
					$given[ $promo_id ] = $amount * $scale;
				}
				$price = $entering - $max_discount;
			}
		}

		return max( 0.0, $price );
	}

	/**
	 * Stage 2: BOGO and fixed-price bundles on post-stage-1 prices.
	 *
	 * Lines are expanded into individual units; every unit participates in
	 * at most one group promotion. Group promotions are processed in
	 * priority order (desc, ties by lower ID).
	 *
	 * @param Cart_Line[] $lines   Cart lines.
	 * @param Promotion[] $running Running promotions.
	 */
	private function apply_group_stage( array $lines, array $running ): void {
		$group_promos = $this->sort_by_priority(
			array_filter(
				$running,
				static fn( Promotion $p ): bool => in_array( $p->type, Promotion::GROUP_TYPES, true )
			)
		);
		if ( ! $group_promos ) {
			return;
		}

		// Expand lines into units. Unit price at this point = post-stage-1 price.
		$units = array();
		foreach ( $lines as $index => $line ) {
			for ( $i = 0; $i < $line->qty; $i++ ) {
				$units[] = array(
					'line'     => $index,
					'price'    => $line->unit_price, // price entering stage 2.
					'consumed' => false,
				);
			}
		}

		foreach ( $group_promos as $promo ) {
			$eligible = array();
			foreach ( $units as $unit_index => $unit ) {
				if ( ! $unit['consumed'] && $promo->applies_to( $lines[ $unit['line'] ] ) ) {
					$eligible[] = $unit_index;
				}
			}

			if ( Promotion::TYPE_BOGO === $promo->type ) {
				$this->apply_bogo( $promo, $units, $eligible, $lines );
			} else {
				$this->apply_bundle( $promo, $units, $eligible, $lines );
			}
		}

		// Fold unit prices back into lines: unit_price = line average.
		$line_totals = array();
		foreach ( $units as $unit ) {
			$line_totals[ $unit['line'] ] = ( $line_totals[ $unit['line'] ] ?? 0.0 ) + $unit['price'];
		}
		foreach ( $line_totals as $index => $total ) {
			$lines[ $index ]->unit_price = $total / $lines[ $index ]->qty;
		}
	}

	/**
	 * "Buy X get Y free": per full group of (X+Y) eligible units the Y
	 * cheapest units are free. Merchant-safe interpretation: the globally
	 * cheapest eligible units become the free ones.
	 *
	 * @param Promotion                                            $promo    Promotion.
	 * @param array<int, array{line:int,price:float,consumed:bool}> $units    All cart units (by reference).
	 * @param int[]                                                $eligible Indexes of eligible units.
	 * @param Cart_Line[]                                          $lines    Cart lines.
	 */
	private function apply_bogo( Promotion $promo, array &$units, array $eligible, array $lines ): void {
		$group_size = $promo->bogo_buy_qty + $promo->bogo_get_qty;
		if ( $group_size < 1 ) {
			return;
		}
		$groups = intdiv( count( $eligible ), $group_size );
		if ( $groups < 1 ) {
			return;
		}

		// Cheapest first.
		usort(
			$eligible,
			static fn( int $a, int $b ): int => $units[ $a ]['price'] <=> $units[ $b ]['price']
		);

		$consumed_count = $groups * $group_size;
		$free_count     = $groups * $promo->bogo_get_qty;
		$consumed       = array_slice( $eligible, 0, $consumed_count );

		foreach ( $consumed as $position => $unit_index ) {
			$units[ $unit_index ]['consumed'] = true;
			if ( $position < $free_count ) {
				$entering = $units[ $unit_index ]['price'];
				$discount = $entering;
				if ( null !== $promo->cap_percent ) {
					$discount = min( $discount, $entering * $promo->cap_percent / 100 );
				}
				if ( $discount > 0 ) {
					$units[ $unit_index ]['price'] -= $discount;
					$lines[ $units[ $unit_index ]['line'] ]->add_discount( $promo->id, $discount );
				}
			}
		}
	}

	/**
	 * Fixed-price bundle: every N eligible units are sold together for a
	 * fixed price, distributed across the units proportionally to their
	 * current prices. Bundles are formed from the most expensive units
	 * first and are only applied when they actually reduce the price.
	 *
	 * @param Promotion                                            $promo    Promotion.
	 * @param array<int, array{line:int,price:float,consumed:bool}> $units    All cart units (by reference).
	 * @param int[]                                                $eligible Indexes of eligible units.
	 * @param Cart_Line[]                                          $lines    Cart lines.
	 */
	private function apply_bundle( Promotion $promo, array &$units, array $eligible, array $lines ): void {
		if ( $promo->bundle_qty < 1 || $promo->bundle_price <= 0 ) {
			return;
		}
		$groups = intdiv( count( $eligible ), $promo->bundle_qty );
		if ( $groups < 1 ) {
			return;
		}

		// Most expensive first.
		usort(
			$eligible,
			static fn( int $a, int $b ): int => $units[ $b ]['price'] <=> $units[ $a ]['price']
		);

		for ( $g = 0; $g < $groups; $g++ ) {
			$members = array_slice( $eligible, $g * $promo->bundle_qty, $promo->bundle_qty );
			$sum     = 0.0;
			foreach ( $members as $unit_index ) {
				$sum += $units[ $unit_index ]['price'];
			}
			if ( $sum <= $promo->bundle_price ) {
				continue; // The bundle would not benefit the customer — skip, leave units available.
			}

			$factor = $promo->bundle_price / $sum;
			foreach ( $members as $unit_index ) {
				$entering = $units[ $unit_index ]['price'];
				$discount = $entering * ( 1 - $factor );
				if ( null !== $promo->cap_percent ) {
					$discount = min( $discount, $entering * $promo->cap_percent / 100 );
				}
				$units[ $unit_index ]['price']   -= $discount;
				$units[ $unit_index ]['consumed'] = true;
				$lines[ $units[ $unit_index ]['line'] ]->add_discount( $promo->id, $discount );
			}
		}
	}

	/**
	 * Stage 3: cart-level threshold discount, distributed across all lines
	 * proportionally (implemented as multiplying every line price), so a
	 * WooCommerce coupon applied later computes on discounted prices.
	 *
	 * Thresholds are matched against the items subtotal after stages 1–2.
	 * Only the highest reached tier of a promotion applies.
	 *
	 * @param Cart_Line[] $lines   Cart lines.
	 * @param Promotion[] $running Running promotions.
	 * @param Result      $result  Result (cart_applied / next_tier are filled).
	 */
	private function apply_cart_stage( array $lines, array $running, Result $result ): void {
		$cart_promos = array_filter(
			$running,
			static fn( Promotion $p ): bool => Promotion::TYPE_CART === $p->type
		);
		if ( ! $cart_promos ) {
			return;
		}

		$applied  = $this->resolve_competition( $cart_promos );
		$subtotal = $result->subtotal_before_cart;
		$current  = $subtotal;

		foreach ( $applied as $promo ) {
			$tier = $promo->matched_tier( $subtotal );

			// Progress hint towards the next tier of the winning promotion.
			$next = $promo->next_tier( $subtotal );
			if ( $next && null === $result->next_tier ) {
				$result->next_tier = array(
					'promo_id' => $promo->id,
					'name'     => $promo->name,
					'min'      => $next['min'],
					'percent'  => $next['percent'],
					'missing'  => $next['min'] - $subtotal,
				);
			}

			if ( ! $tier ) {
				continue;
			}

			$percent = $tier['percent'];
			if ( null !== $promo->cap_percent ) {
				$percent = min( $percent, $promo->cap_percent );
			}
			if ( $percent <= 0 ) {
				continue;
			}

			$amount = $current * $percent / 100;
			foreach ( $lines as $line ) {
				$line_discount = $line->total() * $percent / 100;
				$line->unit_price *= ( 1 - $percent / 100 );
				$line->add_discount( $promo->id, $line_discount );
			}
			$current *= ( 1 - $percent / 100 );

			$result->cart_applied[] = array(
				'promo_id' => $promo->id,
				'name'     => $promo->name,
				'percent'  => $percent,
				'amount'   => $amount,
			);
		}
	}

	/**
	 * Aggregate per-promotion totals across all lines.
	 *
	 * @param Promotion[] $running Running promotions.
	 * @param Result      $result  Result to fill.
	 */
	private function collect_totals( array $running, Result $result ): void {
		$by_id = array();
		foreach ( $running as $promo ) {
			$by_id[ $promo->id ] = $promo;
		}

		foreach ( $result->lines as $line ) {
			foreach ( $line->discounts as $promo_id => $amount ) {
				if ( ! isset( $result->promo_totals[ $promo_id ] ) ) {
					$promo                             = $by_id[ $promo_id ] ?? null;
					$result->promo_totals[ $promo_id ] = array(
						'name'   => $promo ? $promo->name : (string) $promo_id,
						'type'   => $promo ? $promo->type : '',
						'amount' => 0.0,
					);
				}
				$result->promo_totals[ $promo_id ]['amount'] += $amount;
			}
		}
	}
}

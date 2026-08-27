<?php
/**
 * Standalone tests for the Promo Engine discount engine.
 *
 * No WordPress required:  php tests/engine-test.php
 *
 * Covers the 9 mandatory examples from the spec (section 3) plus edge cases.
 *
 * @package Promo_Engine
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' ); // Satisfy the plugin file guards.
}

require __DIR__ . '/../promo-engine/includes/engine/class-promotion.php';
require __DIR__ . '/../promo-engine/includes/engine/class-cart-line.php';
require __DIR__ . '/../promo-engine/includes/engine/class-result.php';
require __DIR__ . '/../promo-engine/includes/engine/class-discount-engine.php';

use Promo_Engine\Engine\Cart_Line;
use Promo_Engine\Engine\Discount_Engine;
use Promo_Engine\Engine\Promotion;

$passed = 0;
$failed = 0;

/**
 * Assert two floats are equal to the cent.
 *
 * @param string $name     Test name.
 * @param float  $expected Expected value.
 * @param float  $actual   Actual value.
 */
function check( string $name, float $expected, float $actual ): void {
	global $passed, $failed;
	if ( abs( round( $expected, 2 ) - round( $actual, 2 ) ) < 0.005 ) {
		++$passed;
		echo "  ok    {$name}\n";
	} else {
		++$failed;
		printf( "  FAIL  %s — expected %.2f, got %.2f\n", $name, $expected, $actual );
	}
}

$now = 1_000_000; // Arbitrary "current" timestamp.

// ---------------------------------------------------------------- fixtures.
const CAT1 = 101; // "Category 1" (Hoodie on the test site).
const CAT2 = 102; // "Category 2" (Shorts).
const CAT3 = 103; // "Category 3" (T-Shirts).

function promo_cat1_20(): Promotion { // Spec promo 1.
	return Promotion::from_array( array(
		'id'          => 1,
		'name'        => 'Category 1 −20%',
		'type'        => Promotion::TYPE_PERCENT,
		'amount'      => 20.0,
		'priority'    => 10,
		'stacking'    => true,
		'cap_percent' => 70.0,
		'scope_type'  => Promotion::SCOPE_CATEGORY,
		'scope_ids'   => array( CAT1 ),
	) );
}

function promo_flash_30( array $product_ids ): Promotion { // Spec promo 2.
	return Promotion::from_array( array(
		'id'          => 2,
		'name'        => 'Flash −30%',
		'type'        => Promotion::TYPE_PERCENT,
		'amount'      => 30.0,
		'priority'    => 40,
		'stacking'    => false,
		'cap_percent' => 70.0,
		'scope_type'  => Promotion::SCOPE_PRODUCTS,
		'scope_ids'   => $product_ids,
	) );
}

function promo_bogo(): Promotion { // Spec promo 3: buy 2 get 1 in CAT2.
	return Promotion::from_array( array(
		'id'           => 3,
		'name'         => 'Buy 2 get 1',
		'type'         => Promotion::TYPE_BOGO,
		'priority'     => 20,
		'stacking'     => true,
		'bogo_buy_qty' => 2,
		'bogo_get_qty' => 1,
		'scope_type'   => Promotion::SCOPE_CATEGORY,
		'scope_ids'    => array( CAT2 ),
	) );
}

function promo_bundle(): Promotion { // Spec promo 4: any 2 from CAT3 for $250.
	return Promotion::from_array( array(
		'id'           => 4,
		'name'         => 'Bundle: 2 for $250',
		'type'         => Promotion::TYPE_BUNDLE,
		'priority'     => 30,
		'stacking'     => false,
		'bundle_qty'   => 2,
		'bundle_price' => 250.0,
		'scope_type'   => Promotion::SCOPE_CATEGORY,
		'scope_ids'    => array( CAT3 ),
	) );
}

function promo_cart_tiers(): Promotion { // Spec promo 5.
	return Promotion::from_array( array(
		'id'       => 5,
		'name'     => 'Cart threshold',
		'type'     => Promotion::TYPE_CART,
		'priority' => 5,
		'stacking' => true,
		'tiers'    => array(
			array( 'min' => 150.0, 'percent' => 10.0 ),
			array( 'min' => 250.0, 'percent' => 15.0 ),
			array( 'min' => 400.0, 'percent' => 20.0 ),
		),
	) );
}

/**
 * Build a cart line.
 *
 * @param int   $id    Product ID.
 * @param float $price Base price.
 * @param int[] $cats  Category IDs.
 * @param int   $qty   Quantity.
 */
function line( int $id, float $price, array $cats = array(), int $qty = 1 ): Cart_Line {
	return Cart_Line::from_array( array(
		'key'          => 'line-' . $id,
		'product_id'   => $id,
		'qty'          => $qty,
		'base_price'   => $price,
		'category_ids' => $cats,
	) );
}

$engine = new Discount_Engine();

echo "Spec examples (section 3):\n";

// 1. Item from Category 1 ($159) + Promo 1 (−20%) → $127.20.
$r = $engine->calculate( array( line( 11, 159, array( CAT1 ) ) ), array( promo_cat1_20() ), $now );
check( 'ex1: $159 −20% = 127.20', 127.20, $r->lines[0]->unit_price );

// 2. Item under two stackable promos −20% and −10% → 159 × 0.8 × 0.9 = 114.48.
$minus10 = Promotion::from_array( array(
	'id'         => 6,
	'name'       => 'Extra −10%',
	'type'       => Promotion::TYPE_PERCENT,
	'amount'     => 10.0,
	'priority'   => 8,
	'stacking'   => true,
	'scope_type' => Promotion::SCOPE_CATALOG,
) );
$r = $engine->calculate( array( line( 11, 159, array( CAT1 ) ) ), array( promo_cat1_20(), $minus10 ), $now );
check( 'ex2: stacked −20% then −10% = 114.48', 114.48, $r->lines[0]->unit_price );

// 3. $108 under non-stacking Promo 2 (−30%) and another −20% → only Promo 2 → 75.60.
$r = $engine->calculate(
	array( line( 12, 108, array( CAT1 ) ) ),           // In CAT1 (−20%) …
	array( promo_cat1_20(), promo_flash_30( array( 12 ) ) ), // … and in the flash list.
	$now
);
check( 'ex3: non-stacking −30% wins alone = 75.60', 75.60, $r->lines[0]->unit_price );
check( 'ex3: only one promo recorded', 1, (float) count( $r->lines[0]->discounts ) );

// 4. 3 items from Category 2 ($108, $90, $120) + BOGO → cheapest ($90) free → $228.
$cart4 = array(
	line( 21, 108, array( CAT2 ) ),
	line( 22, 90, array( CAT2 ) ),
	line( 23, 120, array( CAT2 ) ),
);
$r = $engine->calculate( $cart4, array( promo_bogo() ), $now );
check( 'ex4: BOGO subtotal = 228.00', 228.00, $r->subtotal );
check( 'ex4: $90 item is free', 0.0, $r->line( 'line-22' )->unit_price );
check( 'ex4: paid BOGO lines marked as participating', 1.0, (float) isset( $r->line( 'line-21' )->applied[3], $r->line( 'line-23' )->applied[3] ) );

// 5. Same, but the $120 item is also under Promo 2 (−30%) → 84;
//    cheapest of {108, 90, 84} = 84 free → 108 + 90 = $198.
$cart5 = array(
	line( 21, 108, array( CAT2 ) ),
	line( 22, 90, array( CAT2 ) ),
	line( 23, 120, array( CAT2 ) ),
);
$r = $engine->calculate( $cart5, array( promo_bogo(), promo_flash_30( array( 23 ) ) ), $now );
check( 'ex5: flash + BOGO subtotal = 198.00', 198.00, $r->subtotal );
check( 'ex5: flashed $84 item is the free one', 0.0, $r->line( 'line-23' )->unit_price );

// 6. Subtotal $228 → tier $150 → −10% → $205.20.
$cart6 = array(
	line( 21, 108, array( CAT2 ) ),
	line( 22, 90, array( CAT2 ) ),
	line( 23, 120, array( CAT2 ) ),
);
$r = $engine->calculate( $cart6, array( promo_bogo(), promo_cart_tiers() ), $now );
check( 'ex6: 228 −10% cart tier = 205.20', 205.20, $r->subtotal );
check( 'ex6: subtotal before cart stage = 228.00', 228.00, $r->subtotal_before_cart );

// 7. WooCommerce coupon SAVE10 (−10%) applies AFTER our discounts:
//    because stage 3 lowers the actual line prices, WC computes 10% of 205.20.
check( 'ex7: coupon math on discounted prices = 184.68', 184.68, $r->subtotal * 0.9 );

// 8. Two stackable −50% and −60% → −80%, capped at 70% → −70%.
$p50 = Promotion::from_array( array(
	'id'          => 7,
	'name'        => '−50%',
	'type'        => Promotion::TYPE_PERCENT,
	'amount'      => 50.0,
	'priority'    => 10,
	'stacking'    => true,
	'cap_percent' => 70.0,
	'scope_type'  => Promotion::SCOPE_CATALOG,
) );
$p60 = Promotion::from_array( array(
	'id'          => 8,
	'name'        => '−60%',
	'type'        => Promotion::TYPE_PERCENT,
	'amount'      => 60.0,
	'priority'    => 9,
	'stacking'    => true,
	'cap_percent' => 70.0,
	'scope_type'  => Promotion::SCOPE_CATALOG,
) );
$r = $engine->calculate( array( line( 31, 100 ) ), array( $p50, $p60 ), $now );
check( 'ex8: −50% × −60% capped at −70% → 30.00', 30.00, $r->lines[0]->unit_price );
check( 'ex8: recorded discounts sum to the cap', 70.00, array_sum( $r->lines[0]->discounts ) );

// 9. Regular $120, on sale $100, Promo 1 −20% → from the sale price → $80.
//    (The integration layer feeds the sale price as base_price.)
$r = $engine->calculate( array( line( 32, 100, array( CAT1 ) ) ), array( promo_cat1_20() ), $now );
check( 'ex9: −20% off the $100 sale price = 80.00', 80.00, $r->lines[0]->unit_price );

echo "\nEdge cases:\n";

// Fixed amount per unit.
$fixed = Promotion::from_array( array(
	'id'         => 9,
	'name'       => '$15 off',
	'type'       => Promotion::TYPE_FIXED,
	'amount'     => 15.0,
	'priority'   => 1,
	'stacking'   => true,
	'scope_type' => Promotion::SCOPE_CATALOG,
) );
$r = $engine->calculate( array( line( 41, 100, array(), 2 ) ), array( $fixed ), $now );
check( 'fixed: $15 off per unit, qty 2 → line 170', 170.00, $r->lines[0]->total() );

// Equal priority → lower ID wins among non-stacking.
$a = Promotion::from_array( array(
	'id'         => 10,
	'name'       => 'A −10%',
	'type'       => Promotion::TYPE_PERCENT,
	'amount'     => 10.0,
	'priority'   => 5,
	'stacking'   => false,
	'scope_type' => Promotion::SCOPE_CATALOG,
) );
$b = Promotion::from_array( array(
	'id'         => 11,
	'name'       => 'B −40%',
	'type'       => Promotion::TYPE_PERCENT,
	'amount'     => 40.0,
	'priority'   => 5,
	'stacking'   => false,
	'scope_type' => Promotion::SCOPE_CATALOG,
) );
$r = $engine->calculate( array( line( 42, 100 ) ), array( $b, $a ), $now );
check( 'ties: equal priority → lower ID (−10%) wins', 90.00, $r->lines[0]->unit_price );

// Highest reached tier only (subtotal 420 → −20%, not 10+15+20).
$r = $engine->calculate( array( line( 43, 420 ) ), array( promo_cart_tiers() ), $now );
check( 'tiers: only the highest tier applies (−20%)', 336.00, $r->subtotal );

// Next-tier progress hint: at 228 the next tier is 250 → missing 22.
$r = $engine->calculate( array( line( 44, 228 ) ), array( promo_cart_tiers() ), $now );
check( 'tiers: next-tier missing amount = 22', 22.00, $r->next_tier['missing'] );
check( 'tiers: next-tier percent = 15', 15.00, $r->next_tier['percent'] );

// Bundle: $130 + $140 from CAT3 for $250 → total 250, split proportionally.
$r = $engine->calculate(
	array( line( 51, 130, array( CAT3 ) ), line( 52, 140, array( CAT3 ) ) ),
	array( promo_bundle() ),
	$now
);
check( 'bundle: pair sold for 250.00', 250.00, $r->subtotal );
check( 'bundle: proportional split (140 → 129.63)', 129.63, $r->line( 'line-52' )->unit_price );

// Bundle must not fire when it would RAISE the price ($90 + $90 < $250).
$r = $engine->calculate(
	array( line( 53, 90, array( CAT3 ) ), line( 54, 90, array( CAT3 ) ) ),
	array( promo_bundle() ),
	$now
);
check( 'bundle: skipped when not beneficial', 180.00, $r->subtotal );

// Expired promotion is ignored.
$expired           = promo_cat1_20();
$expired->ends_at  = $now - 10;
$r = $engine->calculate( array( line( 55, 159, array( CAT1 ) ) ), array( $expired ), $now );
check( 'dates: expired promo ignored', 159.00, $r->lines[0]->unit_price );

// Not started yet.
$future            = promo_cat1_20();
$future->starts_at = $now + 10;
$r = $engine->calculate( array( line( 56, 159, array( CAT1 ) ) ), array( $future ), $now );
check( 'dates: future promo ignored', 159.00, $r->lines[0]->unit_price );

// Usage limit: an exhausted promotion is ignored.
$limited              = promo_cat1_20();
$limited->usage_limit = 5;
$limited->used_count  = 5;
$r = $engine->calculate( array( line( 60, 159, array( CAT1 ) ) ), array( $limited ), $now );
check( 'usage: exhausted promo ignored', 159.00, $r->lines[0]->unit_price );

// Usage limit: a promotion with uses left still applies (0 = unlimited).
$limited              = promo_cat1_20();
$limited->usage_limit = 5;
$limited->used_count  = 4;
$r = $engine->calculate( array( line( 61, 159, array( CAT1 ) ) ), array( $limited ), $now );
check( 'usage: promo under limit applies', 127.20, $r->lines[0]->unit_price );

// Percent over 100 is clamped: price floors at 0 and recorded discounts
// match the actual reduction (no inflated analytics numbers).
$over = Promotion::from_array( array(
	'id'         => 13,
	'name'       => '−150%',
	'type'       => Promotion::TYPE_PERCENT,
	'amount'     => 150.0,
	'priority'   => 1,
	'stacking'   => true,
	'scope_type' => Promotion::SCOPE_CATALOG,
) );
$r = $engine->calculate( array( line( 62, 100 ) ), array( $over ), $now );
check( 'clamp: percent > 100 floors price at 0', 0.00, $r->lines[0]->unit_price );
check( 'clamp: recorded discount equals actual reduction', 100.00, array_sum( $r->lines[0]->discounts ) );

// Same overshoot with a cap: the cap wins and the recorded discount matches it.
$over_capped              = Promotion::from_array( array(
	'id'          => 15,
	'name'        => '−150% capped 70',
	'type'        => Promotion::TYPE_PERCENT,
	'amount'      => 150.0,
	'priority'    => 1,
	'stacking'    => true,
	'cap_percent' => 70.0,
	'scope_type'  => Promotion::SCOPE_CATALOG,
) );
$r = $engine->calculate( array( line( 64, 100 ) ), array( $over_capped ), $now );
check( 'clamp: overshoot with cap charges 30', 30.00, $r->lines[0]->unit_price );
check( 'clamp: overshoot with cap records 70', 70.00, array_sum( $r->lines[0]->discounts ) );

// An exclusive cart promo whose threshold is NOT reached is inert and must
// not block a stacking cart promo whose tier IS reached.
$vip = Promotion::from_array( array(
	'id'       => 14,
	'name'     => 'VIP −25% over $500',
	'type'     => Promotion::TYPE_CART,
	'priority' => 50,
	'stacking' => false,
	'tiers'    => array( array( 'min' => 500.0, 'percent' => 25.0 ) ),
) );
$r = $engine->calculate( array( line( 63, 228 ) ), array( $vip, promo_cart_tiers() ), $now );
check( 'cart: unreached exclusive tier does not block others', 205.20, $r->subtotal );
check( 'cart: progress hint follows the competition winner', 500.00, $r->next_tier['min'] );

// BOGO within a single line, qty 3 → one unit free, price averaged.
$r = $engine->calculate( array( line( 57, 90, array( CAT2 ), 3 ) ), array( promo_bogo() ), $now );
check( 'bogo: qty 3 of one product → line total 180', 180.00, $r->lines[0]->total() );

// Discount never goes below zero.
$big_fixed = Promotion::from_array( array(
	'id'         => 12,
	'name'       => '$500 off',
	'type'       => Promotion::TYPE_FIXED,
	'amount'     => 500.0,
	'priority'   => 1,
	'stacking'   => true,
	'scope_type' => Promotion::SCOPE_CATALOG,
) );
$r = $engine->calculate( array( line( 58, 100 ) ), array( $big_fixed ), $now );
check( 'floor: price clamped at 0', 0.00, $r->lines[0]->unit_price );

// Promo totals aggregate across the cart (ex6 scenario: BOGO 90 + cart 22.80).
$cart_t = array(
	line( 21, 108, array( CAT2 ) ),
	line( 22, 90, array( CAT2 ) ),
	line( 23, 120, array( CAT2 ) ),
);
$r = $engine->calculate( $cart_t, array( promo_bogo(), promo_cart_tiers() ), $now );
check( 'totals: BOGO promo total = 90', 90.00, $r->promo_totals[3]['amount'] );
check( 'totals: cart promo total = 22.80', 22.80, $r->promo_totals[5]['amount'] );
check( 'totals: total saved = 112.80', 112.80, $r->total_saved() );

echo "\n";
printf( "%d passed, %d failed\n", $passed, $failed );
exit( $failed > 0 ? 1 : 0 );

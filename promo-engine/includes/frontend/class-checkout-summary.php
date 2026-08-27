<?php
/**
 * Checkout savings breakdown.
 *
 * @package Promo_Engine
 */

namespace Promo_Engine\Frontend;

use Promo_Engine\Cart\Cart_Hooks;

defined( 'ABSPATH' ) || exit;

/**
 * Adds a per-promotion savings breakdown to the checkout review table.
 */
class Checkout_Summary {

	/**
	 * Hook everything.
	 */
	public function register(): void {
		add_action( 'woocommerce_review_order_before_order_total', array( $this, 'render' ) );
	}

	/**
	 * Render breakdown rows.
	 */
	public function render(): void {
		$summary = Cart_Hooks::session_summary();
		if ( empty( $summary['promo_totals'] ) ) {
			return;
		}
		?>
		<tr class="pe-checkout-savings">
			<th><?php esc_html_e( 'Promo savings', 'promo-engine' ); ?></th>
			<td>−<?php echo wp_kses_post( wc_price( (float) $summary['total_saved'] ) ); ?></td>
		</tr>
		<?php foreach ( $summary['promo_totals'] as $info ) : ?>
			<tr class="pe-checkout-savings-line">
				<th class="pe-checkout-savings-line__name"><?php echo esc_html( $info['name'] ); ?></th>
				<td>−<?php echo wp_kses_post( wc_price( (float) $info['amount'] ) ); ?></td>
			</tr>
		<?php endforeach; ?>
		<?php
	}
}

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
			<td>
				−<?php echo wp_kses_post( wc_price( (float) $summary['total_saved'] ) ); ?>
				<ul class="pe-checkout-savings__list">
					<?php foreach ( $summary['promo_totals'] as $info ) : ?>
						<li>
							<span class="pe-checkout-savings__name"><?php echo esc_html( $info['name'] ); ?></span>
							<span class="pe-checkout-savings__amount">−<?php echo wp_kses_post( wc_price( (float) $info['amount'] ) ); ?></span>
						</li>
					<?php endforeach; ?>
				</ul>
			</td>
		</tr>
		<?php
	}
}

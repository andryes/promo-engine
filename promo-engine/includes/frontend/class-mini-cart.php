<?php
/**
 * Mini-cart savings summary + threshold progress.
 *
 * @package Promo_Engine
 */

namespace Promo_Engine\Frontend;

use Promo_Engine\Cart\Cart_Hooks;

defined( 'ABSPATH' ) || exit;

/**
 * Renders applied discounts and "add $X to get −Y%" progress inside the
 * mini-cart widget (refreshed via standard WooCommerce cart fragments)
 * and a savings row on the cart totals table.
 */
class Mini_Cart {

	/**
	 * Hook everything.
	 */
	public function register(): void {
		add_action( 'woocommerce_widget_shopping_cart_before_buttons', array( $this, 'render_widget_summary' ) );
		add_action( 'woocommerce_cart_totals_before_order_total', array( $this, 'render_cart_totals_row' ) );
	}

	/**
	 * Mini-cart block: savings + progress bar.
	 */
	public function render_widget_summary(): void {
		$summary = Cart_Hooks::session_summary();
		if ( ! $summary ) {
			return;
		}
		?>
		<div class="pe-minicart">
			<?php if ( ! empty( $summary['promo_totals'] ) ) : ?>
				<p class="pe-minicart__saved">
					<?php
					printf(
						/* translators: %s: amount saved. */
						esc_html__( 'You save %s', 'promo-engine' ),
						wp_kses_post( wc_price( (float) $summary['total_saved'] ) )
					);
					?>
				</p>
				<ul class="pe-minicart__promos">
					<?php foreach ( $summary['promo_totals'] as $info ) : ?>
						<li>
							<span class="pe-minicart__promo-name"><?php echo esc_html( $info['name'] ); ?></span>
							<span class="pe-minicart__promo-amount">−<?php echo wp_kses_post( wc_price( (float) $info['amount'] ) ); ?></span>
						</li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>
			<?php $this->render_progress( $summary ); ?>
		</div>
		<?php
	}

	/**
	 * Progress towards the next cart threshold.
	 *
	 * @param array<string, mixed> $summary Session summary.
	 */
	private function render_progress( array $summary ): void {
		$next = $summary['next_tier'] ?? null;
		if ( ! $next ) {
			return;
		}

		$current = (float) ( $summary['subtotal_before_cart'] ?? 0 );
		$target  = (float) $next['min'];
		$percent = $target > 0 ? min( 100, $current / $target * 100 ) : 0;
		?>
		<div class="pe-progress">
			<p class="pe-progress__label">
				<?php
				printf(
					/* translators: 1: missing amount, 2: discount percent. */
					esc_html__( 'Add %1$s more — get −%2$s%%', 'promo-engine' ),
					wp_kses_post( wc_price( (float) $next['missing'] ) ),
					esc_html( wc_format_localized_decimal( (string) $next['percent'] ) )
				);
				?>
			</p>
			<div class="pe-progress__bar" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?php echo esc_attr( (string) round( $percent ) ); ?>">
				<span class="pe-progress__fill" style="width: <?php echo esc_attr( (string) round( $percent, 1 ) ); ?>%"></span>
			</div>
		</div>
		<?php
	}

	/**
	 * "You save" row on the cart totals table.
	 */
	public function render_cart_totals_row(): void {
		$summary = Cart_Hooks::session_summary();
		if ( empty( $summary['promo_totals'] ) ) {
			return;
		}
		?>
		<tr class="pe-cart-savings">
			<th><?php esc_html_e( 'Promo savings', 'promo-engine' ); ?></th>
			<td data-title="<?php esc_attr_e( 'Promo savings', 'promo-engine' ); ?>">
				−<?php echo wp_kses_post( wc_price( (float) $summary['total_saved'] ) ); ?>
			</td>
		</tr>
		<?php
	}
}

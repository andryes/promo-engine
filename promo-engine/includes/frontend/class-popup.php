<?php
/**
 * Promo popup with countdown.
 *
 * @package Promo_Engine
 */

namespace Promo_Engine\Frontend;

use Promo_Engine\Engine\Promotion;
use Promo_Engine\Repository;

defined( 'ABSPATH' ) || exit;

/**
 * Renders the (hidden) popup markup in the footer. The JS decides whether
 * to show it: once per session, never on checkout, accessible (Esc close,
 * focus trap, prefers-reduced-motion respected).
 */
class Popup {

	/**
	 * Promotions repository.
	 *
	 * @var Repository
	 */
	private Repository $repository;

	/**
	 * Constructor.
	 *
	 * @param Repository $repository Promotions repository.
	 */
	public function __construct( Repository $repository ) {
		$this->repository = $repository;
	}

	/**
	 * Hook the footer render.
	 */
	public function register(): void {
		add_action( 'wp_footer', array( $this, 'render' ) );
	}

	/**
	 * Print the popup markup.
	 */
	public function render(): void {
		if ( is_admin() || is_checkout() ) {
			return;
		}

		$promo = null;
		foreach ( $this->repository->get_running() as $candidate ) {
			if ( $candidate->popup_enabled && ( null === $promo || $candidate->priority > $promo->priority ) ) {
				$promo = $candidate;
			}
		}
		if ( ! $promo ) {
			return;
		}

		$discount = '';
		if ( Promotion::TYPE_PERCENT === $promo->type ) {
			/* translators: %s: percent value. */
			$discount = sprintf( __( '−%s%%', 'promo-engine' ), wc_format_localized_decimal( (string) $promo->amount ) );
		} elseif ( Promotion::TYPE_FIXED === $promo->type ) {
			$discount = '−' . wp_strip_all_tags( wc_price( $promo->amount ) );
		}
		?>
		<div class="pe-popup" id="pe-popup" role="dialog" aria-modal="true" aria-labelledby="pe-popup-title" hidden>
			<div class="pe-popup__overlay" data-pe-close></div>
			<div class="pe-popup__box">
				<button type="button" class="pe-popup__close" data-pe-close aria-label="<?php esc_attr_e( 'Close', 'promo-engine' ); ?>">&times;</button>
				<?php if ( $discount ) : ?>
					<p class="pe-popup__discount"><?php echo esc_html( $discount ); ?></p>
				<?php endif; ?>
				<h2 class="pe-popup__title" id="pe-popup-title"><?php echo esc_html( $promo->name ); ?></h2>
				<?php if ( $promo->ends_at ) : ?>
					<p class="pe-popup__timer" data-pe-countdown aria-live="off">
						<span class="pe-popup__timer-value">--:--:--</span>
					</p>
				<?php endif; ?>
				<p class="pe-popup__cta">
					<button type="button" class="button pe-popup__button" data-pe-cta>
						<?php esc_html_e( 'Shop the deal', 'promo-engine' ); ?>
					</button>
				</p>
			</div>
		</div>
		<?php
	}
}

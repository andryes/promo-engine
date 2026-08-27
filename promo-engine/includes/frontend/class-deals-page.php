<?php
/**
 * The /deals/ page shortcode.
 *
 * @package Promo_Engine
 */

namespace Promo_Engine\Frontend;

use Promo_Engine\Admin\Promotion_CPT;
use Promo_Engine\Engine\Promotion;
use Promo_Engine\Repository;

defined( 'ABSPATH' ) || exit;

/**
 * Renders active promotions with their products using standard
 * WooCommerce product loop templates.
 */
class Deals_Page {

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
	 * Hook the shortcode.
	 */
	public function register(): void {
		add_shortcode( 'promo_engine_deals', array( $this, 'render' ) );
	}

	/**
	 * Render all running promotions.
	 *
	 * @return string
	 */
	public function render(): string {
		$promos = $this->repository->get_running();
		if ( ! $promos ) {
			return '<p class="pe-deals-empty">' . esc_html__( 'No active deals right now — check back soon!', 'promo-engine' ) . '</p>';
		}

		usort(
			$promos,
			static fn( Promotion $a, Promotion $b ): int => $b->priority <=> $a->priority
		);

		ob_start();
		echo '<div class="pe-deals">';
		foreach ( $promos as $promo ) {
			$this->render_promo( $promo );
		}
		echo '</div>';
		return (string) ob_get_clean();
	}

	/**
	 * Render one promotion section.
	 *
	 * @param Promotion $promo Promotion.
	 */
	private function render_promo( Promotion $promo ): void {
		?>
		<section class="pe-deal" id="pe-deal-<?php echo (int) $promo->id; ?>">
			<header class="pe-deal__header">
				<h2 class="pe-deal__title"><?php echo esc_html( $promo->name ); ?></h2>
				<p class="pe-deal__meta">
					<span class="pe-deal__rule"><?php echo esc_html( $this->describe( $promo ) ); ?></span>
					<?php if ( $promo->ends_at ) : ?>
						<span class="pe-deal__ends">
							<?php
							printf(
								/* translators: %s: formatted end date. */
								esc_html__( 'Ends %s', 'promo-engine' ),
								esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $promo->ends_at ) )
							);
							?>
						</span>
					<?php endif; ?>
				</p>
			</header>
			<?php
			if ( Promotion::TYPE_CART === $promo->type ) {
				$this->render_tiers( $promo );
			} else {
				$this->render_products( $promo );
			}
			?>
		</section>
		<?php
	}

	/**
	 * Human description of the promotion rule.
	 *
	 * @param Promotion $promo Promotion.
	 * @return string
	 */
	private function describe( Promotion $promo ): string {
		switch ( $promo->type ) {
			case Promotion::TYPE_PERCENT:
				/* translators: %s: percent value. */
				return sprintf( __( '−%s%% off', 'promo-engine' ), wc_format_localized_decimal( (string) $promo->amount ) );
			case Promotion::TYPE_FIXED:
				/* translators: %s: amount. */
				return sprintf( __( '%s off per item', 'promo-engine' ), wp_strip_all_tags( wc_price( $promo->amount ) ) );
			case Promotion::TYPE_BOGO:
				/* translators: 1: buy quantity, 2: free quantity. */
				return sprintf( __( 'Buy %1$d — get %2$d cheapest free', 'promo-engine' ), $promo->bogo_buy_qty, $promo->bogo_get_qty );
			case Promotion::TYPE_BUNDLE:
				/* translators: 1: bundle size, 2: bundle price. */
				return sprintf( __( 'Any %1$d for %2$s', 'promo-engine' ), $promo->bundle_qty, wp_strip_all_tags( wc_price( $promo->bundle_price ) ) );
			case Promotion::TYPE_CART:
				return __( 'Cart discount by subtotal', 'promo-engine' );
		}
		return '';
	}

	/**
	 * Render the tier ladder for a cart-level promotion.
	 *
	 * @param Promotion $promo Promotion.
	 */
	private function render_tiers( Promotion $promo ): void {
		if ( ! $promo->tiers ) {
			return;
		}
		echo '<ul class="pe-deal__tiers">';
		foreach ( $promo->tiers as $tier ) {
			printf(
				'<li>%s</li>',
				sprintf(
					/* translators: 1: subtotal threshold, 2: percent. */
					esc_html__( 'Spend %1$s — get −%2$s%%', 'promo-engine' ),
					wp_kses_post( wc_price( $tier['min'] ) ),
					esc_html( wc_format_localized_decimal( (string) $tier['percent'] ) )
				)
			);
		}
		echo '</ul>';
	}

	/**
	 * Render the promotion's products with standard WooCommerce templates.
	 *
	 * @param Promotion $promo Promotion.
	 */
	private function render_products( Promotion $promo ): void {
		$args = array(
			'post_type'      => 'product',
			'post_status'    => 'publish',
			'posts_per_page' => 8,
			'orderby'        => 'ID',
			'order'          => 'ASC',
			'no_found_rows'  => true,
		);

		switch ( $promo->scope_type ) {
			case Promotion::SCOPE_PRODUCTS:
				if ( ! $promo->scope_ids ) {
					return;
				}
				$args['post__in'] = $promo->scope_ids;
				$args['orderby']  = 'post__in';
				break;
			case Promotion::SCOPE_CATEGORY:
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- limited, front page render.
				$args['tax_query'] = array(
					array(
						'taxonomy' => 'product_cat',
						'terms'    => $promo->scope_ids,
					),
				);
				break;
			case Promotion::SCOPE_TAG:
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- limited, front page render.
				$args['tax_query'] = array(
					array(
						'taxonomy' => 'product_tag',
						'terms'    => $promo->scope_ids,
					),
				);
				break;
			case Promotion::SCOPE_CATALOG:
				$args['posts_per_page'] = 4;
				break;
		}

		$query = new \WP_Query( $args );
		if ( ! $query->have_posts() ) {
			return;
		}

		wc_setup_loop(
			array(
				'name'         => 'pe_deals',
				'columns'      => 4,
				'is_shortcode' => true,
				'total'        => $query->post_count,
			)
		);

		woocommerce_product_loop_start();
		while ( $query->have_posts() ) {
			$query->the_post();
			wc_get_template_part( 'content', 'product' );
		}
		woocommerce_product_loop_end();
		woocommerce_reset_loop();
		wp_reset_postdata();
	}
}

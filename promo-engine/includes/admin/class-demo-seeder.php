<?php
/**
 * Demo promotions seeder (admin button + WP-CLI).
 *
 * @package Promo_Engine
 */

namespace Promo_Engine\Admin;

use Promo_Engine\Engine\Promotion;
use Promo_Engine\Repository;

defined( 'ABSPATH' ) || exit;

/**
 * Creates the five spec demo promotions and the SAVE10 coupon.
 *
 * Idempotent: promotions are matched by a hidden _pe_demo_key meta and
 * updated in place on re-runs.
 *
 * Spec category mapping on the test site:
 *   Category 1 → product_cat "hoodie"   (−20%)
 *   Category 2 → product_cat "shorts"   (buy 2 get 1)
 *   Category 3 → product_cat "t-shirts" (2 for $250)
 */
class Demo_Seeder {

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
	 * Hook everything.
	 */
	public function register(): void {
		add_action( 'admin_post_pe_load_demo', array( $this, 'handle_admin_action' ) );
		add_action( 'manage_posts_extra_tablenav', array( $this, 'render_button' ) );
		add_action( 'admin_notices', array( $this, 'maybe_notice' ) );

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			\WP_CLI::add_command(
				'promo-engine seed',
				function (): void {
					$report = $this->seed();
					foreach ( $report as $line ) {
						\WP_CLI::log( $line );
					}
					\WP_CLI::success( 'Demo promotions seeded.' );
				}
			);
		}
	}

	/**
	 * "Load demo" button on the promotions list table.
	 *
	 * @param string $which Table nav position.
	 */
	public function render_button( string $which ): void {
		$screen = get_current_screen();
		if ( 'top' !== $which || ! $screen || 'edit-' . Promotion_CPT::POST_TYPE !== $screen->id ) {
			return;
		}
		$url = wp_nonce_url( admin_url( 'admin-post.php?action=pe_load_demo' ), 'pe_load_demo' );
		printf(
			'<div class="alignleft actions"><a href="%s" class="button">%s</a></div>',
			esc_url( $url ),
			esc_html__( 'Load demo promotions', 'promo-engine' )
		);
	}

	/**
	 * Success notice after seeding.
	 */
	public function maybe_notice(): void {
		if ( ! isset( $_GET['pe_demo'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only flag.
			return;
		}
		echo '<div class="notice notice-success is-dismissible"><p>';
		esc_html_e( 'Demo promotions and the SAVE10 coupon have been created/updated.', 'promo-engine' );
		echo '</p></div>';
	}

	/**
	 * admin-post handler.
	 */
	public function handle_admin_action(): void {
		check_admin_referer( 'pe_load_demo' );
		if ( ! current_user_can( 'manage_woocommerce' ) && ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'promo-engine' ) );
		}
		$this->seed();
		wp_safe_redirect( admin_url( 'edit.php?post_type=' . Promotion_CPT::POST_TYPE . '&pe_demo=done' ) );
		exit;
	}

	/**
	 * Create/update the five demo promotions + SAVE10 coupon.
	 *
	 * @return string[] Report lines.
	 */
	public function seed(): array {
		$report = array();

		$cat1 = $this->term_id( 'hoodie' );
		$cat2 = $this->term_id( 'shorts' );
		$cat3 = $this->term_id( 't-shirts' );

		$flash_products = array_merge(
			$this->pick_products( $cat2, 2 ),
			$this->pick_products( $cat3, 3 )
		);

		$ends = ( new \DateTimeImmutable( 'now', wp_timezone() ) )->modify( '+2 days' )->format( 'Y-m-d\TH:i' );

		$report[] = $this->upsert(
			'demo1',
			__( 'Hoodies −20%', 'promo-engine' ),
			array(
				'_pe_type'       => Promotion::TYPE_PERCENT,
				'_pe_amount'     => '20',
				'_pe_priority'   => 10,
				'_pe_stacking'   => '1',
				'_pe_cap'        => '70',
				'_pe_scope_type' => Promotion::SCOPE_CATEGORY,
				'_pe_categories' => $cat1 ? array( $cat1 ) : array(),
			)
		);

		$report[] = $this->upsert(
			'demo2',
			__( 'Flash Sale −30%', 'promo-engine' ),
			array(
				'_pe_type'       => Promotion::TYPE_PERCENT,
				'_pe_amount'     => '30',
				'_pe_priority'   => 40,
				'_pe_stacking'   => '0',
				'_pe_cap'        => '70',
				'_pe_scope_type' => Promotion::SCOPE_PRODUCTS,
				'_pe_products'   => $flash_products,
				'_pe_ends'          => $ends,
				'_pe_popup'         => '1',
				'_pe_popup_title_a' => __( 'Flash Sale: −30% off', 'promo-engine' ),
				'_pe_popup_title_b' => __( 'Hurry! 48 hours only — −30%', 'promo-engine' ),
			)
		);

		$report[] = $this->upsert(
			'demo3',
			__( 'Shorts: Buy 2 Get 1 Free', 'promo-engine' ),
			array(
				'_pe_type'       => Promotion::TYPE_BOGO,
				'_pe_bogo_buy'   => 2,
				'_pe_bogo_get'   => 1,
				'_pe_priority'   => 20,
				'_pe_stacking'   => '1',
				'_pe_scope_type' => Promotion::SCOPE_CATEGORY,
				'_pe_categories' => $cat2 ? array( $cat2 ) : array(),
			)
		);

		$report[] = $this->upsert(
			'demo4',
			__( 'T-Shirt Bundle: 2 for $250', 'promo-engine' ),
			array(
				'_pe_type'         => Promotion::TYPE_BUNDLE,
				'_pe_bundle_qty'   => 2,
				'_pe_bundle_price' => '250',
				'_pe_priority'     => 30,
				'_pe_stacking'     => '0',
				'_pe_scope_type'   => Promotion::SCOPE_CATEGORY,
				'_pe_categories'   => $cat3 ? array( $cat3 ) : array(),
			)
		);

		$report[] = $this->upsert(
			'demo5',
			__( 'Cart Savings: up to −20%', 'promo-engine' ),
			array(
				'_pe_type'       => Promotion::TYPE_CART,
				'_pe_priority'   => 5,
				'_pe_stacking'   => '1',
				'_pe_scope_type' => Promotion::SCOPE_CATALOG,
				'_pe_tiers'      => array(
					array(
						'min'     => 150.0,
						'percent' => 10.0,
					),
					array(
						'min'     => 250.0,
						'percent' => 15.0,
					),
					array(
						'min'     => 400.0,
						'percent' => 20.0,
					),
				),
			)
		);

		$report[] = $this->ensure_coupon();

		$this->repository->flush();

		return $report;
	}

	/**
	 * Create or update one demo promotion.
	 *
	 * @param string               $key   Demo key.
	 * @param string               $title Post title.
	 * @param array<string, mixed> $meta  Meta values (defaults are merged in).
	 * @return string Report line.
	 */
	private function upsert( string $key, string $title, array $meta ): string {
		$existing = get_posts(
			array(
				'post_type'      => Promotion_CPT::POST_TYPE,
				'post_status'    => 'any',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key, WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- one-off admin action.
				'meta_key'       => '_pe_demo_key',
				'meta_value'     => $key,
			)
		);

		$defaults = array(
			'_pe_status'       => 'active',
			'_pe_stacking'     => '1',
			'_pe_priority'     => 0,
			'_pe_cap'          => '',
			'_pe_amount'       => '0',
			'_pe_bogo_buy'     => 2,
			'_pe_bogo_get'     => 1,
			'_pe_bundle_qty'   => 2,
			'_pe_bundle_price' => '0',
			'_pe_products'     => array(),
			'_pe_categories'   => array(),
			'_pe_tags'         => array(),
			'_pe_tiers'        => array(),
			'_pe_starts'        => '',
			'_pe_ends'          => '',
			'_pe_popup'         => '0',
			'_pe_popup_title_a' => '',
			'_pe_popup_title_b' => '',
			'_pe_usage_limit'   => 0,
			'_pe_demo_key'      => $key,
		);
		$meta     = array_merge( $defaults, $meta );

		if ( $existing ) {
			$post_id = (int) $existing[0];
			wp_update_post(
				array(
					'ID'          => $post_id,
					'post_title'  => $title,
					'post_status' => 'publish',
				)
			);
			$action = 'updated';
		} else {
			$post_id = (int) wp_insert_post(
				array(
					'post_type'   => Promotion_CPT::POST_TYPE,
					'post_status' => 'publish',
					'post_title'  => $title,
				)
			);
			$action = 'created';
		}

		foreach ( $meta as $meta_key => $value ) {
			update_post_meta( $post_id, $meta_key, $value );
		}

		return sprintf( '%s: %s (#%d)', $action, $title, $post_id );
	}

	/**
	 * Ensure the SAVE10 percent coupon exists.
	 *
	 * @return string Report line.
	 */
	private function ensure_coupon(): string {
		$code = 'SAVE10';
		if ( wc_get_coupon_id_by_code( $code ) ) {
			return 'exists: coupon ' . $code;
		}
		$coupon = new \WC_Coupon();
		$coupon->set_code( $code );
		$coupon->set_discount_type( 'percent' );
		$coupon->set_amount( 10 );
		$coupon->save();
		return 'created: coupon ' . $code;
	}

	/**
	 * Resolve a product_cat term ID by slug.
	 *
	 * @param string $slug Term slug.
	 * @return int 0 when not found.
	 */
	private function term_id( string $slug ): int {
		$term = get_term_by( 'slug', $slug, 'product_cat' );
		return $term instanceof \WP_Term ? (int) $term->term_id : 0;
	}

	/**
	 * Pick the first N published products of a category (deterministic: ID asc).
	 *
	 * @param int $term_id Category term ID.
	 * @param int $count   How many.
	 * @return int[]
	 */
	private function pick_products( int $term_id, int $count ): array {
		if ( ! $term_id ) {
			return array();
		}
		$ids = get_posts(
			array(
				'post_type'      => 'product',
				'post_status'    => 'publish',
				'posts_per_page' => $count,
				'orderby'        => 'ID',
				'order'          => 'ASC',
				'fields'         => 'ids',
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- one-off admin action.
				'tax_query'      => array(
					array(
						'taxonomy' => 'product_cat',
						'terms'    => $term_id,
					),
				),
			)
		);
		return array_map( 'intval', $ids );
	}
}

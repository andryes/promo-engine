<?php
/**
 * Promotion custom post type.
 *
 * @package Promo_Engine
 */

namespace Promo_Engine\Admin;

use Promo_Engine\Engine\Promotion;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the pe_promotion post type and its list-table columns.
 */
class Promotion_CPT {

	public const POST_TYPE = 'pe_promotion';

	/**
	 * Hook everything.
	 */
	public function register(): void {
		add_action( 'init', array( $this, 'register_post_type' ) );
		add_filter( 'manage_' . self::POST_TYPE . '_posts_columns', array( $this, 'columns' ) );
		add_action( 'manage_' . self::POST_TYPE . '_posts_custom_column', array( $this, 'column_content' ), 10, 2 );
	}

	/**
	 * Register the post type.
	 */
	public function register_post_type(): void {
		register_post_type(
			self::POST_TYPE,
			array(
				'labels'          => array(
					'name'               => __( 'Promotions', 'promo-engine' ),
					'singular_name'      => __( 'Promotion', 'promo-engine' ),
					'add_new'            => __( 'Add promotion', 'promo-engine' ),
					'add_new_item'       => __( 'Add promotion', 'promo-engine' ),
					'edit_item'          => __( 'Edit promotion', 'promo-engine' ),
					'new_item'           => __( 'New promotion', 'promo-engine' ),
					'search_items'       => __( 'Search promotions', 'promo-engine' ),
					'not_found'          => __( 'No promotions found.', 'promo-engine' ),
					'not_found_in_trash' => __( 'No promotions found in Trash.', 'promo-engine' ),
					'menu_name'          => __( 'Promo Engine', 'promo-engine' ),
				),
				'public'          => false,
				'show_ui'         => true,
				'show_in_menu'    => true,
				'menu_position'   => 56,
				'menu_icon'       => 'dashicons-megaphone',
				'supports'        => array( 'title' ),
				// Promotions change storefront prices — restrict every operation
				// to users who can manage the store (admins, shop managers).
				'capability_type' => 'post',
				'map_meta_cap'    => false,
				'capabilities'    => array(
					'edit_post'              => 'manage_woocommerce',
					'read_post'              => 'manage_woocommerce',
					'delete_post'            => 'manage_woocommerce',
					'edit_posts'             => 'manage_woocommerce',
					'edit_others_posts'      => 'manage_woocommerce',
					'publish_posts'          => 'manage_woocommerce',
					'read_private_posts'     => 'manage_woocommerce',
					'delete_posts'           => 'manage_woocommerce',
					'delete_others_posts'    => 'manage_woocommerce',
					'delete_published_posts' => 'manage_woocommerce',
					'delete_private_posts'   => 'manage_woocommerce',
					'edit_published_posts'   => 'manage_woocommerce',
					'edit_private_posts'     => 'manage_woocommerce',
					'create_posts'           => 'manage_woocommerce',
				),
			)
		);
	}

	/**
	 * List table columns.
	 *
	 * @param array<string, string> $columns Columns.
	 * @return array<string, string>
	 */
	public function columns( array $columns ): array {
		return array(
			'cb'          => $columns['cb'] ?? '',
			'title'       => __( 'Promotion', 'promo-engine' ),
			'pe_status'   => __( 'Status', 'promo-engine' ),
			'pe_type'     => __( 'Type', 'promo-engine' ),
			'pe_scope'    => __( 'Applies to', 'promo-engine' ),
			'pe_priority' => __( 'Priority', 'promo-engine' ),
			'pe_stacking' => __( 'Stacking', 'promo-engine' ),
			'pe_period'   => __( 'Period', 'promo-engine' ),
			'pe_usage'    => __( 'Usage', 'promo-engine' ),
		);
	}

	/**
	 * Human-readable labels for discount types.
	 *
	 * @return array<string, string>
	 */
	public static function type_labels(): array {
		return array(
			Promotion::TYPE_PERCENT => __( 'Percentage', 'promo-engine' ),
			Promotion::TYPE_FIXED   => __( 'Fixed amount', 'promo-engine' ),
			Promotion::TYPE_BOGO    => __( 'Buy X get Y', 'promo-engine' ),
			Promotion::TYPE_BUNDLE  => __( 'Bundle for fixed price', 'promo-engine' ),
			Promotion::TYPE_CART    => __( 'Cart discount by subtotal', 'promo-engine' ),
		);
	}

	/**
	 * Human-readable labels for scopes.
	 *
	 * @return array<string, string>
	 */
	public static function scope_labels(): array {
		return array(
			Promotion::SCOPE_PRODUCTS => __( 'Selected products', 'promo-engine' ),
			Promotion::SCOPE_CATEGORY => __( 'Categories', 'promo-engine' ),
			Promotion::SCOPE_TAG      => __( 'Tags', 'promo-engine' ),
			Promotion::SCOPE_CATALOG  => __( 'Whole catalog', 'promo-engine' ),
		);
	}

	/**
	 * Render a custom column.
	 *
	 * @param string $column  Column key.
	 * @param int    $post_id Post ID.
	 */
	public function column_content( string $column, int $post_id ): void {
		switch ( $column ) {
			case 'pe_status':
				$active = 'active' === get_post_meta( $post_id, '_pe_status', true );
				printf(
					'<span class="pe-badge %s">%s</span>',
					$active ? 'pe-badge--active' : 'pe-badge--paused',
					$active ? esc_html__( 'Active', 'promo-engine' ) : esc_html__( 'Paused', 'promo-engine' )
				);
				break;

			case 'pe_type':
				$type   = (string) get_post_meta( $post_id, '_pe_type', true );
				$labels = self::type_labels();
				echo esc_html( $labels[ $type ] ?? $type );
				break;

			case 'pe_scope':
				$scope  = (string) get_post_meta( $post_id, '_pe_scope_type', true );
				$labels = self::scope_labels();
				echo esc_html( $labels[ $scope ] ?? __( 'Whole catalog', 'promo-engine' ) );
				break;

			case 'pe_priority':
				echo esc_html( (string) (int) get_post_meta( $post_id, '_pe_priority', true ) );
				break;

			case 'pe_stacking':
				echo '0' !== (string) get_post_meta( $post_id, '_pe_stacking', true )
					? esc_html__( 'Combines', 'promo-engine' )
					: esc_html__( 'Exclusive', 'promo-engine' );
				break;

			case 'pe_usage':
				$used  = (int) get_post_meta( $post_id, '_pe_used_count', true );
				$limit = (int) get_post_meta( $post_id, '_pe_usage_limit', true );
				if ( $limit > 0 ) {
					echo esc_html( $used . ' / ' . $limit );
					if ( $used >= $limit ) {
						echo ' <span class="pe-badge pe-badge--paused">' . esc_html__( 'Exhausted', 'promo-engine' ) . '</span>';
					}
				} else {
					/* translators: %d: number of orders that used the promotion. */
					echo esc_html( sprintf( __( '%d / ∞', 'promo-engine' ), $used ) );
				}
				break;

			case 'pe_period':
				$starts = (string) get_post_meta( $post_id, '_pe_starts', true );
				$ends   = (string) get_post_meta( $post_id, '_pe_ends', true );
				if ( '' === $starts && '' === $ends ) {
					esc_html_e( 'Always', 'promo-engine' );
				} else {
					echo esc_html( str_replace( 'T', ' ', $starts ) . ' — ' . str_replace( 'T', ' ', $ends ) );
				}
				break;
		}
	}
}

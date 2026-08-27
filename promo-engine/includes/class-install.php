<?php
/**
 * Activation / upgrade routines.
 *
 * @package Promo_Engine
 */

namespace Promo_Engine;

defined( 'ABSPATH' ) || exit;

/**
 * Creates the events table and the /deals/ page.
 */
final class Install {

	private const VERSION_OPTION = 'promo_engine_version';
	private const PAGE_OPTION    = 'promo_engine_deals_page_id';

	/**
	 * Activation hook.
	 */
	public static function activate(): void {
		Analytics\Events::install_table();
		self::create_deals_page();
		update_option( self::VERSION_OPTION, PROMO_ENGINE_VERSION );
	}

	/**
	 * Re-run install steps after a plugin update (dbDelta is idempotent).
	 */
	public static function maybe_upgrade(): void {
		if ( get_option( self::VERSION_OPTION ) !== PROMO_ENGINE_VERSION ) {
			self::activate();
		}
	}

	/**
	 * Create the Deals page holding the shortcode, if it does not exist yet.
	 */
	private static function create_deals_page(): void {
		$page_id = (int) get_option( self::PAGE_OPTION );
		if ( $page_id && 'page' === get_post_type( $page_id ) && 'trash' !== get_post_status( $page_id ) ) {
			return;
		}

		$existing = get_page_by_path( 'deals' );
		if ( $existing instanceof \WP_Post ) {
			update_option( self::PAGE_OPTION, $existing->ID );
			return;
		}

		$page_id = wp_insert_post(
			array(
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_title'   => __( 'Deals', 'promo-engine' ),
				'post_name'    => 'deals',
				'post_content' => '[promo_engine_deals]',
			)
		);
		if ( $page_id && ! is_wp_error( $page_id ) ) {
			update_option( self::PAGE_OPTION, $page_id );
		}
	}

	/**
	 * The Deals page ID.
	 *
	 * @return int
	 */
	public static function deals_page_id(): int {
		return (int) get_option( self::PAGE_OPTION );
	}
}

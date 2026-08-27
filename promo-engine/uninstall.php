<?php
/**
 * Uninstall: remove the events table, options and promotion posts.
 *
 * @package Promo_Engine
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;

// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- uninstall cleanup.
$wpdb->query( 'DROP TABLE IF EXISTS ' . $wpdb->prefix . 'pe_events' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name from prefix.

delete_option( 'promo_engine_version' );
delete_option( 'promo_engine_deals_page_id' );
delete_transient( 'promo_engine_active_promos' );

$promo_ids = get_posts(
	array(
		'post_type'      => 'pe_promotion',
		// 'any' would skip trashed/auto-draft posts — list statuses explicitly.
		'post_status'    => array_values( get_post_stati() ),
		'posts_per_page' => -1,
		'fields'         => 'ids',
	)
);
foreach ( $promo_ids as $promo_id ) {
	wp_delete_post( (int) $promo_id, true );
}

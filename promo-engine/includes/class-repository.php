<?php
/**
 * Promotions repository: loads promotion posts into engine DTOs, cached.
 *
 * @package Promo_Engine
 */

namespace Promo_Engine;

use Promo_Engine\Engine\Promotion;

defined( 'ABSPATH' ) || exit;

/**
 * Loads and caches active promotions.
 *
 * The full list of active-status promotions is cached in a transient as
 * plain arrays; the time window is evaluated per request (so caching does
 * not delay scheduled starts/ends).
 */
class Repository {

	private const CACHE_KEY = 'promo_engine_active_promos';
	private const CACHE_TTL = 6 * HOUR_IN_SECONDS;

	/**
	 * Request-level cache.
	 *
	 * @var Promotion[]|null
	 */
	private ?array $active = null;

	/**
	 * Hook cache invalidation.
	 */
	public function register(): void {
		add_action( 'save_post_' . Admin\Promotion_CPT::POST_TYPE, array( $this, 'flush' ) );
		add_action( 'deleted_post', array( $this, 'flush_on_delete' ), 10, 2 );
		add_action( 'trashed_post', array( $this, 'flush' ) );
		add_action( 'untrashed_post', array( $this, 'flush' ) );
	}

	/**
	 * All promotions with active status (date window NOT applied here).
	 *
	 * @return Promotion[]
	 */
	public function get_active(): array {
		if ( null !== $this->active ) {
			return $this->active;
		}

		$data = get_transient( self::CACHE_KEY );
		if ( ! is_array( $data ) ) {
			$data = $this->build();
			set_transient( self::CACHE_KEY, $data, self::CACHE_TTL );
		}

		$this->active = array_map( array( Promotion::class, 'from_array' ), $data );
		return $this->active;
	}

	/**
	 * Active promotions currently inside their date window.
	 *
	 * @return Promotion[]
	 */
	public function get_running(): array {
		$now = time();
		return array_values(
			array_filter(
				$this->get_active(),
				static fn( Promotion $p ): bool => $p->is_running( $now ) && $p->has_uses_left()
			)
		);
	}

	/**
	 * Drop the cache.
	 */
	public function flush(): void {
		$this->active = null;
		delete_transient( self::CACHE_KEY );
	}

	/**
	 * Flush when a promotion post is deleted.
	 *
	 * @param int           $post_id Post ID.
	 * @param \WP_Post|null $post    Post object.
	 */
	public function flush_on_delete( int $post_id, $post = null ): void {
		if ( $post && Admin\Promotion_CPT::POST_TYPE === $post->post_type ) {
			$this->flush();
		}
	}

	/**
	 * Query promotion posts and serialize them to arrays for the transient.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function build(): array {
		$query = new \WP_Query(
			array(
				'post_type'              => Admin\Promotion_CPT::POST_TYPE,
				'post_status'            => 'publish',
				'posts_per_page'         => -1,
				'orderby'                => 'ID',
				'order'                  => 'ASC',
				'no_found_rows'          => true,
				'update_post_term_cache' => false,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- bounded set of promotions, cached in a transient.
				'meta_query'             => array(
					array(
						'key'   => '_pe_status',
						'value' => 'active',
					),
				),
			)
		);

		$promos = array();
		foreach ( $query->posts as $post ) {
			$promos[] = $this->post_to_array( $post );
		}
		return $promos;
	}

	/**
	 * Build the DTO source array for one promotion post.
	 *
	 * @param \WP_Post $post Promotion post.
	 * @return array<string, mixed>
	 */
	public function post_to_array( \WP_Post $post ): array {
		$meta = static fn( string $key ) => get_post_meta( $post->ID, $key, true );

		$scope_type = (string) $meta( '_pe_scope_type' );
		if ( ! in_array( $scope_type, array( Promotion::SCOPE_PRODUCTS, Promotion::SCOPE_CATEGORY, Promotion::SCOPE_TAG, Promotion::SCOPE_CATALOG ), true ) ) {
			$scope_type = Promotion::SCOPE_CATALOG;
		}

		$scope_ids = array();
		switch ( $scope_type ) {
			case Promotion::SCOPE_PRODUCTS:
				$scope_ids = array_map( 'absint', (array) $meta( '_pe_products' ) );
				break;
			case Promotion::SCOPE_CATEGORY:
				$scope_ids = $this->expand_terms( array_map( 'absint', (array) $meta( '_pe_categories' ) ), 'product_cat' );
				break;
			case Promotion::SCOPE_TAG:
				$scope_ids = array_map( 'absint', (array) $meta( '_pe_tags' ) );
				break;
		}

		$type = (string) $meta( '_pe_type' );
		if ( ! in_array( $type, array( Promotion::TYPE_PERCENT, Promotion::TYPE_FIXED, Promotion::TYPE_BOGO, Promotion::TYPE_BUNDLE, Promotion::TYPE_CART ), true ) ) {
			$type = Promotion::TYPE_PERCENT;
		}

		$tiers = array();
		foreach ( (array) $meta( '_pe_tiers' ) as $tier ) {
			if ( isset( $tier['min'], $tier['percent'] ) && '' !== $tier['min'] && '' !== $tier['percent'] ) {
				$tiers[] = array(
					'min'     => (float) $tier['min'],
					'percent' => (float) $tier['percent'],
				);
			}
		}

		$cap = $meta( '_pe_cap' );

		return array(
			'id'            => $post->ID,
			'name'          => $post->post_title,
			'type'          => $type,
			'priority'      => (int) $meta( '_pe_priority' ),
			'stacking'      => '0' !== (string) $meta( '_pe_stacking' ),
			'cap_percent'   => ( '' === (string) $cap ) ? null : (float) $cap,
			'scope_type'    => $scope_type,
			'scope_ids'     => array_values( array_filter( $scope_ids ) ),
			'amount'        => (float) $meta( '_pe_amount' ),
			'bogo_buy_qty'  => max( 1, (int) $meta( '_pe_bogo_buy' ) ),
			'bogo_get_qty'  => max( 1, (int) $meta( '_pe_bogo_get' ) ),
			'bundle_qty'    => max( 1, (int) $meta( '_pe_bundle_qty' ) ),
			'bundle_price'  => (float) $meta( '_pe_bundle_price' ),
			'tiers'         => $tiers,
			'starts_at'     => $this->to_timestamp( (string) $meta( '_pe_starts' ) ),
			'ends_at'       => $this->to_timestamp( (string) $meta( '_pe_ends' ) ),
			'popup_enabled' => '1' === (string) $meta( '_pe_popup' ),
			'usage_limit'   => max( 0, (int) $meta( '_pe_usage_limit' ) ),
			'used_count'    => max( 0, (int) $meta( '_pe_used_count' ) ),
		);
	}

	/**
	 * Include descendants of the selected terms so a promotion on a parent
	 * category also covers products assigned only to child categories.
	 *
	 * @param int[]  $term_ids Selected term IDs.
	 * @param string $taxonomy Taxonomy name.
	 * @return int[]
	 */
	private function expand_terms( array $term_ids, string $taxonomy ): array {
		$all = $term_ids;
		foreach ( $term_ids as $term_id ) {
			$children = get_term_children( $term_id, $taxonomy );
			if ( ! is_wp_error( $children ) ) {
				$all = array_merge( $all, array_map( 'absint', $children ) );
			}
		}
		return array_values( array_unique( $all ) );
	}

	/**
	 * Convert a site-timezone datetime-local string to a UTC timestamp.
	 *
	 * @param string $value e.g. "2026-08-27T15:00".
	 * @return int|null
	 */
	private function to_timestamp( string $value ): ?int {
		if ( '' === $value ) {
			return null;
		}
		try {
			return ( new \DateTimeImmutable( $value, wp_timezone() ) )->getTimestamp();
		} catch ( \Exception $e ) {
			return null;
		}
	}
}

<?php
/**
 * Promotion edit screen meta boxes.
 *
 * @package Promo_Engine
 */

namespace Promo_Engine\Admin;

use Promo_Engine\Engine\Promotion;

defined( 'ABSPATH' ) || exit;

/**
 * Renders and saves the promotion settings meta box.
 */
class Meta_Boxes {

	private const NONCE_ACTION = 'pe_save_promotion';
	private const NONCE_FIELD  = 'pe_meta_nonce';

	/**
	 * Hook everything.
	 */
	public function register(): void {
		add_action( 'add_meta_boxes_' . Promotion_CPT::POST_TYPE, array( $this, 'add_meta_boxes' ) );
		add_action( 'save_post_' . Promotion_CPT::POST_TYPE, array( $this, 'save' ), 10, 2 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	/**
	 * Enqueue admin assets on our screens.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public function enqueue( string $hook ): void {
		$screen = get_current_screen();
		if ( ! $screen || Promotion_CPT::POST_TYPE !== $screen->post_type ) {
			return;
		}

		wp_enqueue_style( 'promo-engine-admin', PROMO_ENGINE_URL . 'assets/css/admin.css', array(), PROMO_ENGINE_VERSION );

		if ( in_array( $hook, array( 'post.php', 'post-new.php' ), true ) ) {
			// WooCommerce's product search select.
			wp_enqueue_script( 'wc-enhanced-select' );
			wp_enqueue_style( 'woocommerce_admin_styles' );
			wp_enqueue_script( 'promo-engine-admin', PROMO_ENGINE_URL . 'assets/js/admin.js', array( 'jquery' ), PROMO_ENGINE_VERSION, true );
		}
	}

	/**
	 * Register the meta box.
	 */
	public function add_meta_boxes(): void {
		add_meta_box(
			'pe-promotion-settings',
			__( 'Promotion settings', 'promo-engine' ),
			array( $this, 'render' ),
			Promotion_CPT::POST_TYPE,
			'normal',
			'high'
		);
	}

	/**
	 * Render the settings form.
	 *
	 * @param \WP_Post $post Promotion post.
	 */
	public function render( \WP_Post $post ): void {
		$meta = static fn( string $key, $default = '' ) => (string) ( get_post_meta( $post->ID, $key, true ) ?: $default );

		$status     = $meta( '_pe_status', 'active' );
		$type       = $meta( '_pe_type', Promotion::TYPE_PERCENT );
		$scope_type = $meta( '_pe_scope_type', Promotion::SCOPE_CATEGORY );
		$stacking   = $meta( '_pe_stacking', '1' );
		$products   = array_map( 'absint', (array) get_post_meta( $post->ID, '_pe_products', true ) );
		$categories = array_map( 'absint', (array) get_post_meta( $post->ID, '_pe_categories', true ) );
		$tags       = array_map( 'absint', (array) get_post_meta( $post->ID, '_pe_tags', true ) );
		$tiers      = (array) get_post_meta( $post->ID, '_pe_tiers', true );

		wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD );
		?>
		<table class="form-table pe-form-table">
			<tr>
				<th><label for="pe_status"><?php esc_html_e( 'Status', 'promo-engine' ); ?></label></th>
				<td>
					<select name="_pe_status" id="pe_status">
						<option value="active" <?php selected( $status, 'active' ); ?>><?php esc_html_e( 'Active', 'promo-engine' ); ?></option>
						<option value="paused" <?php selected( $status, 'paused' ); ?>><?php esc_html_e( 'Paused', 'promo-engine' ); ?></option>
					</select>
				</td>
			</tr>
			<tr>
				<th><label for="pe_priority"><?php esc_html_e( 'Priority', 'promo-engine' ); ?></label></th>
				<td>
					<input type="number" name="_pe_priority" id="pe_priority" value="<?php echo esc_attr( $meta( '_pe_priority', '0' ) ); ?>" step="1" class="small-text" />
					<p class="description"><?php esc_html_e( 'Higher number wins when promotions compete. Ties are resolved by lower ID.', 'promo-engine' ); ?></p>
				</td>
			</tr>
			<tr>
				<th><label for="pe_stacking"><?php esc_html_e( 'Combination', 'promo-engine' ); ?></label></th>
				<td>
					<select name="_pe_stacking" id="pe_stacking">
						<option value="1" <?php selected( $stacking, '1' ); ?>><?php esc_html_e( 'Combines with other promotions', 'promo-engine' ); ?></option>
						<option value="0" <?php selected( $stacking, '0' ); ?>><?php esc_html_e( 'Does not combine (exclusive)', 'promo-engine' ); ?></option>
					</select>
				</td>
			</tr>
			<tr>
				<th><label for="pe_cap"><?php esc_html_e( 'Max discount cap, %', 'promo-engine' ); ?></label></th>
				<td>
					<input type="number" name="_pe_cap" id="pe_cap" value="<?php echo esc_attr( $meta( '_pe_cap' ) ); ?>" step="0.01" min="0" max="100" class="small-text" />
					<p class="description"><?php esc_html_e( 'Total discount on an item is clipped to this percent. Leave empty for no cap.', 'promo-engine' ); ?></p>
				</td>
			</tr>
			<tr>
				<th><label for="pe_type"><?php esc_html_e( 'Discount type', 'promo-engine' ); ?></label></th>
				<td>
					<select name="_pe_type" id="pe_type">
						<?php foreach ( Promotion_CPT::type_labels() as $value => $label ) : ?>
							<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $type, $value ); ?>><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
				</td>
			</tr>

			<tr class="pe-type-row" data-pe-types="percent fixed">
				<th><label for="pe_amount"><?php esc_html_e( 'Amount', 'promo-engine' ); ?></label></th>
				<td>
					<input type="number" name="_pe_amount" id="pe_amount" value="<?php echo esc_attr( $meta( '_pe_amount' ) ); ?>" step="0.01" min="0" class="small-text" />
					<p class="description"><?php esc_html_e( 'Percent for percentage type; amount per unit for fixed type.', 'promo-engine' ); ?></p>
				</td>
			</tr>

			<tr class="pe-type-row" data-pe-types="bogo">
				<th><?php esc_html_e( 'Buy X get Y', 'promo-engine' ); ?></th>
				<td>
					<label>
						<?php esc_html_e( 'Buy', 'promo-engine' ); ?>
						<input type="number" name="_pe_bogo_buy" value="<?php echo esc_attr( $meta( '_pe_bogo_buy', '2' ) ); ?>" step="1" min="1" class="small-text" />
					</label>
					<label>
						<?php esc_html_e( 'get free (cheapest)', 'promo-engine' ); ?>
						<input type="number" name="_pe_bogo_get" value="<?php echo esc_attr( $meta( '_pe_bogo_get', '1' ) ); ?>" step="1" min="1" class="small-text" />
					</label>
				</td>
			</tr>

			<tr class="pe-type-row" data-pe-types="bundle">
				<th><?php esc_html_e( 'Bundle', 'promo-engine' ); ?></th>
				<td>
					<label>
						<?php esc_html_e( 'Items in bundle', 'promo-engine' ); ?>
						<input type="number" name="_pe_bundle_qty" value="<?php echo esc_attr( $meta( '_pe_bundle_qty', '2' ) ); ?>" step="1" min="2" class="small-text" />
					</label>
					<label>
						<?php esc_html_e( 'Bundle price', 'promo-engine' ); ?>
						<input type="number" name="_pe_bundle_price" value="<?php echo esc_attr( $meta( '_pe_bundle_price' ) ); ?>" step="0.01" min="0" class="small-text" />
					</label>
					<p class="description"><?php esc_html_e( 'Applied only when it lowers the total for the bundled items.', 'promo-engine' ); ?></p>
				</td>
			</tr>

			<tr class="pe-type-row" data-pe-types="cart_threshold">
				<th><?php esc_html_e( 'Subtotal tiers', 'promo-engine' ); ?></th>
				<td>
					<table class="pe-tiers" id="pe-tiers">
						<thead>
							<tr>
								<th><?php esc_html_e( 'Subtotal at least', 'promo-engine' ); ?></th>
								<th><?php esc_html_e( 'Discount, %', 'promo-engine' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php
							$tiers = array_values( array_filter( $tiers, 'is_array' ) );
							$rows  = max( count( $tiers ) + 1, 3 );
							for ( $i = 0; $i < $rows; $i++ ) :
								$min = isset( $tiers[ $i ]['min'] ) ? (string) $tiers[ $i ]['min'] : '';
								$pct = isset( $tiers[ $i ]['percent'] ) ? (string) $tiers[ $i ]['percent'] : '';
								?>
								<tr>
									<td><input type="number" name="_pe_tiers[<?php echo (int) $i; ?>][min]" value="<?php echo esc_attr( $min ); ?>" step="0.01" min="0" /></td>
									<td><input type="number" name="_pe_tiers[<?php echo (int) $i; ?>][percent]" value="<?php echo esc_attr( $pct ); ?>" step="0.01" min="0" max="100" /></td>
								</tr>
							<?php endfor; ?>
						</tbody>
					</table>
					<button type="button" class="button" id="pe-add-tier"><?php esc_html_e( 'Add tier', 'promo-engine' ); ?></button>
					<p class="description"><?php esc_html_e( 'Only the highest reached tier applies.', 'promo-engine' ); ?></p>
				</td>
			</tr>

			<tr class="pe-scope-toggle-row">
				<th><label for="pe_scope_type"><?php esc_html_e( 'Applies to', 'promo-engine' ); ?></label></th>
				<td>
					<select name="_pe_scope_type" id="pe_scope_type">
						<?php foreach ( Promotion_CPT::scope_labels() as $value => $label ) : ?>
							<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $scope_type, $value ); ?>><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
				</td>
			</tr>

			<tr class="pe-scope-row" data-pe-scopes="products">
				<th><label for="pe_products"><?php esc_html_e( 'Products', 'promo-engine' ); ?></label></th>
				<td>
					<select class="wc-product-search" id="pe_products" multiple="multiple" style="width: 50%;" name="_pe_products[]"
						data-placeholder="<?php esc_attr_e( 'Search for products…', 'promo-engine' ); ?>"
						data-action="woocommerce_json_search_products_and_variations">
						<?php
						foreach ( $products as $product_id ) {
							$product = wc_get_product( $product_id );
							if ( $product ) {
								printf(
									'<option value="%s" selected="selected">%s</option>',
									esc_attr( (string) $product_id ),
									esc_html( wp_strip_all_tags( $product->get_formatted_name() ) )
								);
							}
						}
						?>
					</select>
				</td>
			</tr>

			<tr class="pe-scope-row" data-pe-scopes="category">
				<th><?php esc_html_e( 'Categories', 'promo-engine' ); ?></th>
				<td class="pe-term-checklist"><?php $this->term_checklist( 'product_cat', '_pe_categories', $categories ); ?></td>
			</tr>

			<tr class="pe-scope-row" data-pe-scopes="tag">
				<th><?php esc_html_e( 'Tags', 'promo-engine' ); ?></th>
				<td class="pe-term-checklist"><?php $this->term_checklist( 'product_tag', '_pe_tags', $tags ); ?></td>
			</tr>

			<tr>
				<th><label for="pe_starts"><?php esc_html_e( 'Starts', 'promo-engine' ); ?></label></th>
				<td>
					<input type="datetime-local" name="_pe_starts" id="pe_starts" value="<?php echo esc_attr( $meta( '_pe_starts' ) ); ?>" />
					<p class="description"><?php esc_html_e( 'Site timezone. Empty = no start limit.', 'promo-engine' ); ?></p>
				</td>
			</tr>
			<tr>
				<th><label for="pe_ends"><?php esc_html_e( 'Ends', 'promo-engine' ); ?></label></th>
				<td>
					<input type="datetime-local" name="_pe_ends" id="pe_ends" value="<?php echo esc_attr( $meta( '_pe_ends' ) ); ?>" />
					<p class="description"><?php esc_html_e( 'Site timezone. Empty = no end limit. The popup countdown uses this.', 'promo-engine' ); ?></p>
				</td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'Promo popup', 'promo-engine' ); ?></th>
				<td>
					<label>
						<input type="checkbox" name="_pe_popup" value="1" <?php checked( $meta( '_pe_popup' ), '1' ); ?> />
						<?php esc_html_e( 'Show a popup with a countdown for this promotion (once per session, not on checkout).', 'promo-engine' ); ?>
					</label>
				</td>
			</tr>
		</table>
		<?php
	}

	/**
	 * Render a scrollable checkbox list of taxonomy terms.
	 *
	 * @param string $taxonomy Taxonomy.
	 * @param string $name     Field name.
	 * @param int[]  $selected Selected term IDs.
	 */
	private function term_checklist( string $taxonomy, string $name, array $selected ): void {
		$terms = get_terms(
			array(
				'taxonomy'   => $taxonomy,
				'hide_empty' => false,
			)
		);
		if ( is_wp_error( $terms ) || ! $terms ) {
			esc_html_e( 'No terms found.', 'promo-engine' );
			return;
		}
		echo '<div class="pe-checklist">';
		foreach ( $terms as $term ) {
			printf(
				'<label><input type="checkbox" name="%s[]" value="%d" %s /> %s <span class="pe-muted">(%d)</span></label>',
				esc_attr( $name ),
				(int) $term->term_id,
				checked( in_array( (int) $term->term_id, $selected, true ), true, false ),
				esc_html( $term->name ),
				(int) $term->count
			);
		}
		echo '</div>';
	}

	/**
	 * Save the meta box values.
	 *
	 * @param int      $post_id Post ID.
	 * @param \WP_Post $post    Post object.
	 */
	public function save( int $post_id, \WP_Post $post ): void {
		if ( ! isset( $_POST[ self::NONCE_FIELD ] )
			|| ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST[ self::NONCE_FIELD ] ) ), self::NONCE_ACTION ) ) {
			return;
		}
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $post_id ) || wp_is_post_revision( $post_id ) ) {
			return;
		}

		$in = static fn( string $key ) => isset( $_POST[ $key ] ) ? wp_unslash( $_POST[ $key ] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized per-field below.

		$status = ( 'paused' === $in( '_pe_status' ) ) ? 'paused' : 'active';
		update_post_meta( $post_id, '_pe_status', $status );

		update_post_meta( $post_id, '_pe_priority', (int) $in( '_pe_priority' ) );
		update_post_meta( $post_id, '_pe_stacking', ( '0' === $in( '_pe_stacking' ) ) ? '0' : '1' );

		$cap = trim( (string) $in( '_pe_cap' ) );
		update_post_meta( $post_id, '_pe_cap', ( '' === $cap ) ? '' : (string) min( 100, max( 0, (float) $cap ) ) );

		$type = (string) $in( '_pe_type' );
		if ( ! array_key_exists( $type, Promotion_CPT::type_labels() ) ) {
			$type = Promotion::TYPE_PERCENT;
		}
		update_post_meta( $post_id, '_pe_type', $type );

		update_post_meta( $post_id, '_pe_amount', (string) max( 0, (float) $in( '_pe_amount' ) ) );
		update_post_meta( $post_id, '_pe_bogo_buy', max( 1, (int) $in( '_pe_bogo_buy' ) ) );
		update_post_meta( $post_id, '_pe_bogo_get', max( 1, (int) $in( '_pe_bogo_get' ) ) );
		update_post_meta( $post_id, '_pe_bundle_qty', max( 2, (int) $in( '_pe_bundle_qty' ) ) );
		update_post_meta( $post_id, '_pe_bundle_price', (string) max( 0, (float) $in( '_pe_bundle_price' ) ) );

		$scope = (string) $in( '_pe_scope_type' );
		if ( ! array_key_exists( $scope, Promotion_CPT::scope_labels() ) ) {
			$scope = Promotion::SCOPE_CATALOG;
		}
		update_post_meta( $post_id, '_pe_scope_type', $scope );

		update_post_meta( $post_id, '_pe_products', array_values( array_filter( array_map( 'absint', (array) $in( '_pe_products' ) ) ) ) );
		update_post_meta( $post_id, '_pe_categories', array_values( array_filter( array_map( 'absint', (array) $in( '_pe_categories' ) ) ) ) );
		update_post_meta( $post_id, '_pe_tags', array_values( array_filter( array_map( 'absint', (array) $in( '_pe_tags' ) ) ) ) );

		$tiers = array();
		foreach ( (array) $in( '_pe_tiers' ) as $tier ) {
			if ( is_array( $tier ) && '' !== trim( (string) ( $tier['min'] ?? '' ) ) && '' !== trim( (string) ( $tier['percent'] ?? '' ) ) ) {
				$tiers[] = array(
					'min'     => max( 0, (float) $tier['min'] ),
					'percent' => min( 100, max( 0, (float) $tier['percent'] ) ),
				);
			}
		}
		usort( $tiers, static fn( array $a, array $b ): int => $a['min'] <=> $b['min'] );
		update_post_meta( $post_id, '_pe_tiers', $tiers );

		update_post_meta( $post_id, '_pe_starts', $this->sanitize_datetime( (string) $in( '_pe_starts' ) ) );
		update_post_meta( $post_id, '_pe_ends', $this->sanitize_datetime( (string) $in( '_pe_ends' ) ) );

		update_post_meta( $post_id, '_pe_popup', ( '1' === $in( '_pe_popup' ) ) ? '1' : '0' );
	}

	/**
	 * Validate a datetime-local value ("Y-m-d\TH:i", seconds tolerated).
	 *
	 * @param string $value Raw value.
	 * @return string Sanitized value or empty string.
	 */
	private function sanitize_datetime( string $value ): string {
		$value = trim( $value );
		if ( '' === $value ) {
			return '';
		}
		return preg_match( '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(:\d{2})?$/', $value ) ? $value : '';
	}
}

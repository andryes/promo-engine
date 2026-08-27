<?php
/**
 * Analytics admin screen.
 *
 * @package Promo_Engine
 */

namespace Promo_Engine\Admin;

use Promo_Engine\Analytics\Events;

defined( 'ABSPATH' ) || exit;

/**
 * Per-promotion analytics: impressions, clicks, CTR, add-to-carts, orders,
 * revenue, discount, conversion, top products and a daily SVG chart.
 *
 * All aggregations are single SQL GROUP BY queries on the indexed events
 * table; nothing is aggregated in PHP loops.
 */
class Analytics_Page {

	private const SLUG = 'pe-analytics';

	/**
	 * Hook everything.
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
	}

	/**
	 * Register the submenu page.
	 */
	public function add_menu(): void {
		add_submenu_page(
			'edit.php?post_type=' . Promotion_CPT::POST_TYPE,
			__( 'Promo analytics', 'promo-engine' ),
			__( 'Analytics', 'promo-engine' ),
			'manage_woocommerce',
			self::SLUG,
			array( $this, 'render' )
		);
	}

	/**
	 * Current filter values from the query string.
	 *
	 * @return array{from: string, to: string, promo_id: int}
	 */
	private function filters(): array {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only GET filters.
		$from     = isset( $_GET['pe_from'] ) ? sanitize_text_field( wp_unslash( $_GET['pe_from'] ) ) : '';
		$to       = isset( $_GET['pe_to'] ) ? sanitize_text_field( wp_unslash( $_GET['pe_to'] ) ) : '';
		$promo_id = isset( $_GET['promo_id'] ) ? absint( wp_unslash( $_GET['promo_id'] ) ) : 0;
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$valid = static fn( string $date ): bool => (bool) preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date );
		if ( ! $valid( $from ) ) {
			$from = wp_date( 'Y-m-d', strtotime( '-29 days' ) );
		}
		if ( ! $valid( $to ) ) {
			$to = wp_date( 'Y-m-d' );
		}

		return array(
			'from'     => $from,
			'to'       => $to,
			'promo_id' => $promo_id,
		);
	}

	/**
	 * Aggregated stats per promotion for a date range.
	 *
	 * @param string $from     From date (Y-m-d, site tz).
	 * @param string $to       To date (Y-m-d).
	 * @param int    $promo_id Optional single promotion.
	 * @return array<int, object>
	 */
	private function stats( string $from, string $to, int $promo_id = 0 ): array {
		global $wpdb;
		$table = Events::table();

		$sql  = "SELECT promo_id,
				SUM(event_type = %s) AS views,
				SUM(event_type = %s) AS clicks,
				SUM(event_type = %s) AS atc,
				SUM(event_type = %s) AS orders,
				SUM(CASE WHEN event_type = %s THEN revenue ELSE 0 END) AS revenue,
				SUM(CASE WHEN event_type = %s THEN discount ELSE 0 END) AS discount
			FROM {$table}
			WHERE created_at BETWEEN %s AND %s";
		$args = array(
			Events::TYPE_POPUP_VIEW,
			Events::TYPE_POPUP_CLICK,
			Events::TYPE_ADD_TO_CART,
			Events::TYPE_ORDER,
			Events::TYPE_ORDER,
			Events::TYPE_ORDER,
			$from . ' 00:00:00',
			$to . ' 23:59:59',
		);
		if ( $promo_id ) {
			$sql   .= ' AND promo_id = %d';
			$args[] = $promo_id;
		}
		$sql .= ' GROUP BY promo_id ORDER BY revenue DESC, promo_id ASC';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- custom table, fully prepared above.
		return (array) $wpdb->get_results( $wpdb->prepare( $sql, $args ) );
	}

	/**
	 * Daily time series for one promotion (or all).
	 *
	 * @param string $from     From date.
	 * @param string $to       To date.
	 * @param int    $promo_id Optional promotion.
	 * @return array<string, object> date => row.
	 */
	private function daily( string $from, string $to, int $promo_id = 0 ): array {
		global $wpdb;
		$table = Events::table();

		$sql  = "SELECT DATE(created_at) AS day,
				SUM(event_type = %s) AS views,
				SUM(event_type = %s) AS atc,
				SUM(event_type = %s) AS orders
			FROM {$table}
			WHERE created_at BETWEEN %s AND %s";
		$args = array(
			Events::TYPE_POPUP_VIEW,
			Events::TYPE_ADD_TO_CART,
			Events::TYPE_ORDER,
			$from . ' 00:00:00',
			$to . ' 23:59:59',
		);
		if ( $promo_id ) {
			$sql   .= ' AND promo_id = %d';
			$args[] = $promo_id;
		}
		$sql .= ' GROUP BY day ORDER BY day ASC';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- custom table, fully prepared above.
		$rows = (array) $wpdb->get_results( $wpdb->prepare( $sql, $args ) );

		$by_day = array();
		foreach ( $rows as $row ) {
			$by_day[ $row->day ] = $row;
		}
		return $by_day;
	}

	/**
	 * Top products by add-to-cart events for one promotion.
	 *
	 * @param string $from     From date.
	 * @param string $to       To date.
	 * @param int    $promo_id Promotion ID.
	 * @return array<int, object>
	 */
	private function top_products( string $from, string $to, int $promo_id ): array {
		global $wpdb;
		$table = Events::table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- custom table, fully prepared.
		return (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT product_id, COUNT(*) AS atc
				FROM {$table}
				WHERE event_type = %s AND promo_id = %d AND product_id > 0 AND created_at BETWEEN %s AND %s
				GROUP BY product_id ORDER BY atc DESC LIMIT 10",
				Events::TYPE_ADD_TO_CART,
				$promo_id,
				$from . ' 00:00:00',
				$to . ' 23:59:59'
			)
		);
	}

	/**
	 * Render the page.
	 */
	public function render(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You are not allowed to view this page.', 'promo-engine' ) );
		}

		$filters = $this->filters();
		$promos  = get_posts(
			array(
				'post_type'      => Promotion_CPT::POST_TYPE,
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'orderby'        => 'ID',
				'order'          => 'ASC',
			)
		);
		$names   = array();
		foreach ( $promos as $promo_post ) {
			$names[ $promo_post->ID ] = $promo_post->post_title;
		}

		$stats = $this->stats( $filters['from'], $filters['to'], $filters['promo_id'] );
		?>
		<div class="wrap pe-analytics">
			<h1><?php esc_html_e( 'Promo analytics', 'promo-engine' ); ?></h1>

			<form method="get" class="pe-analytics__filters">
				<input type="hidden" name="post_type" value="<?php echo esc_attr( Promotion_CPT::POST_TYPE ); ?>" />
				<input type="hidden" name="page" value="<?php echo esc_attr( self::SLUG ); ?>" />
				<label>
					<?php esc_html_e( 'Promotion', 'promo-engine' ); ?>
					<select name="promo_id">
						<option value="0"><?php esc_html_e( 'All promotions', 'promo-engine' ); ?></option>
						<?php foreach ( $names as $id => $name ) : ?>
							<option value="<?php echo (int) $id; ?>" <?php selected( $filters['promo_id'], $id ); ?>><?php echo esc_html( $name ); ?></option>
						<?php endforeach; ?>
					</select>
				</label>
				<label>
					<?php esc_html_e( 'From', 'promo-engine' ); ?>
					<input type="date" name="pe_from" value="<?php echo esc_attr( $filters['from'] ); ?>" />
				</label>
				<label>
					<?php esc_html_e( 'To', 'promo-engine' ); ?>
					<input type="date" name="pe_to" value="<?php echo esc_attr( $filters['to'] ); ?>" />
				</label>
				<button class="button"><?php esc_html_e( 'Filter', 'promo-engine' ); ?></button>
			</form>

			<?php $this->render_chart( $filters ); ?>

			<table class="widefat striped pe-analytics__table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Promotion', 'promo-engine' ); ?></th>
						<th><?php esc_html_e( 'Popup views', 'promo-engine' ); ?></th>
						<th><?php esc_html_e( 'Popup clicks', 'promo-engine' ); ?></th>
						<th><?php esc_html_e( 'CTR', 'promo-engine' ); ?></th>
						<th><?php esc_html_e( 'Add to cart', 'promo-engine' ); ?></th>
						<th><?php esc_html_e( 'Orders', 'promo-engine' ); ?></th>
						<th><?php esc_html_e( 'Revenue', 'promo-engine' ); ?></th>
						<th><?php esc_html_e( 'Discount given', 'promo-engine' ); ?></th>
						<th><?php esc_html_e( 'Conversion', 'promo-engine' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( ! $stats ) : ?>
						<tr><td colspan="9"><?php esc_html_e( 'No events recorded for this period.', 'promo-engine' ); ?></td></tr>
					<?php endif; ?>
					<?php
					foreach ( $stats as $row ) :
						$views  = (int) $row->views;
						$clicks = (int) $row->clicks;
						$atc    = (int) $row->atc;
						$orders = (int) $row->orders;
						$link   = add_query_arg(
							array(
								'post_type' => Promotion_CPT::POST_TYPE,
								'page'      => self::SLUG,
								'promo_id'  => (int) $row->promo_id,
								'pe_from'   => $filters['from'],
								'pe_to'     => $filters['to'],
							),
							admin_url( 'edit.php' )
						);
						?>
						<tr>
							<td><a href="<?php echo esc_url( $link ); ?>"><?php echo esc_html( $names[ $row->promo_id ] ?? ( '#' . $row->promo_id ) ); ?></a></td>
							<td><?php echo (int) $views; ?></td>
							<td><?php echo (int) $clicks; ?></td>
							<td><?php echo esc_html( $views ? number_format_i18n( $clicks / $views * 100, 1 ) . '%' : '—' ); ?></td>
							<td><?php echo (int) $atc; ?></td>
							<td><?php echo (int) $orders; ?></td>
							<td><?php echo wp_kses_post( wc_price( (float) $row->revenue ) ); ?></td>
							<td><?php echo wp_kses_post( wc_price( (float) $row->discount ) ); ?></td>
							<td><?php echo esc_html( $atc ? number_format_i18n( $orders / $atc * 100, 1 ) . '%' : '—' ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
			<p class="description">
				<?php esc_html_e( 'CTR = popup clicks / popup views. Conversion = orders / add-to-cart events.', 'promo-engine' ); ?>
			</p>

			<?php if ( $filters['promo_id'] ) : ?>
				<h2><?php esc_html_e( 'Top products (by add-to-cart)', 'promo-engine' ); ?></h2>
				<table class="widefat striped pe-analytics__top">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Product', 'promo-engine' ); ?></th>
							<th><?php esc_html_e( 'Added to cart', 'promo-engine' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php
						$top = $this->top_products( $filters['from'], $filters['to'], $filters['promo_id'] );
						if ( ! $top ) {
							echo '<tr><td colspan="2">' . esc_html__( 'No add-to-cart events yet.', 'promo-engine' ) . '</td></tr>';
						}
						foreach ( $top as $row ) :
							$title = get_the_title( (int) $row->product_id );
							?>
							<tr>
								<td>
									<a href="<?php echo esc_url( (string) get_edit_post_link( (int) $row->product_id ) ); ?>">
										<?php echo esc_html( $title ? $title : ( '#' . $row->product_id ) ); ?>
									</a>
								</td>
								<td><?php echo (int) $row->atc; ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Render the daily SVG chart (no external libraries).
	 *
	 * @param array{from: string, to: string, promo_id: int} $filters Filters.
	 */
	private function render_chart( array $filters ): void {
		$by_day = $this->daily( $filters['from'], $filters['to'], $filters['promo_id'] );

		// Build a continuous day axis.
		$days   = array();
		$cursor = new \DateTimeImmutable( $filters['from'] );
		$end    = new \DateTimeImmutable( $filters['to'] );
		$guard  = 0;
		while ( $cursor <= $end && $guard < 400 ) {
			$days[] = $cursor->format( 'Y-m-d' );
			$cursor = $cursor->modify( '+1 day' );
			++$guard;
		}
		if ( count( $days ) < 2 ) {
			return;
		}

		$series = array(
			'views'  => array(
				'label' => __( 'Popup views', 'promo-engine' ),
				'color' => '#2271b1',
			),
			'atc'    => array(
				'label' => __( 'Add to cart', 'promo-engine' ),
				'color' => '#00a32a',
			),
			'orders' => array(
				'label' => __( 'Orders', 'promo-engine' ),
				'color' => '#d63638',
			),
		);

		$max = 1;
		foreach ( $days as $day ) {
			foreach ( array_keys( $series ) as $key ) {
				$max = max( $max, (int) ( $by_day[ $day ]->$key ?? 0 ) );
			}
		}

		$width   = 960;
		$height  = 260;
		$pad_l   = 44;
		$pad_r   = 12;
		$pad_t   = 12;
		$pad_b   = 32;
		$plot_w  = $width - $pad_l - $pad_r;
		$plot_h  = $height - $pad_t - $pad_b;
		$step    = $plot_w / ( count( $days ) - 1 );
		$x_of    = static fn( int $i ): float => $pad_l + $i * $step;
		$y_of    = static fn( float $v ) => $pad_t + $plot_h - ( $v / $max * $plot_h );
		$every   = max( 1, (int) ceil( count( $days ) / 10 ) );
		?>
		<div class="pe-chart-card">
			<svg viewBox="0 0 <?php echo (int) $width; ?> <?php echo (int) $height; ?>" role="img"
				aria-label="<?php esc_attr_e( 'Daily promo events', 'promo-engine' ); ?>" class="pe-chart">
				<?php for ( $g = 0; $g <= 4; $g++ ) : ?>
					<?php $gy = $pad_t + $plot_h - ( $g / 4 * $plot_h ); ?>
					<line x1="<?php echo (int) $pad_l; ?>" y1="<?php echo esc_attr( (string) round( $gy, 1 ) ); ?>"
						x2="<?php echo (int) ( $width - $pad_r ); ?>" y2="<?php echo esc_attr( (string) round( $gy, 1 ) ); ?>"
						stroke="#dcdcde" stroke-width="1" />
					<text x="<?php echo (int) ( $pad_l - 6 ); ?>" y="<?php echo esc_attr( (string) round( $gy + 4, 1 ) ); ?>"
						text-anchor="end" font-size="11" fill="#646970"><?php echo (int) round( $max * $g / 4 ); ?></text>
				<?php endfor; ?>

				<?php foreach ( $days as $i => $day ) : ?>
					<?php if ( 0 === $i % $every || count( $days ) - 1 === $i ) : ?>
						<text x="<?php echo esc_attr( (string) round( $x_of( $i ), 1 ) ); ?>" y="<?php echo (int) ( $height - 8 ); ?>"
							text-anchor="middle" font-size="11" fill="#646970"><?php echo esc_html( substr( $day, 5 ) ); ?></text>
					<?php endif; ?>
				<?php endforeach; ?>

				<?php foreach ( $series as $key => $conf ) : ?>
					<?php
					$points = array();
					foreach ( $days as $i => $day ) {
						$value    = (float) ( $by_day[ $day ]->$key ?? 0 );
						$points[] = round( $x_of( $i ), 1 ) . ',' . round( $y_of( $value ), 1 );
					}
					?>
					<polyline points="<?php echo esc_attr( implode( ' ', $points ) ); ?>"
						fill="none" stroke="<?php echo esc_attr( $conf['color'] ); ?>" stroke-width="2" />
				<?php endforeach; ?>
			</svg>
			<p class="pe-chart__legend">
				<?php foreach ( $series as $conf ) : ?>
					<span class="pe-chart__legend-item">
						<span class="pe-chart__swatch" style="background: <?php echo esc_attr( $conf['color'] ); ?>"></span>
						<?php echo esc_html( $conf['label'] ); ?>
					</span>
				<?php endforeach; ?>
			</p>
		</div>
		<?php
	}
}

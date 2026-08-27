<?php
/**
 * Plugin Name:          Promo Engine
 * Plugin URI:           https://github.com/andryes/promo-engine
 * Description:          Promotions engine for WooCommerce: percentage / fixed / BOGO / bundle / cart-threshold discounts with stacking rules, a deals page, promo popup, mini-cart savings and built-in analytics.
 * Version:              1.1.0
 * Requires at least:    6.5
 * Requires PHP:         8.0
 * Author:               andryes
 * Author URI:           https://github.com/andryes
 * License:              GPL-2.0-or-later
 * License URI:          https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:          promo-engine
 * Domain Path:          /languages
 * Requires Plugins:     woocommerce
 * WC requires at least: 8.0
 * WC tested up to:      11.0
 *
 * @package Promo_Engine
 */

defined( 'ABSPATH' ) || exit;

define( 'PROMO_ENGINE_VERSION', '1.1.0' );
define( 'PROMO_ENGINE_FILE', __FILE__ );
define( 'PROMO_ENGINE_DIR', plugin_dir_path( __FILE__ ) );
define( 'PROMO_ENGINE_URL', plugin_dir_url( __FILE__ ) );

require PROMO_ENGINE_DIR . 'includes/class-autoloader.php';
Promo_Engine\Autoloader::register();

register_activation_hook( __FILE__, array( 'Promo_Engine\Install', 'activate' ) );

add_action(
	'before_woocommerce_init',
	static function () {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
		}
	}
);

/**
 * Return the main plugin instance.
 *
 * @return Promo_Engine\Plugin
 */
function promo_engine(): Promo_Engine\Plugin {
	return Promo_Engine\Plugin::instance();
}

add_action(
	'plugins_loaded',
	static function () {
		if ( ! class_exists( 'WooCommerce' ) ) {
			add_action(
				'admin_notices',
				static function () {
					echo '<div class="notice notice-error"><p>';
					esc_html_e( 'Promo Engine requires WooCommerce to be installed and active.', 'promo-engine' );
					echo '</p></div>';
				}
			);
			return;
		}
		promo_engine()->init();
	},
	20
);

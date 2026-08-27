<?php
/**
 * Class autoloader.
 *
 * @package Promo_Engine
 */

namespace Promo_Engine;

defined( 'ABSPATH' ) || exit;

/**
 * Maps Promo_Engine\Sub\Class_Name to includes/sub/class-class-name.php.
 */
final class Autoloader {

	/**
	 * Register the autoloader.
	 */
	public static function register(): void {
		spl_autoload_register( array( __CLASS__, 'autoload' ) );
	}

	/**
	 * Load a plugin class file.
	 *
	 * @param string $class_name Fully qualified class name.
	 */
	public static function autoload( string $class_name ): void {
		if ( 0 !== strpos( $class_name, __NAMESPACE__ . '\\' ) ) {
			return;
		}

		$relative = substr( $class_name, strlen( __NAMESPACE__ ) + 1 );
		$parts    = explode( '\\', $relative );
		$file     = 'class-' . str_replace( '_', '-', strtolower( array_pop( $parts ) ) ) . '.php';
		$dir      = strtolower( implode( '/', $parts ) );
		$path     = PROMO_ENGINE_DIR . 'includes/' . ( $dir ? $dir . '/' : '' ) . $file;

		if ( file_exists( $path ) ) {
			require $path;
		}
	}
}

<?php
/**
 * Plugin Name: Media Library Enhance
 * Plugin URI:  https://github.com/Automattic/media-library-enhance
 * Description: Enterprise-grade media library improvements: Elasticsearch-powered search, image replacement, duplicate detection, and media taxonomies. Built for WordPress VIP.
 * Version:     0.1.0-alpha
 * Author:      Automattic
 * Author URI:  https://automattic.com
 * License:     GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: media-library-enhance
 * Requires PHP: 8.1
 * Requires at least: 6.7
 *
 * @package MediaLibraryEnhance
 */

namespace MediaLibraryEnhance;

defined( 'ABSPATH' ) || exit;

const VERSION    = '0.1.0-alpha';
const PLUGIN_DIR = __DIR__;

/**
 * Autoloader for plugin classes.
 *
 * Maps MediaLibraryEnhance\Foo\Bar to includes/foo/class-bar.php
 */
spl_autoload_register(
	function ( string $class ): void {
		$prefix = __NAMESPACE__ . '\\';
		if ( 0 !== strpos( $class, $prefix ) ) {
			return;
		}

		$relative = strtolower( substr( $class, strlen( $prefix ) ) );
		$relative = str_replace( '_', '-', $relative );
		$parts    = explode( '\\', $relative );
		$filename = 'class-' . array_pop( $parts );
		$path     = PLUGIN_DIR . '/includes/' . implode( '/', $parts ) . '/' . $filename . '.php';

		if ( file_exists( $path ) ) {
			require_once $path;
		}
	}
);

/**
 * Bootstrap the plugin.
 */
function bootstrap(): void {
	// Feature modules — each registers its own hooks.
	Search\Elasticsearch_Query::instance()->register();
	Search\REST_Search::instance()->register();

	Taxonomies\Media_Taxonomies::instance()->register();
	Taxonomies\REST_Taxonomies::instance()->register();

	Replacement\Usage_Tracker::instance()->register();
	Replacement\Image_Replacer::instance()->register();
	Replacement\REST_Replacement::instance()->register();

	Duplicates\Hash_Generator::instance()->register();
	Duplicates\Duplicate_Finder::instance()->register();
	Duplicates\REST_Duplicates::instance()->register();

	// WP-CLI commands (only in CLI context).
	if ( defined( 'WP_CLI' ) && WP_CLI ) {
		CLI\Search_Command::register_command();
		CLI\Usage_Command::register_command();
		CLI\Duplicates_Command::register_command();
		CLI\Taxonomies_Command::register_command();
	}
}

add_action( 'plugins_loaded', __NAMESPACE__ . '\\bootstrap' );

<?php
/**
 * PHPUnit bootstrap for Media Library Enhance tests.
 *
 * Expects WP_TESTS_DIR to point to the WordPress test library.
 * On VIP: `WP_TESTS_DIR=/path/to/wordpress-develop/tests/phpunit`
 *
 * @package MediaLibraryEnhance\Tests
 */

$wp_tests_dir = getenv( 'WP_TESTS_DIR' ) ?: '/tmp/wordpress-tests-lib';

if ( ! file_exists( $wp_tests_dir . '/includes/functions.php' ) ) {
	echo "Could not find WordPress test library at {$wp_tests_dir}.\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	echo "Set WP_TESTS_DIR to the wordpress-develop tests/phpunit directory.\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	exit( 1 );
}

// Point to the PHPUnit Polyfills library (required by modern wordpress-develop tests).
$polyfills_path = dirname( __DIR__ ) . '/vendor/yoast/phpunit-polyfills';
if ( file_exists( $polyfills_path ) ) {
	define( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH', $polyfills_path );
}

// Load the plugin before WordPress initializes.
require_once $wp_tests_dir . '/includes/functions.php';

tests_add_filter(
	'muplugins_loaded',
	function () {
		require dirname( __DIR__ ) . '/media-library-enhance.php';
	}
);

require $wp_tests_dir . '/includes/bootstrap.php';

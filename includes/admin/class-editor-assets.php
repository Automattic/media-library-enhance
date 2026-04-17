<?php
/**
 * Editor Assets.
 *
 * Enqueues the compiled block-editor JavaScript bundle that powers the
 * tags InspectorControls panel, the duplicate-on-upload notice, and the
 * "replace everywhere" toolbar button.
 *
 * The bundle is built from `src/` via @wordpress/scripts and emits
 * `build/index.js` plus an `index.asset.php` manifest listing the
 * required script handles and a content-hashed version string.
 *
 * @package MediaLibraryEnhance\Admin
 */

namespace MediaLibraryEnhance\Admin;

defined( 'ABSPATH' ) || exit;

class Editor_Assets {

	private static ?self $instance = null;

	public const HANDLE = 'mle-editor';

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function register(): void {
		add_action( 'enqueue_block_editor_assets', [ $this, 'enqueue' ] );
	}

	/**
	 * Enqueue the compiled editor bundle.
	 *
	 * Fails silent if the build hasn't been run — keeps fresh checkouts
	 * from white-screening the block editor before `npm run build`.
	 */
	public function enqueue(): void {
		$asset_file = \MediaLibraryEnhance\PLUGIN_DIR . '/build/index.asset.php';
		if ( ! file_exists( $asset_file ) ) {
			return;
		}

		$asset = require $asset_file;

		wp_enqueue_script(
			self::HANDLE,
			plugins_url( 'build/index.js', \MediaLibraryEnhance\PLUGIN_DIR . '/media-library-enhance.php' ),
			$asset['dependencies'] ?? [],
			$asset['version'] ?? \MediaLibraryEnhance\VERSION,
			true
		);

		wp_set_script_translations( self::HANDLE, 'media-library-enhance' );
	}
}

<?php
/**
 * MU-plugin loaded in wp-env for local development.
 *
 * Provides test data seeding and hooks useful during development.
 * This file is mapped into wp-content/mu-plugins via .wp-env.json.
 *
 * @package MediaLibraryEnhance\Dev
 */

// One-time dev environment setup: pretty permalinks and seed taxonomies.
// E2E tests require pretty permalinks for /wp-json/ to resolve.
add_action(
	'init',
	function () {
		if ( get_option( 'mle_dev_seeded' ) ) {
			return;
		}

		// Pretty permalinks (required for /wp-json/ routes).
		global $wp_rewrite;
		$wp_rewrite->set_permalink_structure( '/%postname%/' );
		$wp_rewrite->flush_rules( true );

		$categories = [ 'Photos', 'Logos', 'Documents', 'Videos', 'Infographics' ];
		foreach ( $categories as $name ) {
			if ( ! term_exists( $name, 'media_category' ) ) {
				wp_insert_term( $name, 'media_category' );
			}
		}

		$tags = [ 'hero-image', 'thumbnail', 'banner', 'archived', 'needs-alt-text' ];
		foreach ( $tags as $name ) {
			if ( ! term_exists( $name, 'media_tag' ) ) {
				wp_insert_term( $name, 'media_tag' );
			}
		}

		update_option( 'mle_dev_seeded', true );
	},
	100
);

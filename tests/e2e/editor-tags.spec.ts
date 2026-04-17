/**
 * E2E test: tags InspectorControls panel in the block editor.
 *
 * Verifies that the mle-editor script is enqueued and our BlockEdit
 * filter is registered when the post editor loads.
 *
 * Requires: npm run build && npm run wp-env:start
 */
import { test, expect } from '@playwright/test';
import { loginToAdmin } from './helpers';

const BASE_URL = process.env.WP_BASE_URL || 'http://localhost:8888';

test.describe( 'Tags InspectorControls Panel', () => {
	test( 'mle-editor script is enqueued in the block editor', async ( {
		page,
	} ) => {
		await loginToAdmin( page );
		await page.goto( `${ BASE_URL }/wp-admin/post-new.php`, {
			waitUntil: 'load',
			timeout: 30_000,
		} );

		// The build/index.js should be enqueued via the mle-editor handle.
		const scriptTag = page.locator( 'script[id="mle-editor-js"]' );
		await expect( scriptTag ).toBeAttached( { timeout: 10_000 } );
	} );

	test( 'mle/tags-panel filter is registered after script loads', async ( {
		page,
	} ) => {
		await loginToAdmin( page );
		await page.goto( `${ BASE_URL }/wp-admin/post-new.php`, {
			waitUntil: 'load',
			timeout: 30_000,
		} );

		// Wait for our script to load, then check the hooks registry.
		await page.waitForFunction(
			() =>
				typeof wp !== 'undefined' &&
				typeof wp.hooks !== 'undefined' &&
				wp.hooks.hasFilter(
					'editor.BlockEdit',
					'mle/tags-panel'
				),
			{ timeout: 15_000 }
		);
	} );
} );

/**
 * E2E test: "Replace everywhere" toolbar button on core/image.
 *
 * Verifies that our BlockEdit filter for the replace button is
 * registered when the block editor loads.
 *
 * Requires: npm run build && npm run wp-env:start
 */
import { test, expect } from '@playwright/test';
import { loginToAdmin } from './helpers';

const BASE_URL = process.env.WP_BASE_URL || 'http://localhost:8888';

test.describe( 'Replace Everywhere Toolbar Button', () => {
	test( 'mle/replace-everywhere filter is registered after script loads', async ( {
		page,
	} ) => {
		await loginToAdmin( page );
		await page.goto( `${ BASE_URL }/wp-admin/post-new.php`, {
			waitUntil: 'load',
			timeout: 30_000,
		} );

		await page.waitForFunction(
			() =>
				typeof wp !== 'undefined' &&
				typeof wp.hooks !== 'undefined' &&
				wp.hooks.hasFilter(
					'editor.BlockEdit',
					'mle/replace-everywhere'
				),
			{ timeout: 15_000 }
		);
	} );
} );

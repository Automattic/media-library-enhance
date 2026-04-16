/**
 * Playwright E2E test configuration.
 *
 * Runs against a wp-env instance at localhost:8888.
 * Start with: npm run wp-env:start
 */
import { defineConfig } from '@playwright/test';

const baseURL = process.env.WP_BASE_URL || 'http://localhost:8888';

export default defineConfig( {
	testDir: './tests/e2e',
	outputDir: './tests/e2e/results',
	fullyParallel: false,
	workers: 1,
	retries: 0,
	timeout: 60_000,
	expect: {
		timeout: 10_000,
	},
	use: {
		baseURL,
		trace: 'retain-on-failure',
	},
	projects: [
		{
			name: 'setup',
			testMatch: /global-setup\.ts/,
		},
		{
			name: 'e2e',
			dependencies: [ 'setup' ],
		},
	],
} );

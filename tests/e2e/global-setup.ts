/**
 * Global setup — runs once before all E2E tests.
 *
 * - Creates a fresh application password for the admin user (REST API auth).
 * - Verifies WordPress is running and the plugin's REST namespace is registered.
 */
import { test as setup, expect } from '@playwright/test';
import { execSync } from 'node:child_process';
import { writeFileSync } from 'node:fs';
import { join } from 'node:path';
import { createAuthenticatedContext } from './helpers';

const APP_PASSWORD_FILE = join( __dirname, '.app-password' );

setup( 'verify WordPress is running and plugin is active', async () => {
	// Create a fresh application password for the admin user.
	// This works around WP requiring app passwords (not the main login pass)
	// for REST API authentication from external clients.
	const password = execSync(
		'npx wp-env run cli wp user application-password create admin e2e-tests --porcelain',
		{ encoding: 'utf-8', timeout: 30_000, stdio: [ 'ignore', 'pipe', 'ignore' ] }
	).trim();

	writeFileSync( APP_PASSWORD_FILE, password );

	const api = await createAuthenticatedContext();

	// Verify WordPress is responding.
	const response = await api.get( '/wp-json/' );
	expect( response.ok() ).toBeTruthy();

	// Verify our REST namespace is registered.
	const index = await response.json();
	expect( index.namespaces ).toContain( 'mle/v1' );

	await api.dispose();
} );

/**
 * Shared helpers for E2E tests.
 *
 * Provides authenticated REST API requests and WP-CLI execution
 * against the wp-env instance.
 */
import { APIRequestContext, request } from '@playwright/test';
import { execSync } from 'node:child_process';
import { existsSync, readFileSync } from 'node:fs';
import { join } from 'node:path';

const BASE_URL = process.env.WP_BASE_URL || 'http://localhost:8888';
const ADMIN_USER = 'admin';
const APP_PASSWORD_FILE = join( __dirname, '.app-password' );

/**
 * Read the application password created by global-setup.
 */
function getAppPassword(): string {
	if ( ! existsSync( APP_PASSWORD_FILE ) ) {
		throw new Error(
			`Application password not found at ${ APP_PASSWORD_FILE }. ` +
				'Run global-setup first.'
		);
	}
	return readFileSync( APP_PASSWORD_FILE, 'utf-8' ).trim();
}

/**
 * Create an authenticated API request context using an application password.
 *
 * WordPress requires application passwords for REST API authentication
 * from external clients (the user's main login password won't work).
 * The password is created by global-setup and stored locally.
 */
export async function createAuthenticatedContext( user = ADMIN_USER, password?: string ): Promise< APIRequestContext > {
	const pass = password ?? getAppPassword();
	return request.newContext( {
		baseURL: BASE_URL,
		extraHTTPHeaders: {
			Authorization:
				'Basic ' +
				Buffer.from( `${ user }:${ pass }` ).toString( 'base64' ),
		},
	} );
}

/**
 * Run a WP-CLI command inside the wp-env container.
 *
 * @param command - The WP-CLI command (without the `wp` prefix).
 * @returns stdout as a string.
 */
export function wpCli( command: string ): string {
	return execSync( `npx wp-env run cli wp ${ command }`, {
		encoding: 'utf-8',
		timeout: 30_000,
	} ).trim();
}

const FIXTURE_IMAGE = join( __dirname, 'fixtures', 'test-image.jpg' );

/**
 * Read the fixture JPEG.
 *
 * It's a real 8x8 JPEG — not a base64 stub — so PHP's getimagesize/exif
 * don't emit warnings that pollute the REST response.
 */
export function getTestImageBytes(): Buffer {
	return readFileSync( FIXTURE_IMAGE );
}

/**
 * Upload a test image via the REST API and return its attachment ID.
 */
export async function uploadTestImage(
	api: APIRequestContext,
	filename = 'test-image.jpg'
): Promise< number > {
	const response = await api.post( '/wp-json/wp/v2/media', {
		multipart: {
			file: {
				name: filename,
				mimeType: 'image/jpeg',
				buffer: getTestImageBytes(),
			},
		},
	} );

	const text = await response.text();
	let body;
	try {
		body = JSON.parse( text );
	} catch ( e ) {
		throw new Error(
			`Failed to upload image. Status ${ response.status() }. Body: ${ text.substring( 0, 500 ) }`
		);
	}
	if ( ! body.id ) {
		throw new Error(
			`Upload returned no ID. Status ${ response.status() }. Body: ${ text.substring( 0, 500 ) }`
		);
	}
	return body.id;
}

/**
 * Shared helpers for E2E tests.
 *
 * Provides authenticated REST API requests and WP-CLI execution
 * against the wp-env instance.
 */
import { APIRequestContext, request, type Page } from '@playwright/test';
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

/**
 * Log into wp-admin via the browser login form.
 *
 * Uses waitForNavigation with 'domcontentloaded' to avoid timeout issues
 * with wp-admin's full resource loading.
 */
export async function loginToAdmin( page: Page ): Promise< void > {
	await page.goto( `${ BASE_URL }/wp-login.php` );
	await page.fill( '#user_login', 'admin' );
	await page.fill( '#user_pass', 'password' );
	await Promise.all( [
		page.waitForNavigation( { waitUntil: 'domcontentloaded' } ),
		page.click( '#wp-submit' ),
	] );
}

/**
 * Navigate to a fresh post editor and dismiss the welcome guide.
 *
 * WordPress 6.2+ renders the editor canvas inside an iframe. Block
 * content locators must target the iframe; toolbar/sidebar locators
 * target the outer page. Use `editorFrame()` for in-canvas selectors.
 */
export async function createNewPost( page: Page ): Promise< void > {
	await page.goto( `${ BASE_URL }/wp-admin/post-new.php`, {
		waitUntil: 'domcontentloaded',
	} );
	// Dismiss the welcome modal if visible.
	const welcomeModal = page.locator(
		'.edit-post-welcome-guide .components-modal__header button'
	);
	if (
		await welcomeModal
			.isVisible( { timeout: 3_000 } )
			.catch( () => false )
	) {
		await welcomeModal.click();
	}
	// Wait for the editor canvas iframe to load.
	const canvas = editorFrame( page );
	await canvas.locator( 'body' ).waitFor( { timeout: 15_000 } );
}

/**
 * Get the FrameLocator for the editor canvas iframe.
 *
 * In WP 6.2+ the block content lives in an iframe within the editor.
 * Use this for any locator targeting block content. Toolbar and sidebar
 * locators remain on the outer `page`.
 */
export function editorFrame( page: Page ) {
	return page.frameLocator( 'iframe[name="editor-canvas"]' );
}

/**
 * Insert an image block and upload the test fixture.
 * Returns when the <img> has loaded in the editor canvas.
 */
export async function insertImageBlockWithFixture(
	page: Page
): Promise< void > {
	const canvas = editorFrame( page );

	// Click the empty block appender to start typing.
	const appender = canvas.locator(
		'.block-editor-default-block-appender__content'
	);
	await appender.waitFor( { timeout: 10_000 } );
	await appender.click();

	// Type /image to trigger the slash command inserter.
	await page.keyboard.type( '/image' );
	// The autocomplete menu renders on the outer page.
	await page
		.getByRole( 'option', { name: 'Image' } )
		.first()
		.click();

	// The file upload input is inside the canvas iframe.
	const fileInput = canvas.locator(
		'[data-type="core/image"] .components-form-file-upload input[type="file"]'
	);
	await fileInput.setInputFiles( join( __dirname, 'fixtures', 'test-image.jpg' ) );
	await canvas
		.locator( '[data-type="core/image"] img[src]' )
		.waitFor( { timeout: 15_000 } );
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

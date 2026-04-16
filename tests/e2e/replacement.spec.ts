/**
 * E2E tests for the image replacement and usage tracking endpoints.
 *
 * Tests /mle/v1/usage/{id} and /mle/v1/replace/{id}.
 */
import { test, expect } from '@playwright/test';
import { createAuthenticatedContext, getTestImageBytes, uploadTestImage } from './helpers';
import type { APIRequestContext } from '@playwright/test';

let api: APIRequestContext;
let attachmentId: number;
let postId: number;

test.beforeAll( async () => {
	api = await createAuthenticatedContext();

	// Upload a test image.
	attachmentId = await uploadTestImage( api, 'mle-replace-test.jpg' );

	// Create a post that references this attachment.
	const postResponse = await api.post( '/wp-json/wp/v2/posts', {
		data: {
			title: 'MLE Replacement E2E Test Post',
			content: `<!-- wp:image {"id":${ attachmentId }} --><figure><img src="test.jpg"/></figure><!-- /wp:image -->`,
			status: 'publish',
		},
	} );
	const post = await postResponse.json();
	postId = post.id;

	// Wait briefly for the save_post hook to fire and update usage.
	await new Promise( ( resolve ) => setTimeout( resolve, 500 ) );
} );

test.afterAll( async () => {
	await api.delete( `/wp-json/wp/v2/posts/${ postId }?force=true` );
	await api.delete( `/wp-json/wp/v2/media/${ attachmentId }?force=true` );
	await api.dispose();
} );

test.describe( 'GET /mle/v1/usage/{id}', () => {
	test( 'returns usage data for an attachment', async () => {
		const response = await api.get(
			`/wp-json/mle/v1/usage/${ attachmentId }`
		);

		expect( response.ok() ).toBeTruthy();

		const body = await response.json();
		expect( body ).toHaveProperty( 'post_count' );
		expect( body ).toHaveProperty( 'posts' );
		expect( Array.isArray( body.posts ) ).toBeTruthy();
	} );

	test( 'returns empty usage for an unused attachment', async () => {
		const unusedId = await uploadTestImage( api, 'mle-unused.jpg' );

		const response = await api.get(
			`/wp-json/mle/v1/usage/${ unusedId }`
		);

		expect( response.ok() ).toBeTruthy();
		const body = await response.json();
		expect( body.post_count ).toBe( 0 );

		await api.delete( `/wp-json/wp/v2/media/${ unusedId }?force=true` );
	} );
} );

test.describe( 'POST /mle/v1/replace/{id}', () => {
	test( 'replaces an attachment file', async () => {
		const response = await api.post(
			`/wp-json/mle/v1/replace/${ attachmentId }`,
			{
				multipart: {
					file: {
						name: 'replacement.jpg',
						mimeType: 'image/jpeg',
						buffer: getTestImageBytes(),
					},
				},
			}
		);

		expect( response.ok() ).toBeTruthy();

		const body = await response.json();
		expect( body.success ).toBeTruthy();
		expect( body.attachment.id ).toBe( attachmentId );
		// URL should be preserved (same path, same ID).
		expect( body.attachment.url ).toBeDefined();
	} );

	test( 'returns error when no file is provided', async () => {
		const response = await api.post(
			`/wp-json/mle/v1/replace/${ attachmentId }`,
			{ data: {} }
		);

		expect( response.ok() ).toBeFalsy();
	} );

	test( 'requires edit_others_posts capability', async () => {
		const { execSync } = await import( 'node:child_process' );

		// Create a subscriber user.
		const userResponse = await api.post( '/wp-json/wp/v2/users', {
			data: {
				username: 'mle_e2e_subscriber',
				password: 'subscriberpass123!',
				email: 'subscriber@example.com',
				roles: [ 'subscriber' ],
			},
		} );
		const user = await userResponse.json();

		// Create an application password for the subscriber (REST API auth
		// requires an app password, not the user's main login password).
		const subPassword = execSync(
			'npx wp-env run cli wp user application-password create mle_e2e_subscriber e2e --porcelain',
			{ encoding: 'utf-8', timeout: 30_000, stdio: [ 'ignore', 'pipe', 'ignore' ] }
		).trim();

		const subApi = await createAuthenticatedContext(
			'mle_e2e_subscriber',
			subPassword
		);

		const response = await subApi.post(
			`/wp-json/mle/v1/replace/${ attachmentId }`,
			{ data: {} }
		);

		expect( response.status() ).toBe( 403 );

		// Clean up user.
		await api.delete( `/wp-json/wp/v2/users/${ user.id }?force=true&reassign=1` );
		await subApi.dispose();
	} );
} );

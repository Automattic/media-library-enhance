/**
 * E2E tests for the search REST endpoint.
 *
 * Tests /mle/v1/search against a running wp-env instance.
 * Without Elasticsearch, these validate the MySQL fallback path.
 */
import { test, expect } from '@playwright/test';
import { createAuthenticatedContext, uploadTestImage } from './helpers';
import type { APIRequestContext } from '@playwright/test';

let api: APIRequestContext;
let attachmentId: number;

test.beforeAll( async () => {
	api = await createAuthenticatedContext();

	// Upload a test image with a searchable title.
	attachmentId = await uploadTestImage( api, 'mle-search-test-logo.jpg' );

	// Update the title to something unique and searchable.
	await api.post( `/wp-json/wp/v2/media/${ attachmentId }`, {
		data: { title: 'MLE Search E2E Test Logo' },
	} );
} );

test.afterAll( async () => {
	// Clean up: delete the test attachment.
	await api.delete( `/wp-json/wp/v2/media/${ attachmentId }?force=true` );
	await api.dispose();
} );

test.describe( 'GET /mle/v1/search', () => {
	test( 'returns results for a matching search term', async () => {
		const response = await api.get( '/wp-json/mle/v1/search', {
			params: { search: 'MLE Search E2E Test Logo' },
		} );

		expect( response.ok() ).toBeTruthy();

		const results = await response.json();
		expect( Array.isArray( results ) ).toBeTruthy();
		expect( results.length ).toBeGreaterThan( 0 );

		const match = results.find(
			( item: { id: number } ) => item.id === attachmentId
		);
		expect( match ).toBeDefined();
		expect( match.title ).toBe( 'MLE Search E2E Test Logo' );
		expect( match.mime_type ).toBe( 'image/jpeg' );
	} );

	test( 'returns X-MLE-Search-Engine header', async () => {
		const response = await api.get( '/wp-json/mle/v1/search', {
			params: { search: 'logo' },
		} );

		const engine = response.headers()[ 'x-mle-search-engine' ];
		expect( engine ).toBeDefined();
		// Without ES in wp-env, this should be mysql.
		expect( engine ).toBe( 'mysql' );
	} );

	test( 'returns empty array for no matches', async () => {
		const response = await api.get( '/wp-json/mle/v1/search', {
			params: { search: 'xyznonexistent99999' },
		} );

		expect( response.ok() ).toBeTruthy();
		const results = await response.json();
		expect( results ).toEqual( [] );
	} );

	test( 'respects per_page parameter', async () => {
		const response = await api.get( '/wp-json/mle/v1/search', {
			params: { search: 'MLE Search E2E', per_page: '1' },
		} );

		expect( response.ok() ).toBeTruthy();
		const results = await response.json();
		expect( results.length ).toBeLessThanOrEqual( 1 );
	} );

	test( 'requires authentication', async () => {
		const unauthApi = await ( await import( '@playwright/test' ) ).request.newContext( {
			baseURL: process.env.WP_BASE_URL || 'http://localhost:8888',
		} );

		const response = await unauthApi.get( '/wp-json/mle/v1/search', {
			params: { search: 'test' },
		} );

		expect( response.status() ).toBe( 401 );
		await unauthApi.dispose();
	} );
} );

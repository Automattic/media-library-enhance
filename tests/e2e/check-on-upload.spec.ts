/**
 * E2E tests for GET /mle/v1/duplicates/check-on-upload.
 *
 * Verifies the combined exact + similar duplicate lookup that the
 * Gutenberg duplicate-notice JS calls on every new upload.
 */
import { test, expect } from '@playwright/test';
import { createAuthenticatedContext, uploadTestImage } from './helpers';
import type { APIRequestContext } from '@playwright/test';

let api: APIRequestContext;
let firstUploadId: number;
let secondUploadId: number;

test.beforeAll( async () => {
	api = await createAuthenticatedContext();

	// Upload the same JPEG twice — same file hash → exact duplicate.
	firstUploadId = await uploadTestImage( api, 'mle-check-upload-1.jpg' );
	secondUploadId = await uploadTestImage( api, 'mle-check-upload-2.jpg' );
} );

test.afterAll( async () => {
	await api.delete(
		`/wp-json/wp/v2/media/${ firstUploadId }?force=true`
	);
	await api.delete(
		`/wp-json/wp/v2/media/${ secondUploadId }?force=true`
	);
	await api.dispose();
} );

test.describe( 'GET /mle/v1/duplicates/check-on-upload', () => {
	test( 'returns exact and similar arrays', async () => {
		const response = await api.get(
			`/wp-json/mle/v1/duplicates/check-on-upload`,
			{ params: { attachment_id: String( secondUploadId ) } }
		);

		expect( response.ok() ).toBeTruthy();

		const body = await response.json();
		expect( body ).toHaveProperty( 'attachment_id', secondUploadId );
		expect( body ).toHaveProperty( 'exact' );
		expect( body ).toHaveProperty( 'similar' );
		expect( body ).toHaveProperty( 'threshold' );
		expect( Array.isArray( body.exact ) ).toBeTruthy();
		expect( Array.isArray( body.similar ) ).toBeTruthy();
	} );

	test( 'finds the first upload as an exact duplicate of the second', async () => {
		const response = await api.get(
			`/wp-json/mle/v1/duplicates/check-on-upload`,
			{ params: { attachment_id: String( secondUploadId ) } }
		);

		const body = await response.json();
		const exactIds = body.exact.map( ( d: { id: number } ) => d.id );
		expect( exactIds ).toContain( firstUploadId );
	} );

	test( 'exact matches include thumbnail field', async () => {
		const response = await api.get(
			`/wp-json/mle/v1/duplicates/check-on-upload`,
			{ params: { attachment_id: String( secondUploadId ) } }
		);

		const body = await response.json();
		if ( body.exact.length > 0 ) {
			expect( body.exact[ 0 ] ).toHaveProperty( 'thumbnail' );
			expect( body.exact[ 0 ] ).toHaveProperty( 'url' );
			expect( body.exact[ 0 ] ).toHaveProperty( 'title' );
		}
	} );

	test( 'excludes the queried attachment from results', async () => {
		const response = await api.get(
			`/wp-json/mle/v1/duplicates/check-on-upload`,
			{ params: { attachment_id: String( secondUploadId ) } }
		);

		const body = await response.json();
		const exactIds = body.exact.map( ( d: { id: number } ) => d.id );
		const similarIds = body.similar.map( ( d: { id: number } ) => d.id );
		expect( exactIds ).not.toContain( secondUploadId );
		expect( similarIds ).not.toContain( secondUploadId );
	} );

	test( 'returns 404 for non-existent attachment', async () => {
		const response = await api.get(
			`/wp-json/mle/v1/duplicates/check-on-upload`,
			{ params: { attachment_id: '999999999' } }
		);

		expect( response.status() ).toBe( 404 );
	} );
} );

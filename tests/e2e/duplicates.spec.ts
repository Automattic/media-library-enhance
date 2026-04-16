/**
 * E2E tests for the duplicate detection endpoints.
 *
 * Tests /mle/v1/duplicates/{id} and /mle/v1/duplicates/{id}/similar.
 */
import { test, expect } from '@playwright/test';
import { createAuthenticatedContext, uploadTestImage } from './helpers';
import type { APIRequestContext } from '@playwright/test';

let api: APIRequestContext;
let attachmentId1: number;
let attachmentId2: number;

test.beforeAll( async () => {
	api = await createAuthenticatedContext();

	// Upload two identical images — should produce the same file hash.
	attachmentId1 = await uploadTestImage( api, 'mle-dup-test-1.jpg' );
	attachmentId2 = await uploadTestImage( api, 'mle-dup-test-2.jpg' );
} );

test.afterAll( async () => {
	await api.delete(
		`/wp-json/wp/v2/media/${ attachmentId1 }?force=true`
	);
	await api.delete(
		`/wp-json/wp/v2/media/${ attachmentId2 }?force=true`
	);
	await api.dispose();
} );

test.describe( 'GET /mle/v1/duplicates/{id}', () => {
	test( 'returns duplicate information for an attachment', async () => {
		const response = await api.get(
			`/wp-json/mle/v1/duplicates/${ attachmentId1 }`
		);

		expect( response.ok() ).toBeTruthy();

		const body = await response.json();
		expect( body ).toHaveProperty( 'attachment_id', attachmentId1 );
		expect( body ).toHaveProperty( 'duplicates' );
		expect( body ).toHaveProperty( 'count' );
		expect( Array.isArray( body.duplicates ) ).toBeTruthy();
	} );

	test( 'finds exact duplicate when same file is uploaded twice', async () => {
		const response = await api.get(
			`/wp-json/mle/v1/duplicates/${ attachmentId1 }`
		);

		const body = await response.json();

		// Both images use the same base64 JPEG, so they should have the same hash.
		expect( body.count ).toBeGreaterThanOrEqual( 1 );

		const ids = body.duplicates.map(
			( d: { id: number } ) => d.id
		);
		expect( ids ).toContain( attachmentId2 );
	} );

	test( 'duplicate entry includes expected fields', async () => {
		const response = await api.get(
			`/wp-json/mle/v1/duplicates/${ attachmentId1 }`
		);

		const body = await response.json();

		if ( body.duplicates.length > 0 ) {
			const dup = body.duplicates[ 0 ];
			expect( dup ).toHaveProperty( 'id' );
			expect( dup ).toHaveProperty( 'title' );
			expect( dup ).toHaveProperty( 'url' );
			expect( dup ).toHaveProperty( 'mime_type' );
			expect( dup ).toHaveProperty( 'date' );
		}
	} );
} );

test.describe( 'GET /mle/v1/duplicates/{id}/similar', () => {
	test( 'returns similar image data with distance scores', async () => {
		const response = await api.get(
			`/wp-json/mle/v1/duplicates/${ attachmentId1 }/similar`
		);

		expect( response.ok() ).toBeTruthy();

		const body = await response.json();
		expect( body ).toHaveProperty( 'attachment_id', attachmentId1 );
		expect( body ).toHaveProperty( 'threshold' );
		expect( body ).toHaveProperty( 'similar' );
		expect( body ).toHaveProperty( 'count' );
	} );

	test( 'respects custom threshold parameter', async () => {
		const response = await api.get(
			`/wp-json/mle/v1/duplicates/${ attachmentId1 }/similar`,
			{ params: { threshold: '0' } }
		);

		expect( response.ok() ).toBeTruthy();

		const body = await response.json();
		expect( body.threshold ).toBe( 0 );

		// With threshold 0, only exact perceptual matches should appear.
		for ( const match of body.similar ) {
			expect( match.distance ).toBe( 0 );
		}
	} );
} );

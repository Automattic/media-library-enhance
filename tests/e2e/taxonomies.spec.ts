/**
 * E2E tests for media taxonomies.
 *
 * Tests taxonomy registration and the bulk-assign REST endpoint.
 */
import { test, expect } from '@playwright/test';
import { createAuthenticatedContext, uploadTestImage } from './helpers';
import type { APIRequestContext } from '@playwright/test';

let api: APIRequestContext;
let attachmentId1: number;
let attachmentId2: number;
let categoryTermId: number;

test.beforeAll( async () => {
	api = await createAuthenticatedContext();

	// Upload two test images.
	attachmentId1 = await uploadTestImage( api, 'mle-tax-test-1.jpg' );
	attachmentId2 = await uploadTestImage( api, 'mle-tax-test-2.jpg' );

	// Create a media_category term.
	const termResponse = await api.post(
		'/wp-json/wp/v2/media_category',
		{ data: { name: 'E2E Test Category' } }
	);
	const term = await termResponse.json();
	categoryTermId = term.id;
} );

test.afterAll( async () => {
	await api.delete( `/wp-json/wp/v2/media/${ attachmentId1 }?force=true` );
	await api.delete( `/wp-json/wp/v2/media/${ attachmentId2 }?force=true` );
	await api.delete(
		`/wp-json/wp/v2/media_category/${ categoryTermId }?force=true`
	);
	await api.dispose();
} );

test.describe( 'Media Taxonomies', () => {
	test( 'media_category taxonomy is registered and available via REST', async () => {
		const response = await api.get( '/wp-json/wp/v2/taxonomies/media_category' );
		expect( response.ok() ).toBeTruthy();

		const taxonomy = await response.json();
		expect( taxonomy.slug ).toBe( 'media_category' );
		expect( taxonomy.hierarchical ).toBeTruthy();
	} );

	test( 'media_tag taxonomy is registered and available via REST', async () => {
		const response = await api.get( '/wp-json/wp/v2/taxonomies/media_tag' );
		expect( response.ok() ).toBeTruthy();

		const taxonomy = await response.json();
		expect( taxonomy.slug ).toBe( 'media_tag' );
		expect( taxonomy.hierarchical ).toBeFalsy();
	} );
} );

test.describe( 'POST /mle/v1/taxonomies/bulk-assign', () => {
	test( 'assigns a term to multiple attachments', async () => {
		const response = await api.post(
			'/wp-json/mle/v1/taxonomies/bulk-assign',
			{
				data: {
					attachment_ids: [ attachmentId1, attachmentId2 ],
					taxonomy: 'media_category',
					terms: [ categoryTermId ],
					append: true,
				},
			}
		);

		expect( response.ok() ).toBeTruthy();

		const body = await response.json();
		expect( body.results[ attachmentId1 ].success ).toBeTruthy();
		expect( body.results[ attachmentId2 ].success ).toBeTruthy();

		// Verify the terms were actually assigned by querying the attachment.
		const att1Response = await api.get(
			`/wp-json/wp/v2/media/${ attachmentId1 }`
		);
		const att1 = await att1Response.json();
		expect( att1.media_category ).toContain( categoryTermId );
	} );

	test( 'rejects invalid taxonomy name', async () => {
		const response = await api.post(
			'/wp-json/mle/v1/taxonomies/bulk-assign',
			{
				data: {
					attachment_ids: [ attachmentId1 ],
					taxonomy: 'not_a_real_taxonomy',
					terms: [ 1 ],
				},
			}
		);

		expect( response.ok() ).toBeFalsy();
	} );
} );

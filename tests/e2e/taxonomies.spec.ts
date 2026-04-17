/**
 * E2E tests for media taxonomies.
 *
 * Tests media_tag registration and the bulk-assign REST endpoint.
 */
import { test, expect } from '@playwright/test';
import { createAuthenticatedContext, uploadTestImage } from './helpers';
import type { APIRequestContext } from '@playwright/test';

let api: APIRequestContext;
let attachmentId1: number;
let attachmentId2: number;
let tagTermId: number;

test.beforeAll( async () => {
	api = await createAuthenticatedContext();

	// Upload two test images.
	attachmentId1 = await uploadTestImage( api, 'mle-tax-test-1.jpg' );
	attachmentId2 = await uploadTestImage( api, 'mle-tax-test-2.jpg' );

	// Create a media_tag term.
	const termResponse = await api.post(
		'/wp-json/wp/v2/media_tag',
		{ data: { name: 'E2E Test Tag' } }
	);
	const term = await termResponse.json();
	tagTermId = term.id;
} );

test.afterAll( async () => {
	await api.delete( `/wp-json/wp/v2/media/${ attachmentId1 }?force=true` );
	await api.delete( `/wp-json/wp/v2/media/${ attachmentId2 }?force=true` );
	await api.delete(
		`/wp-json/wp/v2/media_tag/${ tagTermId }?force=true`
	);
	await api.dispose();
} );

test.describe( 'Media Taxonomies', () => {
	test( 'media_tag taxonomy is registered and available via REST', async () => {
		const response = await api.get( '/wp-json/wp/v2/taxonomies/media_tag' );
		expect( response.ok() ).toBeTruthy();

		const taxonomy = await response.json();
		expect( taxonomy.slug ).toBe( 'media_tag' );
		expect( taxonomy.hierarchical ).toBeFalsy();
	} );

	test( 'media_category taxonomy is no longer registered', async () => {
		const response = await api.get( '/wp-json/wp/v2/taxonomies/media_category' );
		// Either 404 from REST, or a non-OK response — both confirm absence.
		expect( response.status() ).toBe( 404 );
	} );
} );

test.describe( 'POST /mle/v1/taxonomies/bulk-assign', () => {
	test( 'assigns a tag to multiple attachments', async () => {
		const response = await api.post(
			'/wp-json/mle/v1/taxonomies/bulk-assign',
			{
				data: {
					attachment_ids: [ attachmentId1, attachmentId2 ],
					taxonomy: 'media_tag',
					terms: [ tagTermId ],
					append: true,
				},
			}
		);

		expect( response.ok() ).toBeTruthy();

		const body = await response.json();
		expect( body.results[ attachmentId1 ].success ).toBeTruthy();
		expect( body.results[ attachmentId2 ].success ).toBeTruthy();

		// Verify the tags were actually assigned by querying the attachment.
		const att1Response = await api.get(
			`/wp-json/wp/v2/media/${ attachmentId1 }`
		);
		const att1 = await att1Response.json();
		expect( att1.media_tag ).toContain( tagTermId );
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

	test( 'rejects media_category taxonomy (no longer accepted)', async () => {
		const response = await api.post(
			'/wp-json/mle/v1/taxonomies/bulk-assign',
			{
				data: {
					attachment_ids: [ attachmentId1 ],
					taxonomy: 'media_category',
					terms: [ 1 ],
				},
			}
		);

		expect( response.ok() ).toBeFalsy();
	} );
} );

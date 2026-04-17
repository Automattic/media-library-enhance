<?php
/**
 * Tests for media taxonomy registration.
 *
 * @package MediaLibraryEnhance\Tests\Taxonomies
 */

namespace MediaLibraryEnhance\Tests\Taxonomies;

use MediaLibraryEnhance\Taxonomies\Media_Taxonomies;
use WP_UnitTestCase;

class MediaTaxonomiesTest extends WP_UnitTestCase {

	public function test_media_tag_taxonomy_is_registered(): void {
		$this->assertTrue( taxonomy_exists( Media_Taxonomies::TAG_TAXONOMY ) );
	}

	public function test_media_tag_is_not_hierarchical(): void {
		$tax = get_taxonomy( Media_Taxonomies::TAG_TAXONOMY );
		$this->assertFalse( $tax->hierarchical );
	}

	public function test_media_tag_is_available_in_rest(): void {
		$tax = get_taxonomy( Media_Taxonomies::TAG_TAXONOMY );
		$this->assertTrue( $tax->show_in_rest );
	}

	public function test_legacy_media_category_taxonomy_is_not_registered(): void {
		// The dual-taxonomy scaffold registered media_category. Tags-only
		// is the current direction — categories should be gone.
		$this->assertFalse( taxonomy_exists( 'media_category' ) );
	}

	public function test_can_assign_tag_to_attachment(): void {
		$attachment_id = self::factory()->attachment->create();
		$term          = wp_insert_term( 'Hero Image', Media_Taxonomies::TAG_TAXONOMY );

		wp_set_object_terms( $attachment_id, [ $term['term_id'] ], Media_Taxonomies::TAG_TAXONOMY );

		$terms = wp_get_object_terms( $attachment_id, Media_Taxonomies::TAG_TAXONOMY );
		$this->assertCount( 1, $terms );
		$this->assertSame( 'Hero Image', $terms[0]->name );
	}
}

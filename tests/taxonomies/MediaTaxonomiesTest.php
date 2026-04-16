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

	public function test_media_category_taxonomy_is_registered(): void {
		$this->assertTrue( taxonomy_exists( Media_Taxonomies::CATEGORY_TAXONOMY ) );
	}

	public function test_media_tag_taxonomy_is_registered(): void {
		$this->assertTrue( taxonomy_exists( Media_Taxonomies::TAG_TAXONOMY ) );
	}

	public function test_media_category_is_hierarchical(): void {
		$tax = get_taxonomy( Media_Taxonomies::CATEGORY_TAXONOMY );
		$this->assertTrue( $tax->hierarchical );
	}

	public function test_media_tag_is_not_hierarchical(): void {
		$tax = get_taxonomy( Media_Taxonomies::TAG_TAXONOMY );
		$this->assertFalse( $tax->hierarchical );
	}

	public function test_taxonomies_are_available_in_rest(): void {
		$category_tax = get_taxonomy( Media_Taxonomies::CATEGORY_TAXONOMY );
		$tag_tax      = get_taxonomy( Media_Taxonomies::TAG_TAXONOMY );

		$this->assertTrue( $category_tax->show_in_rest );
		$this->assertTrue( $tag_tax->show_in_rest );
	}

	public function test_can_assign_category_to_attachment(): void {
		$attachment_id = self::factory()->attachment->create();
		$term          = wp_insert_term( 'Photos', Media_Taxonomies::CATEGORY_TAXONOMY );

		wp_set_object_terms( $attachment_id, [ $term['term_id'] ], Media_Taxonomies::CATEGORY_TAXONOMY );

		$terms = wp_get_object_terms( $attachment_id, Media_Taxonomies::CATEGORY_TAXONOMY );
		$this->assertCount( 1, $terms );
		$this->assertSame( 'Photos', $terms[0]->name );
	}
}

<?php
/**
 * Tests for the attachment usage tracker.
 *
 * @package MediaLibraryEnhance\Tests\Replacement
 */

namespace MediaLibraryEnhance\Tests\Replacement;

use MediaLibraryEnhance\Replacement\Usage_Tracker;
use WP_UnitTestCase;

class UsageTrackerTest extends WP_UnitTestCase {

	private Usage_Tracker $tracker;

	public function set_up(): void {
		parent::set_up();
		$this->tracker = Usage_Tracker::instance();
	}

	public function test_extract_block_editor_image_ids(): void {
		$content = '<!-- wp:image {"id":42} --><figure><img src="test.jpg"/></figure><!-- /wp:image -->';
		$ids     = $this->tracker->extract_attachment_ids( $content );

		$this->assertContains( 42, $ids );
	}

	public function test_extract_classic_editor_image_ids(): void {
		$content = '<img class="wp-image-99 size-large" src="test.jpg" />';
		$ids     = $this->tracker->extract_attachment_ids( $content );

		$this->assertContains( 99, $ids );
	}

	public function test_extract_multiple_images(): void {
		$content = '<!-- wp:image {"id":10} --><img/><!-- /wp:image -->'
			. '<!-- wp:image {"id":20} --><img/><!-- /wp:image -->'
			. '<img class="wp-image-30" />';
		$ids     = $this->tracker->extract_attachment_ids( $content );

		$this->assertContains( 10, $ids );
		$this->assertContains( 20, $ids );
		$this->assertContains( 30, $ids );
	}

	public function test_extract_deduplicates_ids(): void {
		$content = '<!-- wp:image {"id":42} --><img class="wp-image-42"/><!-- /wp:image -->';
		$ids     = $this->tracker->extract_attachment_ids( $content );

		// ID 42 appears in both block attrs and CSS class — should appear once.
		$this->assertSame( [ 42 ], $ids );
	}

	public function test_extract_ignores_empty_content(): void {
		$this->assertSame( [], $this->tracker->extract_attachment_ids( '' ) );
	}

	public function test_usage_meta_round_trip(): void {
		$attachment_id = self::factory()->attachment->create();
		$post_id       = self::factory()->post->create( [
			'post_content' => sprintf( '<!-- wp:image {"id":%d} --><img/><!-- /wp:image -->', $attachment_id ),
		] );

		// Trigger the save hook manually.
		$this->tracker->update_usage_on_save( $post_id, get_post( $post_id ) );

		$posts = $this->tracker->get_posts_using_attachment( $attachment_id );
		$this->assertContains( $post_id, $posts );
	}
}

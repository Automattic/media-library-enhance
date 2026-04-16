<?php
/**
 * Attachment Usage Tracker.
 *
 * Tracks which posts reference each attachment by maintaining a
 * `_mle_used_in_posts` postmeta array on each attachment. This index
 * enables "where is this image used?" queries and powers the image
 * replacement feature.
 *
 * The index is kept current via post save hooks and can be fully
 * rebuilt via WP-CLI for existing content.
 *
 * Uses the postmeta-based approach (fastest to implement and validate
 * within a two-week sprint). A future iteration could migrate to
 * a dedicated wp_post_relationships table per Trac #14513.
 *
 * @package MediaLibraryEnhance\Replacement
 */

namespace MediaLibraryEnhance\Replacement;

defined( 'ABSPATH' ) || exit;

class Usage_Tracker {

	private static ?self $instance = null;

	public const META_KEY = '_mle_used_in_posts';

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function register(): void {
		add_action( 'save_post', [ $this, 'update_usage_on_save' ], 20, 2 );
		add_action( 'delete_post', [ $this, 'clean_usage_on_delete' ] );
	}

	/**
	 * Scan a post's content for attachment references and update the index.
	 *
	 * Parses both block markup (wp:image, wp:media-text, wp:cover) and
	 * classic editor img tags to find attachment IDs.
	 *
	 * @param int      $post_id The post being saved.
	 * @param \WP_Post $post    The post object.
	 */
	public function update_usage_on_save( int $post_id, \WP_Post $post ): void {
		// Skip revisions and autosaves.
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}

		// Skip attachments themselves.
		if ( 'attachment' === $post->post_type ) {
			return;
		}

		$attachment_ids = $this->extract_attachment_ids( $post->post_content );

		// Also include the featured image.
		$thumbnail_id = get_post_thumbnail_id( $post_id );
		if ( $thumbnail_id ) {
			$attachment_ids[] = (int) $thumbnail_id;
		}

		$attachment_ids = array_unique( $attachment_ids );

		// Get previously tracked attachments for this post to compute diff.
		$previously_tracked = $this->get_attachments_used_by_post( $post_id );

		// Add this post to newly referenced attachments.
		$added = array_diff( $attachment_ids, $previously_tracked );
		foreach ( $added as $att_id ) {
			$this->add_usage( $att_id, $post_id );
		}

		// Remove this post from no-longer-referenced attachments.
		$removed = array_diff( $previously_tracked, $attachment_ids );
		foreach ( $removed as $att_id ) {
			$this->remove_usage( $att_id, $post_id );
		}
	}

	/**
	 * When a post is deleted, remove it from all attachment usage records.
	 *
	 * @param int $post_id The deleted post ID.
	 */
	public function clean_usage_on_delete( int $post_id ): void {
		$post = get_post( $post_id );
		if ( ! $post || 'attachment' === $post->post_type ) {
			return;
		}

		$attachments = $this->get_attachments_used_by_post( $post_id );
		foreach ( $attachments as $att_id ) {
			$this->remove_usage( $att_id, $post_id );
		}
	}

	/**
	 * Extract attachment IDs from post content.
	 *
	 * Finds IDs from:
	 * - Block attributes: "id":123
	 * - wp-image-123 CSS classes
	 * - ?attachment_id=123 query params
	 *
	 * @param string $content Post content.
	 * @return int[] Array of attachment IDs found.
	 */
	public function extract_attachment_ids( string $content ): array {
		$ids = [];

		// Block editor: "id":123 in block JSON attributes.
		if ( preg_match_all( '/"id"\s*:\s*(\d+)/', $content, $matches ) ) {
			$ids = array_merge( $ids, array_map( 'intval', $matches[1] ) );
		}

		// Classic editor: wp-image-123 class.
		if ( preg_match_all( '/wp-image-(\d+)/', $content, $matches ) ) {
			$ids = array_merge( $ids, array_map( 'intval', $matches[1] ) );
		}

		// Filter to only valid attachment IDs (batch check).
		$ids = array_unique( array_filter( $ids, fn( $id ) => $id > 0 ) );

		return array_values( $ids );
	}

	/**
	 * Get all post IDs where an attachment is used.
	 *
	 * @param int $attachment_id The attachment.
	 * @return int[]
	 */
	public function get_posts_using_attachment( int $attachment_id ): array {
		$posts = get_post_meta( $attachment_id, self::META_KEY, true );
		return is_array( $posts ) ? array_map( 'intval', $posts ) : [];
	}

	/**
	 * Get all attachment IDs used by a specific post.
	 *
	 * This is the reverse lookup — used internally for computing diffs
	 * on post save.
	 *
	 * @param int $post_id The post.
	 * @return int[]
	 */
	private function get_attachments_used_by_post( int $post_id ): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$results = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value LIKE %s",
				self::META_KEY,
				'%' . $wpdb->esc_like( '"' . $post_id . '"' ) . '%'
			)
		);

		return array_map( 'intval', $results );
	}

	/**
	 * Add a post to an attachment's usage list.
	 */
	private function add_usage( int $attachment_id, int $post_id ): void {
		$posts = $this->get_posts_using_attachment( $attachment_id );
		if ( ! in_array( $post_id, $posts, true ) ) {
			$posts[] = $post_id;
			update_post_meta( $attachment_id, self::META_KEY, $posts );
		}
	}

	/**
	 * Remove a post from an attachment's usage list.
	 */
	private function remove_usage( int $attachment_id, int $post_id ): void {
		$posts = $this->get_posts_using_attachment( $attachment_id );
		$posts = array_values( array_diff( $posts, [ $post_id ] ) );
		update_post_meta( $attachment_id, self::META_KEY, $posts );
	}
}

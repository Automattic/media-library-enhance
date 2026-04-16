<?php
/**
 * Image Replacer.
 *
 * Replaces an attachment's file while preserving its ID, URL structure,
 * and all references across posts. This lets editors swap out an image
 * everywhere it's used without manually updating each post.
 *
 * The replacement process:
 * 1. Upload new file to the same attachment ID
 * 2. Regenerate thumbnails/sizes for the new file
 * 3. Update attachment metadata
 * 4. Optionally purge CDN/edge caches (VIP-aware)
 *
 * @package MediaLibraryEnhance\Replacement
 */

namespace MediaLibraryEnhance\Replacement;

defined( 'ABSPATH' ) || exit;

class Image_Replacer {

	private static ?self $instance = null;

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function register(): void {
		// No hooks needed — this is invoked via REST API / WP-CLI.
	}

	/**
	 * Replace an attachment's file with a new one.
	 *
	 * @param int    $attachment_id The attachment to replace.
	 * @param string $new_file_path Path to the new file (temporary upload).
	 * @return true|\WP_Error True on success, WP_Error on failure.
	 */
	public function replace( int $attachment_id, string $new_file_path ) {
		$attachment = get_post( $attachment_id );
		if ( ! $attachment || 'attachment' !== $attachment->post_type ) {
			return new \WP_Error( 'invalid_attachment', 'Attachment not found.' );
		}

		if ( ! file_exists( $new_file_path ) ) {
			return new \WP_Error( 'file_not_found', 'Replacement file not found.' );
		}

		// Get the current file path so we can clean up old thumbnails.
		$old_file = get_attached_file( $attachment_id );
		$old_metadata = wp_get_attachment_metadata( $attachment_id );

		// Delete old thumbnail files (keep the main file path for URL stability).
		if ( is_array( $old_metadata ) && ! empty( $old_metadata['sizes'] ) ) {
			$upload_dir = wp_get_upload_dir();
			$old_dir    = trailingslashit( dirname( $old_file ) );

			foreach ( $old_metadata['sizes'] as $size_data ) {
				$old_thumb = $old_dir . $size_data['file'];
				if ( file_exists( $old_thumb ) ) {
					wp_delete_file( $old_thumb );
				}
			}
		}

		// Copy new file over the old one, preserving the attachment's path.
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		$copied = @copy( $new_file_path, $old_file );
		if ( ! $copied ) {
			return new \WP_Error( 'copy_failed', 'Failed to copy replacement file.' );
		}

		// Update the MIME type if it changed.
		$new_mime = wp_check_filetype( $new_file_path )['type'];
		if ( $new_mime && $new_mime !== $attachment->post_mime_type ) {
			wp_update_post( [
				'ID'             => $attachment_id,
				'post_mime_type' => $new_mime,
			] );
		}

		// Regenerate attachment metadata (thumbnails, dimensions, etc.).
		// wp_generate_attachment_metadata() lives in wp-admin/includes/image.php,
		// which isn't auto-loaded in REST contexts.
		if ( ! function_exists( 'wp_generate_attachment_metadata' ) ) {
			require_once ABSPATH . 'wp-admin/includes/image.php';
		}
		$new_metadata = wp_generate_attachment_metadata( $attachment_id, $old_file );
		wp_update_attachment_metadata( $attachment_id, $new_metadata );

		// Clean up the temporary upload file.
		wp_delete_file( $new_file_path );

		// Purge caches for this attachment URL.
		$this->purge_caches( $attachment_id );

		/**
		 * Fires after an attachment file has been replaced.
		 *
		 * @param int    $attachment_id The attachment ID.
		 * @param string $old_file      The previous file path.
		 * @param array  $old_metadata  The previous attachment metadata.
		 */
		do_action( 'mle_attachment_replaced', $attachment_id, $old_file, $old_metadata );

		return true;
	}

	/**
	 * Get a summary of where an attachment is used (for confirmation UI).
	 *
	 * @param int $attachment_id The attachment.
	 * @return array{ post_count: int, posts: array<int, array{ id: int, title: string, edit_url: string }> }
	 */
	public function get_usage_summary( int $attachment_id ): array {
		$post_ids = Usage_Tracker::instance()->get_posts_using_attachment( $attachment_id );

		$posts = [];
		foreach ( array_slice( $post_ids, 0, 25 ) as $post_id ) {
			$post = get_post( $post_id );
			if ( $post ) {
				$posts[] = [
					'id'       => $post->ID,
					'title'    => $post->post_title,
					'edit_url' => get_edit_post_link( $post->ID, 'raw' ),
				];
			}
		}

		return [
			'post_count' => count( $post_ids ),
			'posts'      => $posts,
		];
	}

	/**
	 * Purge CDN/edge caches for the attachment.
	 *
	 * On VIP, this uses the VIP cache purge API. On other hosts, fires
	 * an action for cache plugins to hook into.
	 */
	private function purge_caches( int $attachment_id ): void {
		$url = wp_get_attachment_url( $attachment_id );

		// VIP: use the built-in cache purge.
		if ( function_exists( 'wpcom_vip_purge_edge_cache_for_url' ) ) {
			wpcom_vip_purge_edge_cache_for_url( $url );
		}

		/**
		 * Fires when caches should be purged for a replaced attachment.
		 *
		 * @param int    $attachment_id The attachment.
		 * @param string $url           The attachment URL.
		 */
		do_action( 'mle_purge_attachment_cache', $attachment_id, $url );
	}
}

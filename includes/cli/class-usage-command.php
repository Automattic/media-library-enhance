<?php
/**
 * WP-CLI Usage Tracking Commands.
 *
 * Commands for building and managing the attachment usage index:
 * - `wp mle usage rebuild` — Scan all posts and rebuild the usage index
 * - `wp mle usage show`    — Show where a specific attachment is used
 * - `wp mle usage stats`   — Summary statistics of the usage index
 *
 * @package MediaLibraryEnhance\CLI
 */

namespace MediaLibraryEnhance\CLI;

use MediaLibraryEnhance\Replacement\Usage_Tracker;

defined( 'ABSPATH' ) || exit;

class Usage_Command {

	public static function register_command(): void {
		\WP_CLI::add_command( 'mle usage', self::class );
	}

	/**
	 * Rebuild the usage index by scanning all post content.
	 *
	 * Processes posts in batches to handle large sites without
	 * running out of memory.
	 *
	 * ## OPTIONS
	 *
	 * [--batch-size=<number>]
	 * : Posts to process per batch.
	 * ---
	 * default: 100
	 * ---
	 *
	 * [--dry-run]
	 * : Report what would be indexed without writing.
	 *
	 * ## EXAMPLES
	 *
	 *     wp mle usage rebuild
	 *     wp mle usage rebuild --batch-size=50 --dry-run
	 *
	 * @subcommand rebuild
	 */
	public function rebuild( array $args, array $assoc_args ): void {
		$batch_size = (int) ( $assoc_args['batch-size'] ?? 100 );
		$dry_run    = isset( $assoc_args['dry-run'] );

		if ( $dry_run ) {
			\WP_CLI::log( '--- DRY RUN ---' );
		}

		$tracker = Usage_Tracker::instance();

		// Count total published posts (excluding attachments).
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$total = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$wpdb->posts}
			WHERE post_status = 'publish' AND post_type NOT IN ('attachment', 'revision')"
		);

		$progress = \WP_CLI\Utils\make_progress_bar( 'Scanning posts for attachment references', $total );
		$offset   = 0;
		$found    = 0;

		while ( $offset < $total ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$posts = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT ID, post_content FROM {$wpdb->posts}
					WHERE post_status = 'publish' AND post_type NOT IN ('attachment', 'revision')
					ORDER BY ID ASC LIMIT %d OFFSET %d",
					$batch_size,
					$offset
				)
			);

			foreach ( $posts as $post ) {
				$ids = $tracker->extract_attachment_ids( $post->post_content );

				// Also check featured image.
				$thumb_id = get_post_thumbnail_id( (int) $post->ID );
				if ( $thumb_id ) {
					$ids[] = (int) $thumb_id;
				}

				$ids = array_unique( $ids );

				if ( ! empty( $ids ) ) {
					$found += count( $ids );

					if ( ! $dry_run ) {
						foreach ( $ids as $att_id ) {
							$existing = $tracker->get_posts_using_attachment( $att_id );
							if ( ! in_array( (int) $post->ID, $existing, true ) ) {
								$existing[] = (int) $post->ID;
								update_post_meta( $att_id, Usage_Tracker::META_KEY, $existing );
							}
						}
					}
				}

				$progress->tick();
			}

			$offset += $batch_size;

			// Free memory between batches.
			$this->stop_the_insanity();
		}

		$progress->finish();

		\WP_CLI::success( sprintf(
			'%s %d attachment references across %d posts.',
			$dry_run ? 'Found' : 'Indexed',
			$found,
			$total
		) );
	}

	/**
	 * Show which posts use a specific attachment.
	 *
	 * ## OPTIONS
	 *
	 * <attachment_id>
	 * : The attachment ID to look up.
	 *
	 * ## EXAMPLES
	 *
	 *     wp mle usage show 12345
	 *
	 * @subcommand show
	 */
	public function show( array $args, array $assoc_args ): void {
		$attachment_id = (int) $args[0];
		$post          = get_post( $attachment_id );

		if ( ! $post || 'attachment' !== $post->post_type ) {
			\WP_CLI::error( "Attachment #{$attachment_id} not found." );
		}

		\WP_CLI::log( sprintf( 'Attachment: #%d "%s"', $attachment_id, $post->post_title ) );

		$post_ids = Usage_Tracker::instance()->get_posts_using_attachment( $attachment_id );

		if ( empty( $post_ids ) ) {
			\WP_CLI::log( 'Not used in any posts (index may need rebuilding).' );
			return;
		}

		$items = [];
		foreach ( $post_ids as $pid ) {
			$p = get_post( $pid );
			if ( $p ) {
				$items[] = [
					'ID'     => $p->ID,
					'Title'  => $p->post_title,
					'Type'   => $p->post_type,
					'Status' => $p->post_status,
				];
			}
		}

		\WP_CLI\Utils\format_items( 'table', $items, [ 'ID', 'Title', 'Type', 'Status' ] );
	}

	/**
	 * Free memory aggressively between batches.
	 *
	 * Standard VIP pattern for WP-CLI batch processing.
	 */
	private function stop_the_insanity(): void {
		global $wpdb, $wp_object_cache;

		$wpdb->queries = [];

		if ( is_object( $wp_object_cache ) ) {
			$wp_object_cache->group_ops      = [];
			$wp_object_cache->memcache_debug = [];
			$wp_object_cache->cache          = [];

			if ( method_exists( $wp_object_cache, '__remoteset' ) ) {
				$wp_object_cache->__remoteset();
			}
		}
	}
}

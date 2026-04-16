<?php
/**
 * WP-CLI Duplicate Detection Commands.
 *
 * Commands for hash generation and duplicate finding:
 * - `wp mle duplicates hash`   — Generate hashes for all unhashed attachments
 * - `wp mle duplicates scan`   — Find all duplicate groups in the library
 * - `wp mle duplicates check`  — Check a specific attachment for duplicates
 *
 * @package MediaLibraryEnhance\CLI
 */

namespace MediaLibraryEnhance\CLI;

use MediaLibraryEnhance\Duplicates\Duplicate_Finder;
use MediaLibraryEnhance\Duplicates\Hash_Generator;

defined( 'ABSPATH' ) || exit;

class Duplicates_Command {

	public static function register_command(): void {
		\WP_CLI::add_command( 'mle duplicates', self::class );
	}

	/**
	 * Generate file hashes for all attachments that don't have one.
	 *
	 * Processes in batches with memory cleanup for large libraries.
	 *
	 * ## OPTIONS
	 *
	 * [--batch-size=<number>]
	 * : Attachments to process per batch.
	 * ---
	 * default: 100
	 * ---
	 *
	 * [--type=<type>]
	 * : Hash type to generate: "file", "perceptual", or "all".
	 * ---
	 * default: all
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp mle duplicates hash
	 *     wp mle duplicates hash --batch-size=50 --type=file
	 *
	 * @subcommand hash
	 */
	public function hash( array $args, array $assoc_args ): void {
		$batch_size = (int) ( $assoc_args['batch-size'] ?? 100 );
		$type       = $assoc_args['type'] ?? 'all';

		$generator = Hash_Generator::instance();

		// Find attachments missing hashes.
		$meta_key = match ( $type ) {
			'file'       => Hash_Generator::FILE_HASH_META,
			'perceptual' => Hash_Generator::PERCEPTUAL_HASH_META,
			default      => Hash_Generator::FILE_HASH_META, // For "all", start with file hash missing.
		};

		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$total = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->posts} p
				LEFT JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = %s
				WHERE p.post_type = 'attachment' AND p.post_status = 'inherit' AND pm.meta_id IS NULL",
				$meta_key
			)
		);

		if ( 0 === $total ) {
			\WP_CLI::success( 'All attachments already have hashes.' );
			return;
		}

		$progress = \WP_CLI\Utils\make_progress_bar( "Generating {$type} hashes", $total );
		$offset   = 0;
		$hashed   = 0;
		$failed   = 0;

		while ( $offset < $total ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$attachments = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT p.ID FROM {$wpdb->posts} p
					LEFT JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = %s
					WHERE p.post_type = 'attachment' AND p.post_status = 'inherit' AND pm.meta_id IS NULL
					ORDER BY p.ID DESC LIMIT %d OFFSET %d",
					$meta_key,
					$batch_size,
					$offset
				)
			);

			foreach ( $attachments as $att_id ) {
				$file = get_attached_file( (int) $att_id );
				if ( ! $file || ! file_exists( $file ) ) {
					$failed++;
					$progress->tick();
					continue;
				}

				$generator->generate_and_store_hashes( (int) $att_id, $file );
				$hashed++;
				$progress->tick();
			}

			$offset += $batch_size;
			$this->stop_the_insanity();
		}

		$progress->finish();

		\WP_CLI::success( sprintf( 'Hashed %d attachments (%d failed/missing files).', $hashed, $failed ) );
	}

	/**
	 * Scan the library for all groups of exact duplicates.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 *   - csv
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp mle duplicates scan
	 *     wp mle duplicates scan --format=json
	 *
	 * @subcommand scan
	 */
	public function scan( array $args, array $assoc_args ): void {
		$format = $assoc_args['format'] ?? 'table';

		$finder = Duplicate_Finder::instance();
		$groups = [];

		foreach ( $finder->scan_all_exact_duplicates() as $group ) {
			$groups[] = [
				'Hash'        => substr( $group['hash'], 0, 12 ) . '...',
				'Count'       => count( $group['attachment_ids'] ),
				'IDs'         => implode( ', ', $group['attachment_ids'] ),
			];
		}

		if ( empty( $groups ) ) {
			\WP_CLI::success( 'No duplicate groups found.' );
			return;
		}

		\WP_CLI::log( sprintf( 'Found %d duplicate groups:', count( $groups ) ) );
		\WP_CLI\Utils\format_items( $format, $groups, [ 'Hash', 'Count', 'IDs' ] );
	}

	/**
	 * Check a specific attachment for duplicates.
	 *
	 * ## OPTIONS
	 *
	 * <attachment_id>
	 * : The attachment ID to check.
	 *
	 * [--similar]
	 * : Also check for visually similar images (perceptual hash).
	 *
	 * ## EXAMPLES
	 *
	 *     wp mle duplicates check 12345
	 *     wp mle duplicates check 12345 --similar
	 *
	 * @subcommand check
	 */
	public function check( array $args, array $assoc_args ): void {
		$attachment_id = (int) $args[0];
		$check_similar = isset( $assoc_args['similar'] );

		$finder = Duplicate_Finder::instance();

		// Exact duplicates.
		$exact = $finder->find_exact_duplicates( $attachment_id );
		if ( ! empty( $exact ) ) {
			\WP_CLI::log( sprintf( 'Exact duplicates of #%d:', $attachment_id ) );
			foreach ( $exact as $dup_id ) {
				$post = get_post( $dup_id );
				\WP_CLI::log( sprintf( '  #%d "%s" — %s', $dup_id, $post->post_title ?? '', wp_get_attachment_url( $dup_id ) ) );
			}
		} else {
			\WP_CLI::log( 'No exact duplicates found.' );
		}

		// Visually similar.
		if ( $check_similar ) {
			$similar = $finder->find_similar( $attachment_id );
			if ( ! empty( $similar ) ) {
				\WP_CLI::log( sprintf( "\nVisually similar to #%d:", $attachment_id ) );
				foreach ( $similar as $match ) {
					$post = get_post( $match['id'] );
					\WP_CLI::log( sprintf(
						'  #%d "%s" (distance: %d) — %s',
						$match['id'],
						$post->post_title ?? '',
						$match['distance'],
						wp_get_attachment_url( $match['id'] )
					) );
				}
			} else {
				\WP_CLI::log( 'No visually similar images found.' );
			}
		}
	}

	/**
	 * Free memory between batches.
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

<?php
/**
 * WP-CLI Taxonomy Management Commands.
 *
 * Commands for bulk taxonomy operations on media:
 * - `wp mle taxonomies assign`                       — Bulk assign terms to attachments
 * - `wp mle taxonomies stats`                        — Show taxonomy usage statistics
 * - `wp mle taxonomies migrate-categories-to-tags`   — Convert media_category terms to media_tag
 *
 * @package MediaLibraryEnhance\CLI
 */

namespace MediaLibraryEnhance\CLI;

use MediaLibraryEnhance\Taxonomies\Media_Taxonomies;

defined( 'ABSPATH' ) || exit;

class Taxonomies_Command {

	/**
	 * Legacy taxonomy slug from the dual-taxonomy scaffold.
	 *
	 * Only the migration subcommand should reference this. The taxonomy
	 * itself is no longer registered — `Media_Taxonomies` ships tags only.
	 */
	private const LEGACY_CATEGORY_TAXONOMY = 'media_category';

	public static function register_command(): void {
		\WP_CLI::add_command( 'mle taxonomies', self::class );
	}

	/**
	 * Bulk assign a taxonomy term to attachments matching a filter.
	 *
	 * ## OPTIONS
	 *
	 * <taxonomy>
	 * : The taxonomy (currently only media_tag).
	 *
	 * <term>
	 * : Term slug to assign.
	 *
	 * [--mime-type=<mime>]
	 * : Filter by MIME type (e.g. image/jpeg).
	 *
	 * [--date-before=<date>]
	 * : Only attachments uploaded before this date (Y-m-d).
	 *
	 * [--date-after=<date>]
	 * : Only attachments uploaded after this date (Y-m-d).
	 *
	 * [--batch-size=<number>]
	 * : Attachments per batch.
	 * ---
	 * default: 100
	 * ---
	 *
	 * [--dry-run]
	 * : Report what would be assigned without writing.
	 *
	 * ## EXAMPLES
	 *
	 *     wp mle taxonomies assign media_tag photos --mime-type=image/jpeg
	 *     wp mle taxonomies assign media_tag legacy --date-before=2020-01-01 --dry-run
	 *
	 * @subcommand assign
	 */
	public function assign( array $args, array $assoc_args ): void {
		$taxonomy   = $args[0];
		$term_slug  = $args[1];
		$batch_size = (int) ( $assoc_args['batch-size'] ?? 100 );
		$dry_run    = isset( $assoc_args['dry-run'] );

		// Validate taxonomy.
		$valid = [ Media_Taxonomies::TAG_TAXONOMY ];
		if ( ! in_array( $taxonomy, $valid, true ) ) {
			\WP_CLI::error( sprintf( 'Invalid taxonomy. Use: %s', implode( ', ', $valid ) ) );
		}

		// Get or create the term.
		$term = get_term_by( 'slug', $term_slug, $taxonomy );
		if ( ! $term ) {
			if ( $dry_run ) {
				\WP_CLI::log( sprintf( 'Term "%s" does not exist (would be created).', $term_slug ) );
			} else {
				$result = wp_insert_term( $term_slug, $taxonomy );
				if ( is_wp_error( $result ) ) {
					\WP_CLI::error( 'Failed to create term: ' . $result->get_error_message() );
				}
				$term = get_term( $result['term_id'], $taxonomy );
			}
		}

		// Build query args.
		$query_args = [
			'post_type'      => 'attachment',
			'post_status'    => 'inherit',
			'posts_per_page' => $batch_size,
			'fields'         => 'ids',
			'no_found_rows'  => false,
		];

		if ( ! empty( $assoc_args['mime-type'] ) ) {
			$query_args['post_mime_type'] = $assoc_args['mime-type'];
		}

		$date_query = [];
		if ( ! empty( $assoc_args['date-before'] ) ) {
			$date_query['before'] = $assoc_args['date-before'];
		}
		if ( ! empty( $assoc_args['date-after'] ) ) {
			$date_query['after'] = $assoc_args['date-after'];
		}
		if ( ! empty( $date_query ) ) {
			$query_args['date_query'] = [ $date_query ];
		}

		// First pass to count.
		$count_query = new \WP_Query( $query_args );
		$total       = $count_query->found_posts;

		if ( 0 === $total ) {
			\WP_CLI::success( 'No matching attachments found.' );
			return;
		}

		if ( $dry_run ) {
			\WP_CLI::success( sprintf( 'Would assign "%s" to %d attachments.', $term_slug, $total ) );
			return;
		}

		$progress = \WP_CLI\Utils\make_progress_bar( "Assigning {$term_slug}", $total );
		$assigned = 0;
		$page     = 1;

		while ( $assigned < $total ) {
			$query_args['paged'] = $page;
			$query               = new \WP_Query( $query_args );

			foreach ( $query->posts as $att_id ) {
				wp_set_object_terms( (int) $att_id, [ $term->term_id ], $taxonomy, true );
				$assigned++;
				$progress->tick();
			}

			$page++;

			if ( empty( $query->posts ) ) {
				break;
			}
		}

		$progress->finish();
		\WP_CLI::success( sprintf( 'Assigned "%s" to %d attachments.', $term_slug, $assigned ) );
	}

	/**
	 * Show taxonomy usage statistics for media.
	 *
	 * ## EXAMPLES
	 *
	 *     wp mle taxonomies stats
	 *
	 * @subcommand stats
	 */
	public function stats( array $args, array $assoc_args ): void {
		$terms = get_terms(
			[
				'taxonomy'   => Media_Taxonomies::TAG_TAXONOMY,
				'hide_empty' => false,
			]
		);

		if ( is_wp_error( $terms ) ) {
			\WP_CLI::error( 'media_tag taxonomy not registered.' );
		}

		\WP_CLI::log( "\nMedia Tags:" );
		\WP_CLI::log( sprintf( '  Terms: %d', count( $terms ) ) );

		if ( ! empty( $terms ) ) {
			$items = [];
			foreach ( $terms as $term ) {
				$items[] = [
					'Name'  => $term->name,
					'Slug'  => $term->slug,
					'Count' => $term->count,
				];
			}
			\WP_CLI\Utils\format_items( 'table', $items, [ 'Name', 'Slug', 'Count' ] );
		}

		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$total = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'attachment' AND post_status = 'inherit'"
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$tagged = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(DISTINCT tr.object_id) FROM {$wpdb->term_relationships} tr
				INNER JOIN {$wpdb->term_taxonomy} tt ON tr.term_taxonomy_id = tt.term_taxonomy_id
				WHERE tt.taxonomy = %s",
				Media_Taxonomies::TAG_TAXONOMY
			)
		);

		\WP_CLI::log( sprintf( "\nTotal attachments: %s", number_format( $total ) ) );
		\WP_CLI::log( sprintf( 'Tagged: %s (%.1f%%)', number_format( $tagged ), $total > 0 ? ( $tagged / $total * 100 ) : 0 ) );
		\WP_CLI::log( sprintf( 'Untagged: %s', number_format( $total - $tagged ) ) );
	}

	/**
	 * Migrate media_category terms to media_tag terms.
	 *
	 * One-time data migration for sites upgrading from the dual-taxonomy
	 * scaffold to the tags-only direction. Each category becomes a tag
	 * with the same slug; every attachment that had the category gets
	 * the equivalent tag (appended — existing tags are preserved).
	 *
	 * Run `--dry-run` first to preview the impact. Pass
	 * `--delete-categories` only after confirming the tag side looks
	 * right, since deletion is irreversible.
	 *
	 * ## OPTIONS
	 *
	 * [--dry-run]
	 * : Report what would change without writing.
	 *
	 * [--delete-categories]
	 * : Delete media_category terms after successful migration.
	 *
	 * [--batch-size=<number>]
	 * : Attachments per batch (memory boundary).
	 * ---
	 * default: 200
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp mle taxonomies migrate-categories-to-tags --dry-run
	 *     wp mle taxonomies migrate-categories-to-tags --delete-categories
	 *
	 * @subcommand migrate-categories-to-tags
	 */
	public function migrate_categories_to_tags( array $args, array $assoc_args ): void {
		$dry_run            = isset( $assoc_args['dry-run'] );
		$delete_categories  = isset( $assoc_args['delete-categories'] );
		$batch_size         = max( 1, (int) ( $assoc_args['batch-size'] ?? 200 ) );

		// The category taxonomy may already be unregistered (Step 7 cleanup).
		// In that case there's nothing to migrate — exit cleanly.
		if ( ! taxonomy_exists( self::LEGACY_CATEGORY_TAXONOMY ) ) {
			\WP_CLI::success( 'media_category is not registered — nothing to migrate.' );
			return;
		}

		$categories = get_terms(
			[
				'taxonomy'   => self::LEGACY_CATEGORY_TAXONOMY,
				'hide_empty' => false,
			]
		);

		if ( is_wp_error( $categories ) || empty( $categories ) ) {
			\WP_CLI::success( 'No media_category terms to migrate.' );
			return;
		}

		$categories_migrated = 0;
		$tags_created        = 0;
		$attachments_updated = 0;

		foreach ( $categories as $category ) {
			$tag_term_id = $this->get_or_create_tag_for_category( $category, $dry_run, $tags_created );
			if ( ! $tag_term_id && ! $dry_run ) {
				\WP_CLI::warning( sprintf( 'Skipping category "%s" — could not resolve a tag.', $category->slug ) );
				continue;
			}

			$attachment_ids = get_objects_in_term( $category->term_id, self::LEGACY_CATEGORY_TAXONOMY );
			if ( is_wp_error( $attachment_ids ) || empty( $attachment_ids ) ) {
				$categories_migrated++;
				continue;
			}

			$attachment_ids = array_map( 'intval', $attachment_ids );
			$total          = count( $attachment_ids );
			$progress       = \WP_CLI\Utils\make_progress_bar(
				sprintf( 'Migrating "%s" → tag', $category->slug ),
				$total
			);

			$processed = 0;
			foreach ( array_chunk( $attachment_ids, $batch_size ) as $chunk ) {
				foreach ( $chunk as $attachment_id ) {
					if ( ! $dry_run ) {
						wp_set_object_terms(
							$attachment_id,
							[ $tag_term_id ],
							Media_Taxonomies::TAG_TAXONOMY,
							true
						);
					}
					$attachments_updated++;
					$processed++;
					$progress->tick();
				}

				// Per CLAUDE.md landmine — VIP object cache OOMs without this.
				if ( function_exists( 'stop_the_insanity' ) ) {
					stop_the_insanity();
				}
			}

			$progress->finish();
			$categories_migrated++;
		}

		if ( $delete_categories && ! $dry_run ) {
			foreach ( $categories as $category ) {
				wp_delete_term( $category->term_id, self::LEGACY_CATEGORY_TAXONOMY );
			}
			\WP_CLI::log( sprintf( 'Deleted %d media_category terms.', count( $categories ) ) );
		}

		\WP_CLI\Utils\format_items(
			'table',
			[
				[
					'Categories migrated' => $categories_migrated,
					'Tags created'        => $tags_created,
					'Attachments updated' => $attachments_updated,
					'Mode'                => $dry_run ? 'dry-run' : 'live',
				],
			],
			[ 'Categories migrated', 'Tags created', 'Attachments updated', 'Mode' ]
		);

		\WP_CLI::success(
			$dry_run
				? 'Dry run complete — no data was modified.'
				: 'Migration complete.'
		);
	}

	/**
	 * Find an existing media_tag with the category's slug, or create one.
	 *
	 * Slug collisions on a different taxonomy are extremely rare in
	 * practice, but if they happen we append the category's term ID to
	 * disambiguate.
	 *
	 * @param \WP_Term $category     The category term to mirror.
	 * @param bool     $dry_run      Whether to write or just report.
	 * @param int      $tags_created Counter passed by reference.
	 * @return int|null The tag term ID, or null in dry-run when missing.
	 */
	private function get_or_create_tag_for_category(
		\WP_Term $category,
		bool $dry_run,
		int &$tags_created
	): ?int {
		$existing = get_term_by( 'slug', $category->slug, Media_Taxonomies::TAG_TAXONOMY );
		if ( $existing instanceof \WP_Term ) {
			return (int) $existing->term_id;
		}

		if ( $dry_run ) {
			$tags_created++;
			\WP_CLI::log( sprintf( 'Would create tag "%s" (from category #%d).', $category->slug, $category->term_id ) );
			return null;
		}

		$args = [ 'slug' => $category->slug ];
		$result = wp_insert_term( $category->name, Media_Taxonomies::TAG_TAXONOMY, $args );

		if ( is_wp_error( $result ) && 'term_exists' === $result->get_error_code() ) {
			// Slug collision — append the category ID and try again.
			$args['slug'] = $category->slug . '-' . $category->term_id;
			$result       = wp_insert_term( $category->name, Media_Taxonomies::TAG_TAXONOMY, $args );
			\WP_CLI::warning(
				sprintf(
					'Slug "%s" was taken; created "%s" instead.',
					$category->slug,
					$args['slug']
				)
			);
		}

		if ( is_wp_error( $result ) ) {
			\WP_CLI::warning(
				sprintf( 'Failed to create tag for category "%s": %s', $category->slug, $result->get_error_message() )
			);
			return null;
		}

		$tags_created++;
		return (int) $result['term_id'];
	}
}

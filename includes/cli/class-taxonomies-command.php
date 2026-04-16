<?php
/**
 * WP-CLI Taxonomy Management Commands.
 *
 * Commands for bulk taxonomy operations on media:
 * - `wp mle taxonomies assign`  — Bulk assign terms to attachments
 * - `wp mle taxonomies migrate` — Import terms from third-party plugins
 * - `wp mle taxonomies stats`   — Show taxonomy usage statistics
 *
 * @package MediaLibraryEnhance\CLI
 */

namespace MediaLibraryEnhance\CLI;

use MediaLibraryEnhance\Taxonomies\Media_Taxonomies;

defined( 'ABSPATH' ) || exit;

class Taxonomies_Command {

	public static function register_command(): void {
		\WP_CLI::add_command( 'mle taxonomies', self::class );
	}

	/**
	 * Bulk assign a taxonomy term to attachments matching a filter.
	 *
	 * ## OPTIONS
	 *
	 * <taxonomy>
	 * : The taxonomy (media_category or media_tag).
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
	 *     wp mle taxonomies assign media_category photos --mime-type=image/jpeg
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
		$valid = [ Media_Taxonomies::CATEGORY_TAXONOMY, Media_Taxonomies::TAG_TAXONOMY ];
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
		$taxonomies = [
			Media_Taxonomies::CATEGORY_TAXONOMY => 'Media Categories',
			Media_Taxonomies::TAG_TAXONOMY      => 'Media Tags',
		];

		foreach ( $taxonomies as $tax => $label ) {
			$terms = get_terms( [
				'taxonomy'   => $tax,
				'hide_empty' => false,
			] );

			if ( is_wp_error( $terms ) ) {
				\WP_CLI::warning( "{$label}: taxonomy not registered." );
				continue;
			}

			\WP_CLI::log( sprintf( "\n%s:", $label ) );
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
		}

		// Count untagged attachments.
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$total = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'attachment' AND post_status = 'inherit'"
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$categorized = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(DISTINCT tr.object_id) FROM {$wpdb->term_relationships} tr
				INNER JOIN {$wpdb->term_taxonomy} tt ON tr.term_taxonomy_id = tt.term_taxonomy_id
				WHERE tt.taxonomy = %s",
				Media_Taxonomies::CATEGORY_TAXONOMY
			)
		);

		\WP_CLI::log( sprintf( "\nTotal attachments: %s", number_format( $total ) ) );
		\WP_CLI::log( sprintf( 'Categorized: %s (%.1f%%)', number_format( $categorized ), $total > 0 ? ( $categorized / $total * 100 ) : 0 ) );
		\WP_CLI::log( sprintf( 'Uncategorized: %s', number_format( $total - $categorized ) ) );
	}
}

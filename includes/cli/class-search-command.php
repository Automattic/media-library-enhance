<?php
/**
 * WP-CLI Search Commands.
 *
 * Commands for managing the Elasticsearch integration:
 * - `wp mle search status`  — Check ES availability and index health
 * - `wp mle search test`    — Run a test query and compare ES vs MySQL timing
 * - `wp mle search reindex` — Trigger a full reindex of attachments (VIP)
 *
 * @package MediaLibraryEnhance\CLI
 */

namespace MediaLibraryEnhance\CLI;

use MediaLibraryEnhance\Search\Elasticsearch_Query;

defined( 'ABSPATH' ) || exit;

class Search_Command {

	public static function register_command(): void {
		\WP_CLI::add_command( 'mle search', self::class );
	}

	/**
	 * Check Elasticsearch availability and configuration.
	 *
	 * ## EXAMPLES
	 *
	 *     wp mle search status
	 *
	 * @subcommand status
	 */
	public function status( array $args, array $assoc_args ): void {
		$es = Elasticsearch_Query::instance();

		if ( $es->is_es_available() ) {
			\WP_CLI::success( 'Elasticsearch is available.' );

			// Check VIP-specific configuration.
			if ( defined( 'VIP_ENABLE_VIP_SEARCH' ) && VIP_ENABLE_VIP_SEARCH ) {
				\WP_CLI::log( 'Provider: WordPress VIP Enterprise Search' );
			}
		} else {
			\WP_CLI::warning( 'Elasticsearch is NOT available. Media searches will use MySQL.' );
			\WP_CLI::log( 'To enable on VIP: ensure VIP_ENABLE_VIP_SEARCH is true and protected_content is enabled.' );
		}

		// Count total attachments.
		$count = wp_count_posts( 'attachment' );
		$total = (int) $count->inherit + (int) $count->private;
		\WP_CLI::log( sprintf( 'Total attachments: %s', number_format( $total ) ) );
	}

	/**
	 * Run a test search query and report timing.
	 *
	 * ## OPTIONS
	 *
	 * <search_term>
	 * : The term to search for.
	 *
	 * [--per-page=<number>]
	 * : Results per page.
	 * ---
	 * default: 40
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp mle search test "company logo"
	 *     wp mle search test "annual report" --per-page=20
	 *
	 * @subcommand test
	 */
	public function test( array $args, array $assoc_args ): void {
		$search_term = $args[0];
		$per_page    = (int) ( $assoc_args['per-page'] ?? 40 );

		$start = microtime( true );

		$query = new \WP_Query( [
			'post_type'      => 'attachment',
			'post_status'    => 'inherit',
			's'              => $search_term,
			'posts_per_page' => $per_page,
		] );

		$elapsed = microtime( true ) - $start;

		$engine = Elasticsearch_Query::instance()->is_es_available() ? 'Elasticsearch' : 'MySQL';

		\WP_CLI::log( sprintf( 'Search engine: %s', $engine ) );
		\WP_CLI::log( sprintf( 'Query: "%s"', $search_term ) );
		\WP_CLI::log( sprintf( 'Results: %d (of %d total)', $query->post_count, $query->found_posts ) );
		\WP_CLI::log( sprintf( 'Time: %.3f seconds', $elapsed ) );

		if ( $elapsed > 2.0 ) {
			\WP_CLI::warning( 'Query exceeded 2-second target.' );
		} else {
			\WP_CLI::success( sprintf( 'Query completed in %.3fs (under 2s target).', $elapsed ) );
		}
	}
}

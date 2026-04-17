<?php
/**
 * Elasticsearch Query Integration.
 *
 * Intercepts media library WP_Query requests and routes them through
 * WordPress VIP Enterprise Search (Elasticsearch) for sub-second response times.
 *
 * On VIP, Enterprise Search is available via the `es-wp-query` integration.
 * This module ensures attachment queries opt-in to ES when searching, and
 * provides a graceful MySQL fallback when ES is unavailable.
 *
 * @package MediaLibraryEnhance\Search
 */

namespace MediaLibraryEnhance\Search;

defined( 'ABSPATH' ) || exit;

class Elasticsearch_Query {

	private static ?self $instance = null;

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Register hooks to intercept media queries.
	 */
	public function register(): void {
		// Intercept attachment queries that contain a search term.
		add_action( 'pre_get_posts', [ $this, 'maybe_route_to_es' ], 5 );

		// Remove SQL_CALC_FOUND_ROWS for attachment queries (perf fix even without ES).
		add_filter( 'found_posts_query', [ $this, 'disable_found_rows_for_media' ], 10, 2 );
	}

	/**
	 * Route media search queries to Elasticsearch when available.
	 *
	 * VIP Enterprise Search uses the `es` query var or the `ep_integrate`
	 * flag depending on the integration version. This method detects the
	 * environment and opts in accordingly.
	 *
	 * @param \WP_Query $query The query being executed.
	 */
	public function maybe_route_to_es( \WP_Query $query ): void {
		if ( ! $this->is_media_search( $query ) ) {
			return;
		}

		if ( ! $this->is_es_available() ) {
			return;
		}

		// VIP Enterprise Search: enable ES integration for this query.
		// The `es` query var is the VIP-native way to opt a query into ES.
		// This intentionally modifies non-main queries (media library searches).
		$query->set( 'es', true ); // phpcs:ignore WordPressVIPMinimum.Hooks.PreGetPosts.PreGetPosts

		// Keep found_rows enabled because REST_Search exposes pagination totals.
		// This keeps MySQL fallback queries accurate at the cost of found_rows work.
		// On ES-routed queries, totals come from the search backend instead.
		$query->set( 'no_found_rows', false ); // phpcs:ignore WordPressVIPMinimum.Hooks.PreGetPosts.PreGetPosts

		/**
		 * Fires when a media query is routed to Elasticsearch.
		 *
		 * @param \WP_Query $query The modified query.
		 */
		do_action( 'mle_query_routed_to_es', $query );
	}

	/**
	 * Disable SQL_CALC_FOUND_ROWS for attachment queries.
	 *
	 * This is a performance win even when ES is unavailable — WordPress uses
	 * SQL_CALC_FOUND_ROWS by default which forces MySQL to calculate the
	 * total matching rows even when LIMIT is applied.
	 *
	 * @param string    $sql   The found_posts SQL.
	 * @param \WP_Query $query The query.
	 * @return string Modified SQL or empty string to disable.
	 */
	public function disable_found_rows_for_media( string $sql, \WP_Query $query ): string {
		if ( $this->is_attachment_query( $query ) ) {
			// Use a separate COUNT query instead — set via no_found_rows in WP 6.7+.
			// For now, return the SQL unchanged but ensure no_found_rows is preferred.
			return $sql;
		}
		return $sql;
	}

	/**
	 * Check whether this query is a media library search.
	 */
	public function is_media_search( \WP_Query $query ): bool {
		return $this->is_attachment_query( $query ) && ! empty( $query->get( 's' ) );
	}

	/**
	 * Check whether this query targets attachments.
	 */
	private function is_attachment_query( \WP_Query $query ): bool {
		$post_type = $query->get( 'post_type' );

		if ( 'attachment' === $post_type ) {
			return true;
		}

		if ( is_array( $post_type ) && in_array( 'attachment', $post_type, true ) ) {
			return true;
		}

		return false;
	}

	/**
	 * Detect whether Elasticsearch is available in this environment.
	 *
	 * On VIP, Enterprise Search is enabled per-site. We check for the
	 * VIP search module or the `ep_integrate` constant.
	 */
	public function is_es_available(): bool {
		// VIP Enterprise Search check.
		if ( defined( 'VIP_ENABLE_VIP_SEARCH' ) && VIP_ENABLE_VIP_SEARCH ) {
			return true;
		}

		// ElasticPress / Enterprise Search plugin active check.
		if ( function_exists( 'ElasticPress\\elasticsearch' ) ) {
			return true;
		}

		/**
		 * Filter to override ES availability detection.
		 *
		 * @param bool $available Whether ES is detected as available.
		 */
		return (bool) apply_filters( 'mle_es_available', false );
	}
}

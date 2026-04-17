<?php
/**
 * REST API Search Endpoint.
 *
 * Provides a dedicated /mle/v1/search endpoint that returns media results
 * via Elasticsearch with hydrated attachment data. This endpoint is used
 * by both the admin media modal integration and external consumers.
 *
 * @package MediaLibraryEnhance\Search
 */

namespace MediaLibraryEnhance\Search;

defined( 'ABSPATH' ) || exit;

class REST_Search {

	private static ?self $instance = null;

	public const NAMESPACE = 'mle/v1';

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function register(): void {
		add_action( 'rest_api_init', [ $this, 'register_routes' ] );
	}

	public function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/search',
			[
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => [ $this, 'handle_search' ],
				'permission_callback' => [ $this, 'check_permissions' ],
				'args'                => $this->get_search_args(),
			]
		);
	}

	/**
	 * Handle a media search request.
	 *
	 * Queries attachments via WP_Query (which will be routed to ES by
	 * Elasticsearch_Query::maybe_route_to_es if ES is available).
	 *
	 * @param \WP_REST_Request $request The request object.
	 * @return \WP_REST_Response
	 */
	public function handle_search( \WP_REST_Request $request ): \WP_REST_Response {
		$query_args = [
			'post_type'      => 'attachment',
			'post_status'    => 'inherit',
			's'              => $request->get_param( 'search' ),
			'posts_per_page' => $request->get_param( 'per_page' ) ?? 40,
			'paged'          => $request->get_param( 'page' ) ?? 1,
			'orderby'        => $request->get_param( 'orderby' ) ?? 'date',
			'order'          => $request->get_param( 'order' ) ?? 'DESC',
		];

		// Filter by mime type if specified.
		$mime_type = $request->get_param( 'mime_type' );
		if ( $mime_type ) {
			$query_args['post_mime_type'] = $mime_type;
		}

		// Filter by taxonomy terms if specified.
		$media_tag = $request->get_param( 'media_tag' );
		if ( $media_tag ) {
			$query_args['tax_query'] = [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
				[
					'taxonomy' => 'media_tag',
					'field'    => 'slug',
					'terms'    => $media_tag,
				],
			];
		}

		$query = new \WP_Query( $query_args );

		$attachments = array_map(
			[ $this, 'prepare_attachment' ],
			$query->posts
		);

		$response = new \WP_REST_Response( $attachments );

		$response->header( 'X-WP-Total', $query->found_posts );
		$response->header( 'X-WP-TotalPages', $query->max_num_pages );
		$response->header( 'X-MLE-Search-Engine', Elasticsearch_Query::instance()->is_es_available() ? 'elasticsearch' : 'mysql' );

		return $response;
	}

	/**
	 * Prepare an attachment post for the REST response.
	 *
	 * Returns a lean payload optimized for the media modal — not the full
	 * wp/v2/media schema, which is expensive to hydrate at scale.
	 *
	 * @param \WP_Post $post The attachment post.
	 * @return array<string, mixed>
	 */
	private function prepare_attachment( \WP_Post $post ): array {
		$metadata = wp_get_attachment_metadata( $post->ID );

		return [
			'id'        => $post->ID,
			'title'     => $post->post_title,
			'caption'   => $post->post_excerpt,
			'alt'       => get_post_meta( $post->ID, '_wp_attachment_image_alt', true ),
			'mime_type' => $post->post_mime_type,
			'url'       => wp_get_attachment_url( $post->ID ),
			'date'      => $post->post_date_gmt,
			'width'     => $metadata['width'] ?? null,
			'height'    => $metadata['height'] ?? null,
			'filesizeInBytes' => $metadata['filesize'] ?? null,
		];
	}

	public function check_permissions(): bool {
		return current_user_can( 'upload_files' );
	}

	/**
	 * Schema for search endpoint arguments.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function get_search_args(): array {
		return [
			'search'         => [
				'description' => 'Search term.',
				'type'        => 'string',
				'required'    => true,
				'sanitize_callback' => 'sanitize_text_field',
			],
			'per_page'       => [
				'description' => 'Results per page.',
				'type'        => 'integer',
				'default'     => 40,
				'minimum'     => 1,
				'maximum'     => 100,
			],
			'page'           => [
				'description' => 'Page number.',
				'type'        => 'integer',
				'default'     => 1,
				'minimum'     => 1,
			],
			'mime_type'      => [
				'description' => 'Filter by MIME type (e.g. image/jpeg).',
				'type'        => 'string',
				'sanitize_callback' => 'sanitize_mime_type',
			],
			'media_tag'      => [
				'description' => 'Filter by media tag slug.',
				'type'        => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			],
			'orderby'        => [
				'description' => 'Sort field.',
				'type'        => 'string',
				'default'     => 'date',
				'enum'        => [ 'date', 'title', 'relevance', 'modified' ],
			],
			'order'          => [
				'description' => 'Sort direction.',
				'type'        => 'string',
				'default'     => 'DESC',
				'enum'        => [ 'ASC', 'DESC' ],
			],
		];
	}
}

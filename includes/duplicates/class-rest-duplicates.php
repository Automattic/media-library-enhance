<?php
/**
 * REST API Duplicate Detection Endpoints.
 *
 * Provides endpoints for:
 * - GET /mle/v1/duplicates/{id}              — Find exact duplicates of a specific attachment
 * - GET /mle/v1/duplicates/{id}/similar      — Find visually similar images
 * - GET /mle/v1/duplicates/check-on-upload   — Combined exact + similar lookup for editor warnings
 *
 * @package MediaLibraryEnhance\Duplicates
 */

namespace MediaLibraryEnhance\Duplicates;

defined( 'ABSPATH' ) || exit;

class REST_Duplicates {

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
		// Find exact duplicates of an attachment.
		register_rest_route(
			self::NAMESPACE,
			'/duplicates/(?P<id>\d+)',
			[
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => [ $this, 'handle_find_exact' ],
				'permission_callback' => [ $this, 'check_permissions' ],
				'args'                => [
					'id' => [
						'description' => 'Attachment ID.',
						'type'        => 'integer',
						'required'    => true,
					],
				],
			]
		);

		// Combined exact + similar check, designed for editor upload warnings.
		// Registered before the /duplicates/{id} routes so the literal
		// "check-on-upload" segment doesn't get matched as an integer ID.
		register_rest_route(
			self::NAMESPACE,
			'/duplicates/check-on-upload',
			[
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => [ $this, 'handle_check_on_upload' ],
				'permission_callback' => [ $this, 'check_permissions' ],
				'args'                => [
					'attachment_id' => [
						'description' => 'Attachment ID just uploaded.',
						'type'        => 'integer',
						'required'    => true,
					],
				],
			]
		);

		// Find visually similar images.
		register_rest_route(
			self::NAMESPACE,
			'/duplicates/(?P<id>\d+)/similar',
			[
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => [ $this, 'handle_find_similar' ],
				'permission_callback' => [ $this, 'check_permissions' ],
				'args'                => [
					'id'        => [
						'description' => 'Attachment ID.',
						'type'        => 'integer',
						'required'    => true,
					],
					'threshold' => [
						'description' => 'Maximum Hamming distance (0-64). Lower = more strict.',
						'type'        => 'integer',
						'default'     => Duplicate_Finder::PERCEPTUAL_THRESHOLD,
						'minimum'     => 0,
						'maximum'     => 64,
					],
				],
			]
		);
	}

	public function handle_find_exact( \WP_REST_Request $request ): \WP_REST_Response {
		$attachment_id = (int) $request->get_param( 'id' );
		$duplicates    = Duplicate_Finder::instance()->find_exact_duplicates( $attachment_id );

		$items = array_map( [ $this, 'prepare_duplicate' ], $duplicates );

		return new \WP_REST_Response( [
			'attachment_id' => $attachment_id,
			'duplicates'    => $items,
			'count'         => count( $items ),
		] );
	}

	public function handle_find_similar( \WP_REST_Request $request ): \WP_REST_Response {
		$attachment_id = (int) $request->get_param( 'id' );
		$threshold     = (int) $request->get_param( 'threshold' );
		$similar       = Duplicate_Finder::instance()->find_similar( $attachment_id, $threshold );

		$items = array_map(
			function ( array $match ): array {
				$prepared             = $this->prepare_duplicate( $match['id'] );
				$prepared['distance'] = $match['distance'];
				return $prepared;
			},
			$similar
		);

		return new \WP_REST_Response( [
			'attachment_id' => $attachment_id,
			'threshold'     => $threshold,
			'similar'       => $items,
			'count'         => count( $items ),
		] );
	}

	/**
	 * Combined exact + perceptual lookup for the editor upload notice.
	 *
	 * Returns both match types in one round trip and excludes the
	 * just-uploaded attachment from the results. The perceptual
	 * threshold is filterable via `mle_duplicate_warning_threshold` —
	 * default 0 means only pixel-identical matches surface.
	 *
	 * @param \WP_REST_Request $request The REST request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function handle_check_on_upload( \WP_REST_Request $request ) {
		$attachment_id = (int) $request->get_param( 'attachment_id' );

		$attachment = get_post( $attachment_id );
		if ( ! $attachment || 'attachment' !== $attachment->post_type ) {
			return new \WP_Error(
				'invalid_attachment',
				'Attachment not found.',
				[ 'status' => 404 ]
			);
		}

		/**
		 * Perceptual hash distance threshold used by the editor warning.
		 *
		 * Default 0 means only pixel-identical matches surface as
		 * "similar". Raise to ~10 to catch resized/recompressed copies.
		 *
		 * @param int $threshold Hamming distance threshold (0-64).
		 */
		$threshold = (int) apply_filters( 'mle_duplicate_warning_threshold', 0 );

		$exact_ids = Duplicate_Finder::instance()->find_exact_duplicates( $attachment_id );
		$similar   = Duplicate_Finder::instance()->find_similar( $attachment_id, $threshold );

		// Exact matches dominate — drop them from the similar list to
		// avoid showing the same attachment twice.
		$exact_id_set = array_flip( $exact_ids );
		$similar      = array_values(
			array_filter(
				$similar,
				static fn( array $match ): bool => ! isset( $exact_id_set[ $match['id'] ] )
			)
		);

		$exact_items = array_map( [ $this, 'prepare_duplicate' ], $exact_ids );

		$similar_items = array_map(
			function ( array $match ): array {
				$prepared             = $this->prepare_duplicate( $match['id'] );
				$prepared['distance'] = $match['distance'];
				return $prepared;
			},
			$similar
		);

		return new \WP_REST_Response(
			[
				'attachment_id' => $attachment_id,
				'threshold'     => $threshold,
				'exact'         => $exact_items,
				'similar'       => $similar_items,
			]
		);
	}

	/**
	 * Prepare a duplicate attachment for the REST response.
	 */
	private function prepare_duplicate( int $attachment_id ): array {
		$post      = get_post( $attachment_id );
		$thumbnail = wp_get_attachment_image_src( $attachment_id, 'thumbnail' );

		return [
			'id'        => $attachment_id,
			'title'     => $post ? $post->post_title : '',
			'url'       => wp_get_attachment_url( $attachment_id ),
			'thumbnail' => $thumbnail ? $thumbnail[0] : '',
			'mime_type' => $post ? $post->post_mime_type : '',
			'date'      => $post ? $post->post_date_gmt : '',
		];
	}

	public function check_permissions(): bool {
		return current_user_can( 'upload_files' );
	}
}

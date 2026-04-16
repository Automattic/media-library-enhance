<?php
/**
 * REST API Duplicate Detection Endpoints.
 *
 * Provides endpoints for:
 * - GET /mle/v1/duplicates/{id}        — Find duplicates of a specific attachment
 * - GET /mle/v1/duplicates/{id}/similar — Find visually similar images
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
	 * Prepare a duplicate attachment for the REST response.
	 */
	private function prepare_duplicate( int $attachment_id ): array {
		$post = get_post( $attachment_id );

		return [
			'id'        => $attachment_id,
			'title'     => $post ? $post->post_title : '',
			'url'       => wp_get_attachment_url( $attachment_id ),
			'mime_type' => $post ? $post->post_mime_type : '',
			'date'      => $post ? $post->post_date_gmt : '',
		];
	}

	public function check_permissions(): bool {
		return current_user_can( 'upload_files' );
	}
}

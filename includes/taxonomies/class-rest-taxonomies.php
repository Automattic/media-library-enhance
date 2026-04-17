<?php
/**
 * REST API Taxonomy Extensions.
 *
 * Adds a bulk-assign endpoint for efficient `media_tag` management on
 * large libraries.
 *
 * @package MediaLibraryEnhance\Taxonomies
 */

namespace MediaLibraryEnhance\Taxonomies;

defined( 'ABSPATH' ) || exit;

class REST_Taxonomies {

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
		// Bulk assign taxonomy terms to multiple attachments.
		register_rest_route(
			self::NAMESPACE,
			'/taxonomies/bulk-assign',
			[
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'handle_bulk_assign' ],
				'permission_callback' => [ $this, 'check_permissions' ],
				'args'                => [
					'attachment_ids' => [
						'description' => 'Array of attachment IDs.',
						'type'        => 'array',
						'required'    => true,
						'items'       => [ 'type' => 'integer' ],
					],
					'taxonomy'       => [
						'description' => 'Taxonomy to assign terms to.',
						'type'        => 'string',
						'required'    => true,
						'enum'        => [
							Media_Taxonomies::TAG_TAXONOMY,
						],
					],
					'terms'          => [
						'description' => 'Array of term IDs to assign.',
						'type'        => 'array',
						'required'    => true,
						'items'       => [ 'type' => 'integer' ],
					],
					'append'         => [
						'description' => 'Whether to append terms (true) or replace (false).',
						'type'        => 'boolean',
						'default'     => true,
					],
				],
			]
		);
	}

	/**
	 * Assign taxonomy terms to multiple attachments in one request.
	 *
	 * @param \WP_REST_Request $request The request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function handle_bulk_assign( \WP_REST_Request $request ) {
		$attachment_ids = $request->get_param( 'attachment_ids' );
		$taxonomy       = $request->get_param( 'taxonomy' );
		$terms          = $request->get_param( 'terms' );
		$append         = $request->get_param( 'append' );

		$results = [];

		foreach ( $attachment_ids as $id ) {
			$post = get_post( $id );
			if ( ! $post || 'attachment' !== $post->post_type ) {
				$results[ $id ] = [ 'success' => false, 'error' => 'Invalid attachment ID.' ];
				continue;
			}

			$result = wp_set_object_terms( $id, $terms, $taxonomy, $append );

			if ( is_wp_error( $result ) ) {
				$results[ $id ] = [ 'success' => false, 'error' => $result->get_error_message() ];
			} else {
				$results[ $id ] = [ 'success' => true, 'terms' => $result ];
			}
		}

		return new \WP_REST_Response( [ 'results' => $results ], 200 );
	}

	public function check_permissions(): bool {
		return current_user_can( 'upload_files' );
	}
}

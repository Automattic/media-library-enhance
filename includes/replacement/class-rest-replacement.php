<?php
/**
 * REST API Image Replacement Endpoints.
 *
 * Provides endpoints for:
 * - GET  /mle/v1/usage/{id}   — Where is this attachment used?
 * - POST /mle/v1/replace/{id} — Replace an attachment's file
 *
 * @package MediaLibraryEnhance\Replacement
 */

namespace MediaLibraryEnhance\Replacement;

defined( 'ABSPATH' ) || exit;

class REST_Replacement {

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
		// Get usage information for an attachment.
		register_rest_route(
			self::NAMESPACE,
			'/usage/(?P<id>\d+)',
			[
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => [ $this, 'handle_get_usage' ],
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

		// Replace an attachment's file.
		register_rest_route(
			self::NAMESPACE,
			'/replace/(?P<id>\d+)',
			[
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'handle_replace' ],
				'permission_callback' => [ $this, 'check_edit_permissions' ],
			]
		);
	}

	/**
	 * Return usage data for an attachment.
	 */
	public function handle_get_usage( \WP_REST_Request $request ): \WP_REST_Response {
		$attachment_id = (int) $request->get_param( 'id' );
		$summary       = Image_Replacer::instance()->get_usage_summary( $attachment_id );

		return new \WP_REST_Response( $summary );
	}

	/**
	 * Handle file replacement upload.
	 *
	 * Expects a multipart/form-data request with a `file` field.
	 *
	 * @param \WP_REST_Request $request The request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function handle_replace( \WP_REST_Request $request ) {
		$attachment_id = (int) $request->get_param( 'id' );
		$files         = $request->get_file_params();

		if ( empty( $files['file'] ) ) {
			return new \WP_Error(
				'missing_file',
				'No replacement file provided.',
				[ 'status' => 400 ]
			);
		}

		$file = $files['file'];

		// Validate the upload.
		$validation = wp_check_filetype_and_ext( $file['tmp_name'], $file['name'] );
		if ( ! $validation['type'] ) {
			return new \WP_Error(
				'invalid_file_type',
				'The uploaded file type is not allowed.',
				[ 'status' => 400 ]
			);
		}

		$result = Image_Replacer::instance()->replace( $attachment_id, $file['tmp_name'] );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return new \WP_REST_Response(
			[
				'success'    => true,
				'attachment' => [
					'id'  => $attachment_id,
					'url' => wp_get_attachment_url( $attachment_id ),
				],
			]
		);
	}

	public function check_permissions(): bool {
		return current_user_can( 'upload_files' );
	}

	public function check_edit_permissions( \WP_REST_Request $request ): bool {
		$attachment_id = (int) $request->get_param( 'id' );
		if ( $attachment_id <= 0 ) {
			return false;
		}
		return current_user_can( 'edit_others_posts' ) && current_user_can( 'edit_post', $attachment_id );
	}
}

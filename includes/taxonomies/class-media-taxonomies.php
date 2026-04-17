<?php
/**
 * Media Taxonomies Registration.
 *
 * Registers `media_tag` (flat, non-hierarchical) on the `attachment`
 * post type. Tags-only by design — see planning/BACKGROUND.md for why
 * we deliberately don't ship hierarchical categories or folders.
 *
 * The `media_category` taxonomy that earlier scaffolds registered has
 * been removed; sites with existing data should run
 * `wp mle taxonomies migrate-categories-to-tags` before upgrading.
 *
 * @package MediaLibraryEnhance\Taxonomies
 */

namespace MediaLibraryEnhance\Taxonomies;

defined( 'ABSPATH' ) || exit;

class Media_Taxonomies {

	private static ?self $instance = null;

	public const TAG_TAXONOMY = 'media_tag';

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function register(): void {
		add_action( 'init', [ $this, 'register_taxonomies' ] );
	}

	/**
	 * Register the media_tag taxonomy.
	 */
	public function register_taxonomies(): void {
		// Flat taxonomy — behaves like tags for flexible labeling.
		register_taxonomy(
			self::TAG_TAXONOMY,
			'attachment',
			[
				'labels'            => $this->get_tag_labels(),
				'hierarchical'      => false,
				'public'            => false,
				'show_ui'           => true,
				'show_in_rest'      => true,
				'show_admin_column' => true,
				'query_var'         => false,
				'rewrite'           => false,
				'capabilities'      => [
					'manage_terms' => 'upload_files',
					'edit_terms'   => 'upload_files',
					'delete_terms' => 'upload_files',
					'assign_terms' => 'upload_files',
				],
			]
		);
	}

	/**
	 * @return array<string, string>
	 */
	private function get_tag_labels(): array {
		return [
			'name'                       => _x( 'Media Tags', 'taxonomy general name', 'media-library-enhance' ),
			'singular_name'              => _x( 'Media Tag', 'taxonomy singular name', 'media-library-enhance' ),
			'search_items'               => __( 'Search Media Tags', 'media-library-enhance' ),
			'popular_items'              => __( 'Popular Media Tags', 'media-library-enhance' ),
			'all_items'                  => __( 'All Media Tags', 'media-library-enhance' ),
			'edit_item'                  => __( 'Edit Media Tag', 'media-library-enhance' ),
			'update_item'                => __( 'Update Media Tag', 'media-library-enhance' ),
			'add_new_item'               => __( 'Add New Media Tag', 'media-library-enhance' ),
			'new_item_name'              => __( 'New Media Tag Name', 'media-library-enhance' ),
			'separate_items_with_commas' => __( 'Separate media tags with commas', 'media-library-enhance' ),
			'add_or_remove_items'        => __( 'Add or remove media tags', 'media-library-enhance' ),
			'choose_from_most_used'      => __( 'Choose from the most used media tags', 'media-library-enhance' ),
			'not_found'                  => __( 'No media tags found.', 'media-library-enhance' ),
			'menu_name'                  => __( 'Media Tags', 'media-library-enhance' ),
		];
	}
}

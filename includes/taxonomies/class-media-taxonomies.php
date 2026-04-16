<?php
/**
 * Media Taxonomies Registration.
 *
 * Registers `media_category` (hierarchical) and `media_tag` (flat)
 * taxonomies on the `attachment` post type. This gives media items
 * organizational structure — virtual folders via categories, and
 * flexible tagging.
 *
 * Follows the community consensus from Trac #47839: use taxonomies
 * (not filesystem folders) for media organization. This approach
 * survives the Phase 3 UI transition since DataViews already supports
 * taxonomy filtering.
 *
 * @package MediaLibraryEnhance\Taxonomies
 */

namespace MediaLibraryEnhance\Taxonomies;

defined( 'ABSPATH' ) || exit;

class Media_Taxonomies {

	private static ?self $instance = null;

	public const CATEGORY_TAXONOMY = 'media_category';
	public const TAG_TAXONOMY      = 'media_tag';

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
	 * Register media_category and media_tag taxonomies.
	 */
	public function register_taxonomies(): void {
		// Hierarchical taxonomy — behaves like categories / virtual folders.
		register_taxonomy(
			self::CATEGORY_TAXONOMY,
			'attachment',
			[
				'labels'            => $this->get_category_labels(),
				'hierarchical'      => true,
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
	private function get_category_labels(): array {
		return [
			'name'              => _x( 'Media Categories', 'taxonomy general name', 'media-library-enhance' ),
			'singular_name'     => _x( 'Media Category', 'taxonomy singular name', 'media-library-enhance' ),
			'search_items'      => __( 'Search Media Categories', 'media-library-enhance' ),
			'all_items'         => __( 'All Media Categories', 'media-library-enhance' ),
			'parent_item'       => __( 'Parent Media Category', 'media-library-enhance' ),
			'parent_item_colon' => __( 'Parent Media Category:', 'media-library-enhance' ),
			'edit_item'         => __( 'Edit Media Category', 'media-library-enhance' ),
			'update_item'       => __( 'Update Media Category', 'media-library-enhance' ),
			'add_new_item'      => __( 'Add New Media Category', 'media-library-enhance' ),
			'new_item_name'     => __( 'New Media Category Name', 'media-library-enhance' ),
			'menu_name'         => __( 'Media Categories', 'media-library-enhance' ),
		];
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

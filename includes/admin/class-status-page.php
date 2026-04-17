<?php
/**
 * Status Page.
 *
 * Adds a "Media Library Enhance" page under Tools that shows the health
 * of each module: Elasticsearch availability, hash coverage, usage index
 * size, and the WP-CLI commands to maintain them.
 *
 * Read-only — actual maintenance happens via WP-CLI.
 *
 * @package MediaLibraryEnhance\Admin
 */

namespace MediaLibraryEnhance\Admin;

use MediaLibraryEnhance\Duplicates\Hash_Generator;
use MediaLibraryEnhance\Replacement\Usage_Tracker;
use MediaLibraryEnhance\Search\Elasticsearch_Query;
use MediaLibraryEnhance\Taxonomies\Media_Taxonomies;

defined( 'ABSPATH' ) || exit;

class Status_Page {

	private static ?self $instance = null;

	public const PAGE_SLUG = 'media-library-enhance';

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function register(): void {
		if ( ! is_admin() ) {
			return;
		}

		add_action( 'admin_menu', [ $this, 'add_menu' ] );
	}

	public function add_menu(): void {
		add_management_page(
			__( 'Media Library Enhance', 'media-library-enhance' ),
			__( 'Media Library Enhance', 'media-library-enhance' ),
			'manage_options',
			self::PAGE_SLUG,
			[ $this, 'render' ]
		);
	}

	/**
	 * Render the status page.
	 */
	public function render(): void {
		$stats = $this->collect_stats();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Media Library Enhance — Status', 'media-library-enhance' ); ?></h1>

			<p class="description">
				<?php esc_html_e( 'Read-only health overview. Maintenance happens via WP-CLI commands shown below.', 'media-library-enhance' ); ?>
			</p>

			<h2><?php esc_html_e( 'Search (Elasticsearch)', 'media-library-enhance' ); ?></h2>
			<table class="widefat striped">
				<tbody>
					<tr>
						<th><?php esc_html_e( 'Status', 'media-library-enhance' ); ?></th>
						<td>
							<?php if ( $stats['es_available'] ) : ?>
								<span style="color:#008a20;">●</span> <?php esc_html_e( 'Active', 'media-library-enhance' ); ?>
							<?php else : ?>
								<span style="color:#d63638;">●</span> <?php esc_html_e( 'Unavailable — falling back to MySQL', 'media-library-enhance' ); ?>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'CLI', 'media-library-enhance' ); ?></th>
						<td><code>wp mle search status</code> · <code>wp mle search test &lt;term&gt;</code></td>
					</tr>
				</tbody>
			</table>

			<h2><?php esc_html_e( 'Attachments', 'media-library-enhance' ); ?></h2>
			<table class="widefat striped">
				<tbody>
					<tr>
						<th><?php esc_html_e( 'Total attachments', 'media-library-enhance' ); ?></th>
						<td><?php echo esc_html( number_format_i18n( $stats['total_attachments'] ) ); ?></td>
					</tr>
				</tbody>
			</table>

			<h2><?php esc_html_e( 'Duplicate Detection', 'media-library-enhance' ); ?></h2>
			<table class="widefat striped">
				<tbody>
					<tr>
						<th><?php esc_html_e( 'File hashes (MD5)', 'media-library-enhance' ); ?></th>
						<td>
							<?php
							echo esc_html( sprintf(
								/* translators: 1: hashed count, 2: total, 3: percentage */
								__( '%1$s of %2$s (%3$s%%)', 'media-library-enhance' ),
								number_format_i18n( $stats['file_hash_count'] ),
								number_format_i18n( $stats['total_attachments'] ),
								number_format_i18n( $stats['file_hash_pct'], 1 )
							) );
							?>
						</td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Perceptual hashes', 'media-library-enhance' ); ?></th>
						<td>
							<?php
							echo esc_html( sprintf(
								/* translators: 1: hashed count, 2: total, 3: percentage */
								__( '%1$s of %2$s (%3$s%%)', 'media-library-enhance' ),
								number_format_i18n( $stats['perceptual_hash_count'] ),
								number_format_i18n( $stats['total_attachments'] ),
								number_format_i18n( $stats['perceptual_hash_pct'], 1 )
							) );
							?>
						</td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'CLI', 'media-library-enhance' ); ?></th>
						<td><code>wp mle duplicates hash</code> · <code>wp mle duplicates scan</code></td>
					</tr>
				</tbody>
			</table>

			<h2><?php esc_html_e( 'Usage Tracking', 'media-library-enhance' ); ?></h2>
			<table class="widefat striped">
				<tbody>
					<tr>
						<th><?php esc_html_e( 'Indexed attachments', 'media-library-enhance' ); ?></th>
						<td><?php echo esc_html( number_format_i18n( $stats['usage_indexed_count'] ) ); ?></td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'CLI', 'media-library-enhance' ); ?></th>
						<td><code>wp mle usage rebuild</code> · <code>wp mle usage show &lt;id&gt;</code></td>
					</tr>
				</tbody>
			</table>

			<h2><?php esc_html_e( 'Taxonomies', 'media-library-enhance' ); ?></h2>
			<table class="widefat striped">
				<tbody>
					<tr>
						<th><?php esc_html_e( 'Media Tags', 'media-library-enhance' ); ?></th>
						<td><?php echo esc_html( number_format_i18n( $stats['tag_count'] ) ); ?></td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'CLI', 'media-library-enhance' ); ?></th>
						<td><code>wp mle taxonomies stats</code> · <code>wp mle taxonomies assign</code></td>
					</tr>
				</tbody>
			</table>
		</div>
		<?php
	}

	/**
	 * Collect statistics for the status page.
	 *
	 * @return array<string, mixed>
	 */
	private function collect_stats(): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$total = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'attachment' AND post_status = 'inherit'"
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$file_hashes = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = %s",
				Hash_Generator::FILE_HASH_META
			)
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$perceptual_hashes = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = %s",
				Hash_Generator::PERCEPTUAL_HASH_META
			)
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$usage_indexed = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = %s",
				Usage_Tracker::META_KEY
			)
		);

		$tags = wp_count_terms( [ 'taxonomy' => Media_Taxonomies::TAG_TAXONOMY, 'hide_empty' => false ] );

		return [
			'es_available'          => Elasticsearch_Query::instance()->is_es_available(),
			'total_attachments'     => $total,
			'file_hash_count'       => $file_hashes,
			'file_hash_pct'         => $total > 0 ? ( $file_hashes / $total * 100 ) : 0,
			'perceptual_hash_count' => $perceptual_hashes,
			'perceptual_hash_pct'   => $total > 0 ? ( $perceptual_hashes / $total * 100 ) : 0,
			'usage_indexed_count'   => $usage_indexed,
			'tag_count'             => is_wp_error( $tags ) ? 0 : (int) $tags,
		];
	}
}

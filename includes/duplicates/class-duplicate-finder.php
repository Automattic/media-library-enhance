<?php
/**
 * Duplicate Finder.
 *
 * Queries the hash index to find exact and visually similar duplicates.
 * Provides methods for both single-attachment lookups ("what are the
 * duplicates of this image?") and library-wide scans ("find all
 * duplicate groups").
 *
 * @package MediaLibraryEnhance\Duplicates
 */

namespace MediaLibraryEnhance\Duplicates;

defined( 'ABSPATH' ) || exit;

class Duplicate_Finder {

	private static ?self $instance = null;

	/**
	 * Maximum Hamming distance to consider two images "visually similar".
	 * 0 = identical, 10 = quite similar, 20+ = probably different.
	 */
	public const PERCEPTUAL_THRESHOLD = 10;

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function register(): void {
		// No hooks needed — invoked via REST API / WP-CLI.
	}

	/**
	 * Find exact duplicates of a specific attachment (by file hash).
	 *
	 * @param int $attachment_id The attachment to find duplicates of.
	 * @return int[] Array of duplicate attachment IDs (excluding the input).
	 */
	public function find_exact_duplicates( int $attachment_id ): array {
		$hash = get_post_meta( $attachment_id, Hash_Generator::FILE_HASH_META, true );
		if ( ! $hash ) {
			return [];
		}

		return $this->find_attachments_by_file_hash( $hash, $attachment_id );
	}

	/**
	 * Find visually similar duplicates of a specific attachment.
	 *
	 * @param int $attachment_id The attachment.
	 * @param int $threshold     Max Hamming distance (default: PERCEPTUAL_THRESHOLD).
	 * @return array<int, array{ id: int, distance: int }> Matches sorted by distance.
	 */
	public function find_similar( int $attachment_id, int $threshold = self::PERCEPTUAL_THRESHOLD ): array {
		$hash = get_post_meta( $attachment_id, Hash_Generator::PERCEPTUAL_HASH_META, true );
		if ( ! $hash ) {
			return [];
		}

		return $this->find_attachments_by_perceptual_hash( $hash, $threshold, $attachment_id );
	}

	/**
	 * Scan the entire library for groups of exact duplicates.
	 *
	 * Returns groups where the same file hash appears on multiple attachments.
	 * Designed for WP-CLI batch processing — yields one group at a time.
	 *
	 * @return \Generator<int, array{ hash: string, attachment_ids: int[] }>
	 */
	public function scan_all_exact_duplicates(): \Generator {
		global $wpdb;

		// Find all file hashes that appear more than once.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$duplicate_hashes = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT meta_value, COUNT(*) as cnt
				FROM {$wpdb->postmeta}
				WHERE meta_key = %s
				GROUP BY meta_value
				HAVING cnt > 1
				ORDER BY cnt DESC",
				Hash_Generator::FILE_HASH_META
			)
		);

		foreach ( $duplicate_hashes as $row ) {
			$attachment_ids = $this->find_attachments_by_file_hash( $row->meta_value );
			yield [
				'hash'           => $row->meta_value,
				'attachment_ids' => $attachment_ids,
			];
		}
	}

	/**
	 * Find all attachments with a specific file hash.
	 *
	 * @param string   $hash       The MD5 file hash.
	 * @param int|null $exclude_id Optional attachment ID to exclude.
	 * @return int[]
	 */
	private function find_attachments_by_file_hash( string $hash, ?int $exclude_id = null ): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT post_id FROM {$wpdb->postmeta}
				WHERE meta_key = %s AND meta_value = %s",
				Hash_Generator::FILE_HASH_META,
				$hash
			)
		);

		$ids = array_map( 'intval', $ids );

		if ( $exclude_id ) {
			$ids = array_values( array_diff( $ids, [ $exclude_id ] ) );
		}

		return $ids;
	}

	/**
	 * Find attachments with a perceptual hash within the threshold.
	 *
	 * Note: This does a full table scan of perceptual hashes and computes
	 * Hamming distance in PHP. For libraries with millions of images,
	 * this should be run via WP-CLI in batches rather than in a web request.
	 *
	 * @param string   $hash       The perceptual hash to compare against.
	 * @param int      $threshold  Maximum Hamming distance.
	 * @param int|null $exclude_id Optional attachment ID to exclude.
	 * @return array<int, array{ id: int, distance: int }>
	 */
	private function find_attachments_by_perceptual_hash( string $hash, int $threshold, ?int $exclude_id = null ): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$results = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT post_id, meta_value FROM {$wpdb->postmeta}
				WHERE meta_key = %s",
				Hash_Generator::PERCEPTUAL_HASH_META
			)
		);

		$matches = [];

		foreach ( $results as $row ) {
			$id = (int) $row->post_id;
			if ( $exclude_id && $id === $exclude_id ) {
				continue;
			}

			$distance = Hash_Generator::hamming_distance( $hash, $row->meta_value );
			if ( $distance <= $threshold ) {
				$matches[] = [
					'id'       => $id,
					'distance' => $distance,
				];
			}
		}

		// Sort by distance (most similar first).
		usort( $matches, fn( $a, $b ) => $a['distance'] <=> $b['distance'] );

		return $matches;
	}
}

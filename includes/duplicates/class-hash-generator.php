<?php
/**
 * Attachment Hash Generator.
 *
 * Generates and stores file hashes for duplicate detection. Two hash types:
 *
 * - MD5 file hash (`_mle_file_hash`): Exact binary duplicate detection.
 *   Fast, reliable, no false positives.
 *
 * - Perceptual hash (`_mle_perceptual_hash`): Visual similarity detection.
 *   Catches cropped/resized/recompressed duplicates. Requires GD or Imagick.
 *
 * Hashes are computed on upload (via wp_handle_upload hook) and can be
 * batch-generated for existing libraries via WP-CLI.
 *
 * @package MediaLibraryEnhance\Duplicates
 */

namespace MediaLibraryEnhance\Duplicates;

defined( 'ABSPATH' ) || exit;

class Hash_Generator {

	private static ?self $instance = null;

	public const FILE_HASH_META       = '_mle_file_hash';
	public const PERCEPTUAL_HASH_META = '_mle_perceptual_hash';

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function register(): void {
		// Hash files on upload.
		add_filter( 'wp_generate_attachment_metadata', [ $this, 'hash_on_upload' ], 10, 2 );
	}

	/**
	 * Generate hashes when a new attachment is uploaded.
	 *
	 * Hooked to wp_generate_attachment_metadata so it runs after the file
	 * is in its final location.
	 *
	 * @param array<string, mixed> $metadata    The attachment metadata.
	 * @param int                  $attachment_id The attachment ID.
	 * @return array<string, mixed> Unmodified metadata (pass-through filter).
	 */
	public function hash_on_upload( array $metadata, int $attachment_id ): array {
		$file = get_attached_file( $attachment_id );
		if ( $file && file_exists( $file ) ) {
			$this->generate_and_store_hashes( $attachment_id, $file );
		}
		return $metadata;
	}

	/**
	 * Generate both hash types and store them as postmeta.
	 *
	 * @param int    $attachment_id The attachment.
	 * @param string $file_path     Absolute path to the file.
	 */
	public function generate_and_store_hashes( int $attachment_id, string $file_path ): void {
		// MD5 file hash — always available.
		$file_hash = $this->compute_file_hash( $file_path );
		if ( $file_hash ) {
			update_post_meta( $attachment_id, self::FILE_HASH_META, $file_hash );
		}

		// Perceptual hash — only for images.
		$mime = get_post_mime_type( $attachment_id );
		if ( $mime && str_starts_with( $mime, 'image/' ) ) {
			$phash = $this->compute_perceptual_hash( $file_path );
			if ( $phash ) {
				update_post_meta( $attachment_id, self::PERCEPTUAL_HASH_META, $phash );
			}
		}
	}

	/**
	 * Compute an MD5 hash of the file contents.
	 *
	 * @param string $file_path Path to the file.
	 * @return string|null The hex-encoded MD5 hash, or null on failure.
	 */
	public function compute_file_hash( string $file_path ): ?string {
		if ( ! file_exists( $file_path ) ) {
			return null;
		}

		$hash = md5_file( $file_path );
		return $hash !== false ? $hash : null;
	}

	/**
	 * Compute a perceptual hash (average hash / aHash) of an image.
	 *
	 * Algorithm:
	 * 1. Resize to 8x8 (removes high-frequency detail)
	 * 2. Convert to grayscale
	 * 3. Compute mean pixel value
	 * 4. Each bit = 1 if pixel > mean, 0 otherwise
	 * 5. Result: 64-bit hash as hex string
	 *
	 * This is intentionally simple — catches obvious duplicates without
	 * requiring external libraries. A future iteration could use dHash
	 * or pHash for better accuracy.
	 *
	 * @param string $file_path Path to the image file.
	 * @return string|null 16-character hex string, or null on failure.
	 */
	public function compute_perceptual_hash( string $file_path ): ?string {
		if ( ! function_exists( 'imagecreatetruecolor' ) ) {
			return null; // GD not available.
		}

		$source = $this->load_image( $file_path );
		if ( ! $source ) {
			return null;
		}

		// Resize to 8x8.
		$small = imagecreatetruecolor( 8, 8 );
		if ( ! $small ) {
			imagedestroy( $source );
			return null;
		}

		imagecopyresampled( $small, $source, 0, 0, 0, 0, 8, 8, imagesx( $source ), imagesy( $source ) );
		imagedestroy( $source );

		// Convert to grayscale values and compute mean.
		$pixels = [];
		$total  = 0;

		for ( $y = 0; $y < 8; $y++ ) {
			for ( $x = 0; $x < 8; $x++ ) {
				$rgb  = imagecolorat( $small, $x, $y );
				$r    = ( $rgb >> 16 ) & 0xFF;
				$g    = ( $rgb >> 8 ) & 0xFF;
				$b    = $rgb & 0xFF;
				$gray = (int) ( 0.299 * $r + 0.587 * $g + 0.114 * $b );

				$pixels[] = $gray;
				$total   += $gray;
			}
		}

		imagedestroy( $small );

		$mean = $total / 64;

		// Build 64-bit hash: 1 if pixel > mean, 0 otherwise.
		$hash = '';
		$bits = '';
		foreach ( $pixels as $gray ) {
			$bits .= ( $gray > $mean ) ? '1' : '0';
		}

		// Convert 64-bit binary string to 16-char hex.
		$hash = '';
		for ( $i = 0; $i < 64; $i += 4 ) {
			$hash .= dechex( (int) bindec( substr( $bits, $i, 4 ) ) );
		}

		return $hash;
	}

	/**
	 * Calculate Hamming distance between two perceptual hashes.
	 *
	 * Lower distance = more similar. Distance of 0 = identical.
	 * Threshold of ~10 typically indicates a visual duplicate.
	 *
	 * @param string $hash_a First hash (hex).
	 * @param string $hash_b Second hash (hex).
	 * @return int Hamming distance (0-64).
	 */
	public static function hamming_distance( string $hash_a, string $hash_b ): int {
		if ( strlen( $hash_a ) !== strlen( $hash_b ) ) {
			return 64; // Maximum distance if hashes are incompatible.
		}

		$distance = 0;
		for ( $i = 0, $len = strlen( $hash_a ); $i < $len; $i++ ) {
			$xor      = intval( $hash_a[ $i ], 16 ) ^ intval( $hash_b[ $i ], 16 );
			$distance += substr_count( decbin( $xor ), '1' );
		}

		return $distance;
	}

	/**
	 * Load an image file into a GD resource.
	 *
	 * @param string $file_path Path to the image.
	 * @return \GdImage|false
	 */
	private function load_image( string $file_path ) {
		$mime = wp_check_filetype( $file_path )['type'];

		return match ( $mime ) {
			'image/jpeg' => imagecreatefromjpeg( $file_path ),
			'image/png'  => imagecreatefrompng( $file_path ),
			'image/gif'  => imagecreatefromgif( $file_path ),
			'image/webp' => function_exists( 'imagecreatefromwebp' ) ? imagecreatefromwebp( $file_path ) : false,
			default      => false,
		};
	}
}

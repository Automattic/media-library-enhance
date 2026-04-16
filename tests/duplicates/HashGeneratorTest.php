<?php
/**
 * Tests for the file hash generator.
 *
 * @package MediaLibraryEnhance\Tests\Duplicates
 */

namespace MediaLibraryEnhance\Tests\Duplicates;

use MediaLibraryEnhance\Duplicates\Hash_Generator;
use WP_UnitTestCase;

class HashGeneratorTest extends WP_UnitTestCase {

	private Hash_Generator $generator;

	public function set_up(): void {
		parent::set_up();
		$this->generator = Hash_Generator::instance();
	}

	public function test_compute_file_hash_returns_md5(): void {
		$tmp = wp_tempnam( 'mle-test' );
		file_put_contents( $tmp, 'test file contents' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents

		$hash = $this->generator->compute_file_hash( $tmp );

		$this->assertNotNull( $hash );
		$this->assertSame( 32, strlen( $hash ) ); // MD5 hex string length.
		$this->assertSame( md5_file( $tmp ), $hash );

		wp_delete_file( $tmp );
	}

	public function test_compute_file_hash_returns_null_for_missing_file(): void {
		$this->assertNull( $this->generator->compute_file_hash( '/nonexistent/file.jpg' ) );
	}

	public function test_identical_files_produce_same_hash(): void {
		$content = 'identical content';
		$tmp1    = wp_tempnam( 'mle-test-1' );
		$tmp2    = wp_tempnam( 'mle-test-2' );

		file_put_contents( $tmp1, $content ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		file_put_contents( $tmp2, $content ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents

		$this->assertSame(
			$this->generator->compute_file_hash( $tmp1 ),
			$this->generator->compute_file_hash( $tmp2 )
		);

		wp_delete_file( $tmp1 );
		wp_delete_file( $tmp2 );
	}

	public function test_different_files_produce_different_hashes(): void {
		$tmp1 = wp_tempnam( 'mle-test-1' );
		$tmp2 = wp_tempnam( 'mle-test-2' );

		file_put_contents( $tmp1, 'content A' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		file_put_contents( $tmp2, 'content B' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents

		$this->assertNotSame(
			$this->generator->compute_file_hash( $tmp1 ),
			$this->generator->compute_file_hash( $tmp2 )
		);

		wp_delete_file( $tmp1 );
		wp_delete_file( $tmp2 );
	}

	public function test_hamming_distance_identical(): void {
		$this->assertSame( 0, Hash_Generator::hamming_distance( 'abcdef0123456789', 'abcdef0123456789' ) );
	}

	public function test_hamming_distance_completely_different(): void {
		$distance = Hash_Generator::hamming_distance( '0000000000000000', 'ffffffffffffffff' );
		$this->assertSame( 64, $distance );
	}

	public function test_hamming_distance_one_bit_difference(): void {
		// 0 = 0000, 1 = 0001 — one bit difference.
		$distance = Hash_Generator::hamming_distance( '0000000000000000', '1000000000000000' );
		$this->assertSame( 1, $distance );
	}
}

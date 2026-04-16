<?php
/**
 * Tests for the Elasticsearch query routing.
 *
 * @package MediaLibraryEnhance\Tests\Search
 */

namespace MediaLibraryEnhance\Tests\Search;

use MediaLibraryEnhance\Search\Elasticsearch_Query;
use WP_UnitTestCase;

class SearchQueryTest extends WP_UnitTestCase {

	private Elasticsearch_Query $es_query;

	public function set_up(): void {
		parent::set_up();
		$this->es_query = Elasticsearch_Query::instance();
	}

	public function test_is_media_search_with_attachment_query(): void {
		$query = new \WP_Query();
		$query->set( 'post_type', 'attachment' );
		$query->set( 's', 'logo' );

		$this->assertTrue( $this->es_query->is_media_search( $query ) );
	}

	public function test_is_media_search_without_search_term(): void {
		$query = new \WP_Query();
		$query->set( 'post_type', 'attachment' );

		$this->assertFalse( $this->es_query->is_media_search( $query ) );
	}

	public function test_is_media_search_with_non_attachment_query(): void {
		$query = new \WP_Query();
		$query->set( 'post_type', 'post' );
		$query->set( 's', 'logo' );

		$this->assertFalse( $this->es_query->is_media_search( $query ) );
	}

	public function test_es_not_available_by_default(): void {
		$this->assertFalse( $this->es_query->is_es_available() );
	}

	public function test_es_available_via_filter(): void {
		add_filter( 'mle_es_available', '__return_true' );
		$this->assertTrue( $this->es_query->is_es_available() );
		remove_filter( 'mle_es_available', '__return_true' );
	}
}

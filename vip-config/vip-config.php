<?php
/**
 * VIP Platform Configuration.
 *
 * Loaded by WordPress VIP environments (production, staging, and vip dev-env).
 * Place platform-level constants and feature flags here.
 *
 * @package MediaLibraryEnhance
 */

// Enable Enterprise Search (Elasticsearch) for this application.
define( 'VIP_ENABLE_VIP_SEARCH', true );

// Route eligible WP_Query requests to Elasticsearch automatically.
define( 'VIP_ENABLE_VIP_SEARCH_QUERY_INTEGRATION', true );

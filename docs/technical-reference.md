# Media Library Enhance — Technical Reference

**Version:** 0.1.0-alpha  
**Requires:** WordPress 6.7+, PHP 8.1+  
**Audience:** Developers, DevOps, and site administrators

---

## Table of Contents

1. [Architecture Overview](#architecture-overview)
2. [Installation](#installation)
3. [Directory Structure](#directory-structure)
4. [PHP Class Reference](#php-class-reference)
5. [REST API Reference](#rest-api-reference)
6. [Hooks Reference (Actions & Filters)](#hooks-reference)
7. [Custom Taxonomy: `media_tag`](#custom-taxonomy-media_tag)
8. [Post Meta Keys](#post-meta-keys)
9. [JavaScript Modules](#javascript-modules)
10. [CLI Reference](#cli-reference)
11. [Admin Status Page](#admin-status-page)
12. [Elasticsearch Integration](#elasticsearch-integration)
13. [VIP Platform Notes](#vip-platform-notes)
14. [Testing](#testing)
15. [Building Assets](#building-assets)

---

## Architecture Overview

Media Library Enhance is a **Gutenberg-first** plugin with no dependency on the classic Backbone `wp.media` modal. This is an intentional choice: Phase 3 of WordPress's media modernisation effort will replace that modal, so features built on it would need to be rewritten. All editor-facing functionality is implemented as block editor extensions using the `@wordpress/` package ecosystem.

On the backend, all functionality is exposed through the WordPress REST API (namespace `mle/v1`) so that the React frontend and WP-CLI commands share the same code paths.

### Feature modules

| Feature | Backend entry point | Frontend entry point |
|---------|--------------------|--------------------|
| Elasticsearch search | `includes/search/` | (no frontend — transparent) |
| Media tagging | `includes/taxonomies/` | `src/tags-panel/` |
| Duplicate detection | `includes/duplicates/` | `src/duplicate-notice/` |
| Image replacement | `includes/replacement/` | `src/replace-everywhere/` |

### Bootstrap sequence

1. The main plugin file (`media-library-enhance.php`) registers an `spl_autoload_register` handler that maps the `MediaLibraryEnhance\` namespace to `includes/` using the convention `Foo\Bar_Baz` → `includes/foo/class-bar-baz.php`.
2. On the `plugins_loaded` action, `bootstrap()` instantiates each module via its static `instance()` factory and calls `register()` on each one. Modules register all their own hooks.
3. WP-CLI commands are registered in the same `bootstrap()` function, guarded by `defined('WP_CLI') && WP_CLI`.

---

## Installation

### Standard WordPress

1. Copy the plugin directory to `wp-content/plugins/media-library-enhance/`.
2. Run `composer install --no-dev` from the plugin directory to set up the autoloader.
3. Run `npm ci && npm run build` to compile JavaScript assets.
4. Activate the plugin via **Plugins → Installed Plugins**.
5. Visit **Tools → Media Library Enhance** to confirm the plugin loaded correctly.

### WordPress VIP

Follow steps 1–4 above, then add the VIP Elasticsearch configuration to `vip-config/vip-config.php`:

```php
define( 'VIP_ENABLE_VIP_SEARCH', true );
define( 'VIP_ENABLE_VIP_SEARCH_QUERY_INTEGRATION', true );
```

The plugin detects VIP Enterprise Search automatically via the `mle_es_available` filter (which the `vip-config.php` file controls). See [Elasticsearch Integration](#elasticsearch-integration).

### Local development (wp-env)

```bash
npm install
npm run wp-env:start   # Starts a local WordPress environment on port 8888
npm run build          # Compile JavaScript
```

---

## Directory Structure

```
media-library-enhance/
├── media-library-enhance.php   # Plugin entry point, autoloader, bootstrap
├── composer.json               # PHP autoload and dev dependencies
├── package.json                # npm scripts and JS dependencies
├── phpunit.xml.dist            # PHPUnit configuration
├── playwright.config.ts        # Playwright E2E configuration
├── .wp-env.json                # wp-env local environment config
├── vip-config/
│   └── vip-config.php          # VIP Elasticsearch constants
├── includes/
│   ├── admin/
│   │   ├── class-status-page.php       # Tools → Media Library Enhance page
│   │   └── class-editor-assets.php     # Enqueue built JS in block editor
│   ├── search/
│   │   ├── class-elasticsearch-query.php  # Route WP_Query to ES, perf fixes
│   │   └── class-rest-search.php          # GET /mle/v1/search endpoint
│   ├── taxonomies/
│   │   ├── class-media-taxonomies.php     # Register media_tag taxonomy
│   │   └── class-rest-taxonomies.php      # POST /mle/v1/taxonomies/bulk-assign
│   ├── replacement/
│   │   ├── class-image-replacer.php       # Replace attachment file on disk
│   │   ├── class-rest-replacement.php     # POST /mle/v1/replace/{id}
│   │   └── class-usage-tracker.php        # Index which posts use each attachment
│   ├── duplicates/
│   │   ├── class-hash-generator.php       # Compute MD5 and perceptual (aHash) hashes
│   │   ├── class-duplicate-finder.php     # Query for exact / similar duplicates
│   │   └── class-rest-duplicates.php      # GET /mle/v1/duplicates/* endpoints
│   └── cli/
│       ├── class-search-command.php       # wp mle search
│       ├── class-usage-command.php        # wp mle usage
│       ├── class-duplicates-command.php   # wp mle duplicates
│       └── class-taxonomies-command.php   # wp mle taxonomies
├── src/
│   ├── index.js                           # JS entry point (imports all three features)
│   ├── tags-panel/                        # Sidebar tag editor for image blocks
│   ├── duplicate-notice/                  # Upload duplicate warning + modal
│   └── replace-everywhere/               # Toolbar "replace everywhere" button + modal
├── build/                                 # Compiled JS/CSS (not committed to VCS)
├── tests/
│   ├── e2e/                               # Playwright end-to-end tests
│   └── {search,taxonomies,duplicates,replacement}/  # PHPUnit unit tests
├── docs/                                  # Documentation (you are here)
└── planning/
    └── BACKGROUND.md                      # Customer pain points & design rationale
```

---

## PHP Class Reference

All classes follow the **singleton pattern**:

```php
private static ?self $instance = null;

public static function instance(): self {
    if ( null === self::$instance ) {
        self::$instance = new self();
    }
    return self::$instance;
}

public function register(): void { /* register hooks here */ }
```

### `MediaLibraryEnhance\Search\Elasticsearch_Query`

Routes `WP_Query` attachment queries to VIP Enterprise Search (Elasticsearch) when available.

- `register()` — hooks `pre_get_posts` and `posts_clauses`
- `maybe_route_to_es( WP_Query $query )` — returns `true` if the query should go to ES
- Fires `mle_query_routed_to_es` action when routing occurs
- Disables `SQL_CALC_FOUND_ROWS` for all attachment queries regardless of ES availability

### `MediaLibraryEnhance\Search\REST_Search`

Registers and handles `GET /mle/v1/search`.

- `register_routes()` — called on `rest_api_init`
- `handle_search( WP_REST_Request $request )` — main handler
- Delegates to `Elasticsearch_Query` for routing; returns standardised attachment objects

### `MediaLibraryEnhance\Taxonomies\Media_Taxonomies`

Registers the `media_tag` taxonomy on the `attachment` post type.

- `register()` — hooks `init`
- `register_taxonomies()` — calls `register_taxonomy()` with REST enabled, flat (non-hierarchical)

### `MediaLibraryEnhance\Taxonomies\REST_Taxonomies`

Registers and handles `POST /mle/v1/taxonomies/bulk-assign`.

- `handle_bulk_assign( WP_REST_Request $request )` — processes batched term assignments

### `MediaLibraryEnhance\Replacement\Usage_Tracker`

Maintains the `_mle_used_in_posts` index (attachment → post IDs array).

- `register()` — hooks `save_post`, `delete_post`
- `extract_attachment_ids( int $post_id )` — parses post content for attachment references
- `rebuild( array $options )` — batch rebuild (used by CLI and admin)

### `MediaLibraryEnhance\Replacement\Image_Replacer`

Performs the on-disk file replacement.

- `replace( int $attachment_id, array $file )` — swaps the file, updates metadata
- Fires `mle_attachment_replaced` after a successful replacement
- Fires `mle_purge_attachment_cache` so VIP CDN caches can be purged

### `MediaLibraryEnhance\Replacement\REST_Replacement`

Registers and handles `POST /mle/v1/replace/{id}`.

- Delegates to `Image_Replacer::replace()`, returns updated attachment data

### `MediaLibraryEnhance\Duplicates\Hash_Generator`

Generates file hashes and stores them in post meta.

- `generate_file_hash( int $attachment_id )` — MD5 of raw file bytes → `_mle_file_hash`
- `compute_perceptual_hash( int $attachment_id )` — 8×8 aHash → 16-char hex → `_mle_perceptual_hash`
- `register()` — hooks `add_attachment` to hash new uploads automatically

### `MediaLibraryEnhance\Duplicates\Duplicate_Finder`

Queries the database for exact and similar duplicates.

- `find_exact_duplicates( int $attachment_id )` — postmeta query on `_mle_file_hash`
- `find_similar( int $attachment_id, int $threshold )` — fetches all perceptual hashes, computes Hamming distance in PHP, filters by `$threshold`

### `MediaLibraryEnhance\Duplicates\REST_Duplicates`

Registers and handles the duplicate-detection REST endpoints.

- `handle_check_on_upload( WP_REST_Request $request )` — called by the JS after a new upload; returns exact + similar duplicates in one response

### `MediaLibraryEnhance\Admin\Status_Page`

Renders the **Tools → Media Library Enhance** admin page.

- `register()` — hooks `admin_menu`
- `collect_stats()` — gathers counts and status flags from other modules
- `render()` — outputs the HTML status dashboard

### `MediaLibraryEnhance\Admin\Editor_Assets`

Enqueues the compiled JavaScript bundle in the block editor.

- `register()` — hooks `enqueue_block_editor_assets`
- `enqueue()` — calls `wp_enqueue_script` with the `build/index.js` bundle

---

## REST API Reference

**Base namespace:** `mle/v1`  
**Base URL:** `{site_url}/wp-json/mle/v1/`  
**Authentication:** Cookie nonce (block editor) or Application Password  
**Required capability:** `upload_files` for all endpoints

---

### `GET /mle/v1/search`

Search media attachments, routed to Elasticsearch when available.

**Query parameters**

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| `search` | string | — | **Required.** Search term |
| `per_page` | integer | 40 | Results per page (max 100) |
| `page` | integer | 1 | Page number |
| `mime_type` | string | — | Filter by MIME type (e.g. `image/jpeg`) |
| `media_tag` | string | — | Filter by `media_tag` slug |
| `orderby` | string | `date` | `date`, `title`, `relevance`, `modified` |
| `order` | string | `DESC` | `ASC` or `DESC` |

**Response headers**

| Header | Description |
|--------|-------------|
| `X-WP-Total` | Total matching attachments |
| `X-WP-TotalPages` | Total pages |
| `X-MLE-Search-Engine` | `elasticsearch` or `mysql` |

**Response body** — array of attachment objects:

```json
[
  {
    "id": 123,
    "title": "Product hero shot",
    "caption": "",
    "alt": "Blue widget on white background",
    "mime_type": "image/jpeg",
    "url": "https://example.com/wp-content/uploads/2026/04/widget.jpg",
    "date": "2026-04-10T12:00:00",
    "width": 1920,
    "height": 1080,
    "filesizeInBytes": 245760
  }
]
```

---

### `GET /mle/v1/usage/{id}`

Get the list of posts that use a specific attachment.

**Path parameters**

| Parameter | Type | Description |
|-----------|------|-------------|
| `id` | integer | Attachment ID |

**Response body**

```json
{
  "post_count": 42,
  "posts": [
    { "id": 5, "title": "Our Products", "edit_url": "https://example.com/wp-admin/post.php?post=5&action=edit" }
  ]
}
```

> `posts` contains the first 25 posts. `post_count` reflects the full count.

---

### `POST /mle/v1/replace/{id}`

Replace an attachment's file. Keeps the attachment ID and URL structure intact.

**Path parameters**

| Parameter | Type | Description |
|-----------|------|-------------|
| `id` | integer | Attachment ID to replace |

**Request body** — `multipart/form-data`

| Field | Type | Description |
|-------|------|-------------|
| `file` | file | **Required.** The replacement image file |

**Response body** (success)

```json
{
  "success": true,
  "attachment": {
    "id": 123,
    "url": "https://example.com/wp-content/uploads/2026/04/widget.jpg"
  }
}
```

**Response** (error) — standard `WP_Error` JSON

---

### `POST /mle/v1/taxonomies/bulk-assign`

Assign taxonomy terms to multiple attachments in one request.

**Request body** (JSON)

```json
{
  "attachment_ids": [123, 456, 789],
  "taxonomy": "media_tag",
  "terms": [1, 2, 3],
  "append": true
}
```

| Field | Type | Description |
|-------|------|-------------|
| `attachment_ids` | integer[] | **Required.** Attachment IDs to update |
| `taxonomy` | string | **Required.** Taxonomy slug (e.g. `media_tag`) |
| `terms` | integer[] | **Required.** Term IDs to assign |
| `append` | boolean | `true` = add to existing terms; `false` = replace |

**Response body**

```json
{
  "results": {
    "123": { "success": true, "terms": [1, 2, 3] },
    "456": { "success": false, "error": "Attachment not found" }
  }
}
```

---

### `GET /mle/v1/duplicates/{id}`

Find exact duplicates of an attachment (same MD5 hash).

**Path parameters**

| Parameter | Type | Description |
|-----------|------|-------------|
| `id` | integer | Attachment ID to check |

**Response body**

```json
{
  "attachment_id": 123,
  "duplicates": [
    { "id": 456, "title": "widget copy", "url": "..." }
  ],
  "count": 1
}
```

---

### `GET /mle/v1/duplicates/{id}/similar`

Find visually similar images using perceptual hashing.

**Path parameters**

| Parameter | Type | Description |
|-----------|------|-------------|
| `id` | integer | Attachment ID to check |

**Query parameters**

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| `threshold` | integer | 10 | Max Hamming distance (0–64). Lower = more similar. 0 = exact perceptual match. |

**Response body**

```json
{
  "attachment_id": 123,
  "threshold": 10,
  "similar": [
    { "id": 789, "title": "widget retouched", "url": "...", "distance": 4 }
  ],
  "count": 1
}
```

---

### `GET /mle/v1/duplicates/check-on-upload`

Check a freshly uploaded attachment for exact and similar duplicates. Called automatically by the block editor after each upload.

**Query parameters**

| Parameter | Type | Description |
|-----------|------|-------------|
| `attachment_id` | integer | **Required.** The just-uploaded attachment ID |

**Response body**

```json
{
  "attachment_id": 999,
  "threshold": 0,
  "exact": [ { "id": 123, ... } ],
  "similar": []
}
```

The `threshold` value is determined by the `mle_duplicate_warning_threshold` filter (default `0`).

---

## Hooks Reference

### Actions

#### `mle_query_routed_to_es`

Fired when a `WP_Query` for attachments is routed to Elasticsearch.

```php
do_action( 'mle_query_routed_to_es', WP_Query $query );
```

Use this to log or instrument search performance.

---

#### `mle_attachment_replaced`

Fired after an attachment's file has been successfully replaced.

```php
do_action( 'mle_attachment_replaced', int $attachment_id, string $old_file, array $old_metadata );
```

| Parameter | Type | Description |
|-----------|------|-------------|
| `$attachment_id` | int | The attachment that was replaced |
| `$old_file` | string | Absolute filesystem path to the old file |
| `$old_metadata` | array | `wp_get_attachment_metadata()` value before replacement |

---

#### `mle_purge_attachment_cache`

Fired when attachment CDN caches should be purged after a file replacement.

```php
do_action( 'mle_purge_attachment_cache', int $attachment_id, string $url );
```

On WordPress VIP, this is connected to the VIP CDN purge mechanism automatically. On other hosts, hook into this to trigger your own cache invalidation:

```php
add_action( 'mle_purge_attachment_cache', function( int $id, string $url ) {
    my_cdn_purge( $url );
}, 10, 2 );
```

---

### Filters

#### `mle_es_available`

Override the Elasticsearch availability check.

```php
apply_filters( 'mle_es_available', bool $available );
```

Default: `false`. Set to `true` when VIP Enterprise Search constants are present. You can also use this filter in non-VIP environments to connect a custom Elasticsearch proxy:

```php
add_filter( 'mle_es_available', '__return_true' );
```

---

#### `mle_duplicate_warning_threshold`

Control the Hamming distance threshold used for the block editor duplicate warning on upload.

```php
apply_filters( 'mle_duplicate_warning_threshold', int $threshold );
```

Default: `0` (exact duplicates only — the perceptual hash matches must be identical).  
Set to a higher value (e.g. `10`) to also warn about visually similar images:

```php
add_filter( 'mle_duplicate_warning_threshold', fn() => 10 );
```

A threshold of `0–5` catches near-identical images (slightly different compression). Values above `20` may produce false positives on unrelated images.

---

## Custom Taxonomy: `media_tag`

| Property | Value |
|----------|-------|
| Registered for | `attachment` post type |
| Hierarchical | No (flat, like post tags) |
| REST API | Enabled — accessible at `/wp/v2/media_tag` |
| Required capability | `upload_files` to assign; `manage_options` to manage terms |
| `show_ui` | `true` |
| `show_in_quick_edit` | `false` |

Terms are stored in the standard WordPress taxonomy tables (`wp_terms`, `wp_term_taxonomy`, `wp_term_relationships`). No custom tables are created.

---

## Post Meta Keys

| Meta Key | Type | Description |
|----------|------|-------------|
| `_mle_file_hash` | string | MD5 hex digest of the raw file bytes. Used for exact duplicate detection. |
| `_mle_perceptual_hash` | string | 16-character hex representation of an 8×8 aHash. Used for visual similarity detection. |
| `_mle_used_in_posts` | JSON array | Array of post IDs whose content references this attachment. Updated on `save_post` and `delete_post`. |

All meta keys are prefixed with `_mle_` and are private (not shown in the custom fields UI by default).

---

## JavaScript Modules

All JavaScript is compiled from `src/` to `build/` via `@wordpress/scripts` (Webpack). The compiled bundle is enqueued only in the block editor. There are no front-end scripts.

### `src/index.js`

Entry point. Imports the three feature modules; each module self-registers its block editor filters on import.

### `src/tags-panel/`

Hooks: `editor.BlockEdit` (via `addFilter`)  
Applies to: `core/image`, `core/cover`, `core/media-text`, `core/gallery`

Renders a `<FormTokenField>` inside the `<InspectorControls>` sidebar panel labelled "Tags". Reads and writes tags via the core `/wp/v2/media_tag` REST endpoint and the `core/data` store. Creates missing terms via a `POST` to the terms endpoint if the user types a tag name that does not exist.

### `src/duplicate-notice/`

Uses `subscribe()` from `@wordpress/data` to watch the `core/block-editor` store. On each store change, walks the block tree and collects new attachment IDs (IDs not seen in previous renders). New IDs are batched with a 100ms debounce and sent to `GET /mle/v1/duplicates/check-on-upload`. When duplicates are found, dispatches a `WARNING` notice to `core/notices` with three action buttons. The "Compare" action renders a portal-based modal with side-by-side image previews.

### `src/replace-everywhere/`

Hooks: `editor.BlockEdit` (via `addFilter`)  
Applies to: `core/image` only

Adds a `<BlockControls>` toolbar button (icon: `image-rotate`) to image blocks. Clicking it opens a modal that fetches usage data from `GET /mle/v1/usage/{id}` and renders a hidden `<input type="file">`. On file selection, POSTs the file to `POST /mle/v1/replace/{id}` using the native `fetch()` API (not `apiFetch`) to support `multipart/form-data` cleanly. After a successful replacement the block attribute is updated with a cache-busted URL.

---

## CLI Reference

All commands are under the `wp mle` namespace. Requires WP-CLI.

---

### `wp mle search`

#### `wp mle search status`

Print Elasticsearch availability and total attachment count.

```
$ wp mle search status
```

#### `wp mle search test <search_term>`

Run a test search and report the engine used, result count, and execution time. Warns if the query exceeds the 2-second target.

```
$ wp mle search test "product hero"
Engine: elasticsearch
Results: 58
Time: 0.43s
```

---

### `wp mle usage`

#### `wp mle usage rebuild [--batch-size=<n>] [--dry-run]`

Rebuild the `_mle_used_in_posts` index for all posts.

| Flag | Default | Description |
|------|---------|-------------|
| `--batch-size` | 100 | Posts processed per batch |
| `--dry-run` | — | Preview without writing |

Calls `stop_the_insanity()` between batches to prevent object cache OOM on VIP.

#### `wp mle usage show <attachment_id>`

Display which posts reference a specific attachment.

```
$ wp mle usage show 123
+-----+-------------------+---------+----------+
| ID  | Title             | Type    | Status   |
+-----+-------------------+---------+----------+
| 42  | Our Products      | page    | publish  |
+-----+-------------------+---------+----------+
```

---

### `wp mle duplicates`

#### `wp mle duplicates hash [--batch-size=<n>] [--type=<type>]`

Generate hashes for all attachments that do not yet have them.

| Flag | Default | Description |
|------|---------|-------------|
| `--batch-size` | 100 | Attachments per batch |
| `--type` | `all` | `file` (MD5 only), `perceptual` (aHash only), or `all` |

Displays a progress bar. Reports counts of hashed and failed attachments at the end.

#### `wp mle duplicates scan [--format=<format>]`

Find all exact duplicate groups across the media library.

| Flag | Default | Description |
|------|---------|-------------|
| `--format` | `table` | `table`, `json`, or `csv` |

Uses a PHP generator internally, so memory usage stays flat regardless of library size.

#### `wp mle duplicates check <attachment_id> [--similar]`

Check one attachment for duplicates.

| Flag | Description |
|------|-------------|
| `--similar` | Also check for visually similar matches using perceptual hashing |

---

### `wp mle taxonomies`

#### `wp mle taxonomies assign <taxonomy> <term> [options]`

Bulk-assign a term to attachments matching the given filters. Creates the term if it does not already exist.

| Flag | Default | Description |
|------|---------|-------------|
| `--mime-type` | — | Filter attachments by MIME type |
| `--date-before` | — | Filter by upload date (Y-m-d) |
| `--date-after` | — | Filter by upload date (Y-m-d) |
| `--batch-size` | 100 | Attachments per batch |
| `--dry-run` | — | Preview without writing |

Example — tag all JPEGs uploaded in Q1 2026:

```
$ wp mle taxonomies assign media_tag "archive-q1-2026" \
    --mime-type=image/jpeg \
    --date-after=2026-01-01 \
    --date-before=2026-04-01
```

#### `wp mle taxonomies stats`

Print a table of all `media_tag` terms with their attachment counts, plus a summary of tagged vs. untagged coverage.

#### `wp mle taxonomies migrate-categories-to-tags [options]`

One-time migration from a legacy `media_category` taxonomy to `media_tag`. Appends tags and preserves existing ones.

| Flag | Description |
|------|-------------|
| `--dry-run` | Preview without writing |
| `--delete-categories` | Remove source category terms after migration |
| `--batch-size` | Default 200 |

---

## Admin Status Page

Location: **Tools → Media Library Enhance**  
Required capability: `manage_options`

This page is read-only. It shows the current health of each plugin feature:

| Section | What it shows |
|---------|---------------|
| Search (Elasticsearch) | ES availability, total attachment count, CLI hint |
| Duplicate Detection | Percentage of attachments with file hashes, percentage with perceptual hashes, CLI hint |
| Usage Tracking | Number of attachments with usage index, CLI hint |
| Taxonomies | Total `media_tag` terms, CLI hint |

All maintenance operations (rebuilding the usage index, generating missing hashes, bulk-tagging) must be performed via WP-CLI — there is no UI for them.

---

## Elasticsearch Integration

### How routing works

The `Elasticsearch_Query` class hooks into `pre_get_posts`. When a `WP_Query` is for attachments and the `mle_es_available` filter returns `true`, the class sets the `es` query var on the query object. VIP Enterprise Search then intercepts the query and routes it to Elasticsearch.

No direct HTTP requests are made to Elasticsearch — the plugin relies entirely on the VIP platform layer.

### Fallback behaviour

When Elasticsearch is unavailable, queries fall back to MySQL automatically. The `SQL_CALC_FOUND_ROWS` optimisation (disabling the slow row count query) is applied to all attachment queries regardless of ES availability.

### Performance

- Elasticsearch path: typically < 500ms for large libraries
- MySQL path (with `SQL_CALC_FOUND_ROWS` disabled): faster than stock WordPress, but slower than ES for very large libraries
- The `X-MLE-Search-Engine` response header tells you which path was used for a given request

---

## VIP Platform Notes

### `stop_the_insanity()`

All WP-CLI batch loops in this plugin call `stop_the_insanity()` between batches. This clears WordPress's in-memory object cache (the `$wpdb` query log, post cache, etc.), which prevents out-of-memory errors when processing thousands of attachments. **Do not remove these calls.**

### CDN cache purge

After `Image_Replacer::replace()` runs, the plugin fires `mle_purge_attachment_cache`. On VIP environments the VIP platform's cache purge mechanism hooks into this automatically. On non-VIP environments you must hook in manually (see [Hooks Reference](#hooks-reference)).

### Conflicting plugins

The following plugins conflict with the Elasticsearch query routing and must not be active alongside Media Library Enhance on a VIP site:

- `es-admin`
- `es-wp-query`

Both plugins hook into the same query integration point and will interfere with ES routing.

---

## Testing

### PHPUnit (unit tests)

```bash
composer install
composer test
```

Test files are in `tests/{search,taxonomies,duplicates,replacement}/`. The bootstrap file at `tests/bootstrap.php` loads the WordPress test suite.

### Playwright (end-to-end tests)

```bash
npm run wp-env:start       # Start wp-env on port 8888
npm run build              # Compile JavaScript
npm run test:e2e           # Run all E2E tests headlessly
npm run test:e2e:ui        # Run in interactive (headed) mode
```

E2E tests are in `tests/e2e/`. They cover all REST endpoints and all three editor features (tags panel, duplicate notice, replace everywhere). Storage states (authenticated browser sessions) are stored in `artifacts/`.

### Code standards

```bash
composer lint              # Check PHP against VIP coding standards
composer lint:fix          # Auto-fix fixable violations
```

---

## Building Assets

```bash
npm run build              # Production build to build/
npm run start              # Development build with file watcher
```

The build uses `@wordpress/scripts` (Webpack). The entry point is `src/index.js`. Output is written to `build/index.js` and `build/index.asset.php` (the asset file contains the dependency array and version hash used by `wp_enqueue_script`).

The `build/` directory is not committed to version control. It must be built before activating the plugin.

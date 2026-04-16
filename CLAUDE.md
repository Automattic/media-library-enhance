# Media Library Enhance

Two-week sprint (April 22 – May 6, 2026) proving enterprise media library improvements. Code moves to WordPress Core or productized features afterward. **Team:** Jacob Smith, Alec Geatches.

## Why decisions were made

- **Backend-first, API-first.** WordPress Core is replacing the entire Backbone.js media modal with React/DataViews (Phase 3). Any modal customization is throwaway. Everything here must survive that transition.
- **VIP Enterprise Search, not a custom ES client.** Uses the `es` query var and `VIP_ENABLE_VIP_SEARCH` constant to opt queries into the platform's existing Elasticsearch. The integration is a WP_Query interceptor, not a separate search layer.
- **Taxonomies, not filesystem folders.** Community consensus from Trac #47839. One image can live in multiple categories. DataViews already supports taxonomy filtering. No files move on disk.
- **Postmeta for usage tracking.** `_mle_used_in_posts` is the fastest approach to validate. A future iteration should use a dedicated relationships table (Trac #14513) for scale.
- **Average hash (aHash) for perceptual duplicate detection.** Uses GD only — no external dependencies. Good enough for obvious duplicates. dHash/pHash are future improvements if precision matters.

## Landmines

- **`stop_the_insanity()`** is required in all WP-CLI batch commands. VIP's object cache will OOM on large libraries without it. Every CLI command that loops over posts must call it between batches.
- **`SQL_CALC_FOUND_ROWS`** is the root cause of slow media queries at scale. WordPress uses it by default. The search module disables it for attachment queries.
- **`es-admin` and `es-wp-query` plugins conflict** with our ES routing on VIP. They must be disabled if present. Document this if onboarding a new test site.
- **The `pre_get_posts` hook modifies non-main queries intentionally.** The PHPCS warning is suppressed inline — don't remove those suppressions.

## Local development

Two environments because Elasticsearch can't run in wp-env:

- **`npm run wp-env:start`** — fast iteration, no ES, MySQL fallback. Admin: `localhost:8888` (admin/password).
- **VIP dev-env** — full stack with Elasticsearch. Requires VIP-CLI + Docker. Run `npm run vip:create` once, then `npm run vip:start`, then `npm run vip:index` to build the ES index. See `vip-config/vip-config.php` for the ES constants.

## Testing

- **Lint:** `composer lint` (WordPress-VIP-Go + PHPCompatibilityWP)
- **Unit:** `WP_TESTS_DIR=/path/to/wp-tests composer test`
- **E2E:** `npm run wp-env:start && npm run test:e2e` (Playwright against wp-env)

## Background

See `planning/background.md` for customer pain points, prior art, Core ticket references, and the gap analysis between Phase 3 and enterprise needs.

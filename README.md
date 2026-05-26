# Media Library Enhance

> ## ⚠️ AI-Driven Exploration — Not a Tested Solution
>
> This repository is an **AI-generated exploration** of enterprise media library improvements for WordPress. The code has **not been tested in production**, has **not been reviewed for security**, and should **not be deployed as-is**.
>
> Treat this as a **resource** for ideas, patterns, and architectural direction — **not a solution**. Anything you reuse should be re-implemented and tested independently.

The original goal was to prove four enterprise media library improvements for WordPress VIP:

- **Elasticsearch-powered media search** via VIP Enterprise Search (`es` query var, not a custom client).
- **In-place image replacement** that preserves the attachment ID and re-points all usages.
- **Perceptual duplicate detection** using average hash (aHash) on upload — GD only, no external deps.
- **Tag-based media organization** via a flat `media_tag` taxonomy on `attachment`.

## Design decisions

- **Block-editor extensions, not the Backbone `wp.media` modal.** Core is replacing the modal with React/DataViews in Phase 3 — this work targets the layer that survives.
- **Editor-surfaced UX.** Editors live in Gutenberg; the only admin page is a read-only status dashboard at Tools → Media Library Enhance.
- **Postmeta for usage tracking** (`_mle_used_in_posts`) for sprint speed. A relationships table (Trac #14513) is the right long-term answer.

See `planning/BACKGROUND.md` for customer pain points, prior art, and Core ticket references. See `CLAUDE.md` for the full context and landmines.

## Local development

```bash
npm install
composer install

# Fast iteration, no Elasticsearch (MySQL fallback)
npm run wp-env:start            # admin/password at localhost:8888
npm run start                   # JS watch mode

# Full stack with Elasticsearch (requires VIP-CLI + Docker)
npm run vip:create              # one time
npm run vip:start
npm run vip:index
```

## Testing

```bash
composer lint                                       # WordPress-VIP-Go + PHPCompatibilityWP
WP_TESTS_DIR=/path/to/wp-tests composer test        # PHPUnit
npm run wp-env:start && npm run test:e2e            # Playwright
```

`build/` is gitignored — run `npm run build` before tagging a release.

## License

GPL-2.0-or-later

# Making the Media Library Great: Background & Research

**Project:** Radical Speed Month - Media Library Improvements
**Team:** Jacob Smith, Alec Geatches
**Timeline:** Two weeks (April 22 - May 6, 2026)
**Last Updated:** April 15, 2026

---

## Project Goals

We're building a WordPress plugin to prove what's possible for enterprise media library functionality in two weeks. The goals:

1. **Reduce search time for large media libraries from 15-30 seconds to under 2 seconds**
2. **Reduce reliance on third-party plugins for basic media library functionality** by building essential features directly

After two weeks, we'll move the code either into WordPress Core (if it makes architectural sense) or into productized features. This is about rapid validation with real customer data, not maintaining a plugin forever.

### Feature Priority Order

1. **Search** - Elasticsearch-powered fast search at scale
2. **Image Replacement** - Swap out an image everywhere it's used
3. **Duplicate Detection** - Find and merge duplicate uploads
4. **Taxonomies** - Categories/tags for media organization

---

## The Customer Pain

### Enterprise Customers Affected

Multiple large enterprise WordPress sites have independently raised serious frustrations with the media library at scale:

- Major news publishers with 5M-75M images
- Large universities with extensive media archives
- Global consulting firms managing client assets
- International telecommunications companies
- Digital media companies with decades of content

### Specific Pain Points

**Search Performance at Scale:**
- **500K-1M images:** 5-15 second search response times
- **1M-5M images:** 15-30+ second search response times
- **5M+ images:** Timeouts, failed searches, blocked editor workflows

**Technical Root Causes:**
- WordPress `SQL_CALC_FOUND_ROWS` creates massive overhead
- Multiple status checks per query (`post_status` validation)
- Search across title+excerpt+content in MySQL doesn't scale
- No Elasticsearch integration for media queries

**Missing Basic Functionality:**
- No organizational structure (tags, categories, folders)
- No bulk operations (can't bulk delete, can't assess staleness)
- Can't track where images are used across posts
- No duplicate detection or management
- No metadata governance or enforcement

**Current Workarounds:**
- Custom code solutions from hosting providers
- Professional services engagements to implement Elasticsearch
- Third-party plugins (Media Library Assistant, FileBird, etc.)
- Manual database queries and exports

---

## What WordPress Core Is Doing

### Phase 3: Collaboration - Media Library

WordPress Core is undertaking a fundamental re-architecture of the Media Library under [Gutenberg Phase 3](https://github.com/WordPress/gutenberg/issues/55238). **Key insight: This is primarily a UI modernization effort, not a backend/performance fix.**

**What's Being Built:**
- Replace Backbone.js media modal with React-based DataViews/DataForm
- Media Editor for in-place editing (crop, metadata, alt text)
- Client-side media processing (WASM-based compression, transcoding)
- New uploading states and modal UX improvements
- Exploring multi-parent attachment relationships

**What's NOT Being Addressed:**
- Search performance at enterprise scale
- SQL query optimization
- Organizational structure (categories/tags listed as "iteration 2", no active work)
- Bulk operations
- Metadata governance
- Usage tracking
- DAM capabilities

### WordPress 7.1 Planning

**Media work in 7.1 scope:**
- Client-side media processing iteration ([#76756](https://github.com/WordPress/gutenberg/issues/76756))
- Touch points between modal and editor ([#73085](https://github.com/WordPress/gutenberg/issues/73085))
- Exploring attachment "attached to" parents ([#66663](https://github.com/WordPress/gutenberg/issues/66663))
- Dynamic galleries based on attached posts

**What Core Contributors Have Asked For:**
1. File Trac tickets for enterprise pain points (especially SQL performance)
2. Contribute to [Trac #47839](https://core.trac.wordpress.org/ticket/47839) (Extended file management)
3. Link workarounds to tickets when providing per-customer patches

---

## Gap Analysis: Phase 3 vs. Enterprise Needs

| Enterprise Need | Phase 3 Coverage | Status |
|---|---|---|
| Search performance at scale | ❌ Not addressed | MySQL queries unchanged |
| Organizational structure (tags/categories/folders) | 🟡 Barely addressed | Listed as "iteration 2", no active work |
| Bulk operations | ❌ Not addressed | Not mentioned |
| Metadata governance | ❌ Not addressed | DataForm enables editing, not enforcement |
| Usage tracking ("where is this used?") | 🟡 Spike only | [#66663](https://github.com/WordPress/gutenberg/issues/66663) is investigatory |
| Duplicate detection | ❌ Not addressed | Not mentioned |
| DAM capabilities | ❌ Not addressed | Completely out of scope |

**Conclusion:** Phase 3 builds UI plumbing but doesn't solve the enterprise operational problems.

---

## What Will Survive the Phase 3 Transition

Critical strategic insight: Core is replacing the entire Backbone.js media modal with React/DataViews. This determines where we should invest effort.

### ✅ WILL Survive Phase 3

- **Backend/SQL query fixes** - Elasticsearch integration, query optimization
- **Data model additions** - Categories/tags as taxonomies on `attachment` post type
- **REST API enhancements** - New endpoints, search parameters
- **WP-CLI commands** - Bulk operations, monitoring, management
- **Plugin-level solutions** - Independent of UI layer

### ❌ Will NOT Survive Phase 3

- **Backbone.js modal customizations** - Entire `wp.media` framework being replaced
- **PHP hooks into old modal** - `attachment_fields_to_edit` needs remapping
- **Old modal layout/UX changes** - All throwaway work

**Strategy:** Focus on backend-first, API-first solutions that will survive the UI transition.

---

## Prior Art: What's Been Proven

### Elasticsearch-Powered Media Search (12M Images)

A large news publisher successfully implemented Elasticsearch-powered media search in early 2026:

**Performance Results:**
- Search query execution: **< 500ms** (Elasticsearch layer)
- Total modal response time: **< 5 seconds** (including attachment hydration)
- Indexing speed: 12M images indexed in 3-4 days

**What Required Manual Work (opportunities for automation):**
- Enabling `protected_content` via WP-CLI (on WordPress VIP)
- Disabling conflicting plugins (`es-admin`, `es-wp-query`)
- Running full reindex via WP-CLI
- Custom code to route media queries to Elasticsearch
- Batched attachment hydration after ES returns IDs

**Customer Feedback:**
- Search now usable, workflow unblocked
- Zero performance regressions post-deployment

**Key Learning:** Newest-to-oldest indexing approach meant editors could search recent uploads within hours, not days.

---

## Technical Context

### Search Performance: The Root Problem

**Why WordPress search is slow at scale:**
1. `SQL_CALC_FOUND_ROWS` modifier forces MySQL to scan entire result set even when limiting to 20 results
2. Multiple `post_status` validation queries per search
3. `LIKE` queries across `post_title`, `post_excerpt`, `post_content` don't use indexes
4. No query result caching for media searches

**The Elasticsearch Solution:**
- Industry standard for search at this scale
- Sub-500ms query execution even at 12M+ images
- Supports relevance scoring, fuzzy matching, custom metadata search
- Can be integrated with existing Elasticsearch infrastructure

**Implementation Path:**
- Intercept media library search queries
- Route to Elasticsearch via REST API
- Fall back to native WordPress if ES unavailable
- Batch index attachments (newest → oldest for immediate editor value)

### Organizational Structure: Taxonomies vs. Folders

**Community Consensus from [Trac #47839](https://core.trac.wordpress.org/ticket/47839):**
- Virtual folders via **hierarchical taxonomies** on the `attachment` post type
- Don't move files on disk, create organizational structure in database
- One image can appear in multiple "folders" (taxonomy terms)

**Why This Works:**
- DataViews (Phase 3's new UI) already supports taxonomy filtering
- Taxonomies are a solved problem in WordPress
- Survives the Phase 3 UI transition
- Enables both hierarchical folders AND flat tags

**Prior Art:**
- Gutenberg POC: [#53788](https://github.com/WordPress/gutenberg/pull/53788) (built at meetup, never merged)
- Third-party plugins: Media Library Categories (20K+ installs), FileBird (100K+ installs)

### Image Replacement: Multi-Parent Attachments

**Current Limitation:**
- Attachment `post_parent` field is single-value
- Can only track one "parent" post per image
- Reusing an image in Post B doesn't update the relationship
- Can't answer "where is this image used?"

**Potential Solutions:**

1. **New wp_post_relationships table** ([Trac #14513](https://core.trac.wordpress.org/ticket/14513))
   - Enables many-to-many relationships
   - 15-year-old ticket, no consensus on implementation
   - ClassicPress has working code prototype

2. **Postmeta-based tracking** (interim solution)
   - Store `_used_in_posts` meta array
   - Update on post save/update
   - Query via `meta_query` (doesn't scale perfectly, but works)

3. **Content parsing approach**
   - Search `post_content` for image URLs/IDs
   - WP-CLI command to build usage index
   - Refresh on demand or cron

**For Two-Week Sprint:** Postmeta-based approach is fastest to implement and validate.

### Duplicate Detection

**Detection Strategies:**
1. **Perceptual hashing (pHash)** - Compare visual similarity, catches cropped/resized duplicates
2. **MD5 file hash** - Exact binary duplicates only
3. **Filename matching** - Fast but unreliable

**Storage Approach:**
- Store hash in attachment postmeta (`_file_hash`, `_perceptual_hash`)
- WP-CLI command to batch-process existing library
- Auto-hash on upload (via `wp_handle_upload` hook)

**Prior Art:**
- Media Deduplicator plugin (30K installs)
- Duplicate Media Finder (proof of concept)

---

## Core Tickets to Track

### High Priority (File New Tickets)

- [ ] **SQL query performance at scale** - Document `SQL_CALC_FOUND_ROWS` overhead, status checks
- [ ] **Elasticsearch integration for media search** - Reference proven implementations
- [ ] **Bulk operations API** - Delete, metadata updates, usage assessment

### Comment on Existing Tickets

- [x] **[Trac #47839](https://core.trac.wordpress.org/ticket/47839)** - Extended file management (add enterprise use cases)
- [x] **[Trac #10657](https://core.trac.wordpress.org/ticket/10657)** - Many-to-many attachments (has `phase-3-media-triage` keyword)
- [x] **[Trac #14513](https://core.trac.wordpress.org/ticket/14513)** - wp_post_relationships table

### Monitor

- **[GitHub #66663](https://github.com/WordPress/gutenberg/issues/66663)** - Auto-attach images (exploring multi-parent in 7.1)
- **[GitHub #53788](https://github.com/WordPress/gutenberg/pull/53788)** - Media categories/tags POC

---

## Two-Week Sprint Approach

### Week 1: Search + Foundation
- Day 1-2: Plugin scaffold, REST API endpoints, Elasticsearch routing
- Day 3-4: Media modal integration, fallback behavior
- Day 5: Basic taxonomy registration (categories/tags for attachments)

### Week 2: Image Replacement + Duplicates
- Day 6-7: Usage tracking (postmeta approach), WP-CLI rebuild command
- Day 8-9: Replace image functionality, batch operations
- Day 10: Duplicate detection (file hash approach), admin UI

### Scrappy = Fast
- No fancy Dashboard UI (WP-CLI and REST API focused)
- No comprehensive conflict detection (document known incompatible plugins)
- Lean on existing WordPress admin interfaces
- Focus on proving the technical approach works

### Success Criteria
- Search response time < 2 seconds on test site with 1M+ images
- Can replace an image across all posts where it's used
- Can detect exact duplicate uploads
- Can organize media with categories/tags
- Working code that demonstrates feasibility for Core adoption

---

## Key WordPress Core Contributors

**Media Library Work:**
- Andrew Serong (@andrewserong) - Primary media driver in Core/Gutenberg
- Pascal Birchler (@swissspidy) - Client-side media processing
- Daniel Flöck (@talldan) - Core data improvements
- Ramon (@ramonjd) - Opened Phase 3 umbrella issue

---

## Public References

### WordPress Core
- [Gutenberg #55238: Phase 3 Media Library](https://github.com/WordPress/gutenberg/issues/55238)
- [Gutenberg #66663: Auto-attach images](https://github.com/WordPress/gutenberg/issues/66663)
- [Gutenberg #53788: Media categories/tags POC](https://github.com/WordPress/gutenberg/pull/53788)
- [Trac #47839: Extended File Management](https://core.trac.wordpress.org/ticket/47839)
- [Trac #10657: Many-to-Many Attachments](https://core.trac.wordpress.org/ticket/10657)
- [Trac #14513: wp_post_relationships table](https://core.trac.wordpress.org/ticket/14513)
- [Make WordPress Core: Media Library (2023)](https://make.wordpress.org/core/2023/07/07/media-library/)

### Third-Party Plugins (Prior Art)
- [Media Library Categories](https://wordpress.org/plugins/wp-media-library-categories/) - 20K+ installs
- [FileBird](https://wordpress.org/plugins/filebird/) - 100K+ installs
- [Media Library Assistant](https://wordpress.org/plugins/media-library-assistant/) - 40K+ installs
- [Media Deduplicator](https://wordpress.org/plugins/media-deduplicator/) - 30K+ installs

---

**Last Updated:** April 15, 2026
**Next Review:** After two-week sprint (May 6, 2026)

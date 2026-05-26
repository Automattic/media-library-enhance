# Media Library Enhance — User Guide

**Version:** 0.1.0-alpha  
**Requires:** WordPress 6.7+  
**Audience:** Editors, content managers, and site administrators

---

## Overview

Media Library Enhance improves WordPress's built-in media library for sites with large image collections. It adds four capabilities that are designed to feel like a natural part of the editor you already use:

1. **Fast search** — find images in seconds instead of minutes
2. **Media tags** — organise images with tags, right inside the post editor
3. **Duplicate detection** — get a warning before you upload a file that already exists
4. **Replace everywhere** — swap out an image file across every post that uses it, in one action

---

## 1. Searching the Media Library

### What changed

On sites with thousands of images, the default WordPress media search can take 15–30 seconds. Media Library Enhance routes searches through Elasticsearch when it is available, cutting that time to under 2 seconds and returning up to 40 results per page instead of the default 10.

On sites where Elasticsearch is not configured, the plugin still speeds things up by removing a slow database query, so searches are faster even without it.

### How to use it

Search works exactly the same way you are used to — use the search box in the Media Library (`Media → Library`) or in the block editor's image picker. No change to your workflow is required.

You can filter search results by:
- **File type** — use the "Type" dropdown (Images, Audio, Video, Documents)
- **Media tag** — filter to a specific tag using the tag filter (see Section 2)

---

## 2. Media Tags

### What they are

Media tags let you categorise your images so they are easier to find and manage. Unlike post categories, media tags:
- Are **flat** (no parent/child hierarchy — just plain tags)
- Live on the **image itself**, not on the post — so a tag you add in one post shows up when you use that image in another post
- Can be added or edited **without leaving the post editor**

### Adding tags in the post editor

1. Insert or select an image block (`core/image`), a cover block, a media-and-text block, or any block containing an image.
2. In the **block settings sidebar** on the right, scroll down to find the **Tags** panel.
3. Start typing a tag name. Existing tags appear as suggestions — press **Enter** or **comma** to select one.
4. To create a new tag, type the name and press **Enter** — it will be created automatically.
5. To remove a tag, click the × next to its name.

Tags are saved to the image file, not to the post, so they persist wherever that image is used.

### Bulk-assigning tags (administrators)

To tag large batches of images at once, use the WP-CLI command (see the [CLI Reference](./technical-reference.md#cli-reference)):

```
wp mle taxonomies assign media_tag "product-shot" --mime-type=image/jpeg
```

---

## 3. Duplicate Detection

### What it does

When you upload an image that already exists in the media library, the plugin warns you before you create a duplicate. It detects two kinds of duplicates:

- **Exact duplicates** — the file is byte-for-byte identical to an existing image (detected by MD5 hash)
- **Similar images** — the image looks visually the same even if the file size or compression differs (detected by perceptual hash)

> **Note:** By default only exact duplicates trigger a warning. Your administrator can enable similar-image warnings by adjusting the threshold setting.

### What happens when a duplicate is detected

After you upload an image that matches an existing one, a notice appears in the editor with three options:

| Option | What it does |
|--------|--------------|
| **Use the existing one** | Replaces the newly uploaded image in your post with the one already in the library, then deletes the new upload. Available for exact duplicates only. |
| **Keep both** | Dismisses the warning and keeps the new upload. Use this if you intentionally want a separate copy. |
| **Compare** | Opens a side-by-side modal so you can visually inspect both images before deciding. |

### Tips

- The warning appears automatically — you do not need to do anything to enable it.
- If you drag and drop multiple images at once, each one is checked individually.
- Choosing "Use the existing one" is the best option in most cases — it keeps your media library clean and avoids broken references later.

---

## 4. Replace Everywhere

### What it does

"Replace everywhere" lets you swap the file behind an image while keeping its ID, URL, and all post references exactly the same. Every post that uses the image will show the new file automatically, with no broken links and no manual updates needed.

Use this when:
- You have an updated version of a product photo, logo, or graphic
- An image needs to be re-cropped or colour-corrected
- You want to replace a low-quality file with a higher-resolution version

### How to use it

1. In the post editor, click on an image block to select it.
2. In the **block toolbar** (the floating bar above the block), click the **Replace everywhere** button (the circular arrow icon).
3. A modal appears showing:
   - How many posts currently use this image
   - A list of the first 25 posts (with links to their edit screens)
4. Click **Choose file** and select the replacement image from your computer.
5. The file uploads and replaces the image everywhere it is used. The modal closes when the replacement is complete.

### Important notes

- The image URL stays the same. If the new file is a different file name, the URL still uses the original file name — your links do not break.
- Replacement is **permanent**. There is no undo. Review the usage list in the modal before confirming.
- On WordPress VIP environments, CDN caches are purged automatically after replacement.
- The block you are editing will update to show the new image immediately. Other posts that use the image will show the new version on next page load.

---

## Frequently Asked Questions

**Will these features slow down the editor?**  
No. Tag suggestions are fetched from the server only when you start typing. Duplicate checks run in the background with a short delay (100ms) and do not block your work.

**Do media tags show up in search engines?**  
No. Media tags are internal organisational labels. They are not output in page HTML and do not affect SEO.

**What if I accidentally replace an image with the wrong file?**  
There is no automatic undo for image replacement. Contact your site administrator — they may be able to restore the original file from a backup or re-upload it manually.

**Can I use these features outside the block editor?**  
The tags panel, duplicate notice, and replace-everywhere button work in the block editor only. Searching and tag management via the classic Media Library grid is supported. WP-CLI commands are available for bulk operations.

**Why doesn't the search show more than 40 results per page?**  
The default is 40 results per page (configurable up to 100 via the REST API). Returning more at once can slow down the interface — use filters or a more specific search term to narrow results instead.

---

## Getting Help

If something does not work as expected, ask your site administrator to check the plugin status at **Tools → Media Library Enhance**. That page shows the current health of each feature (Elasticsearch availability, hash coverage, usage index coverage, and tag counts).

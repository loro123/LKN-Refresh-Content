# CSV Page Importer — WordPress Plugin

## What This Is

A WordPress plugin that bulk-imports pages and posts from CSV files. Used in a content pipeline where the **Local Business Content Creator** (Node app at `C:\Users\alsot\local-business-content-creator\`) generates SEO service pages via Claude API, exports CSV, and this plugin imports them into any WordPress site. Currently deployed on an InMotion VPS but works on any WordPress hosting.

## Current Version: 1.4.0

### Capabilities

- **CSV import** of pages and posts with HTML content
- **Required columns:** `post_title`, `post_name`
- **Optional columns:** `post_content`, `h1_tag`, `post_type`, `post_date`, `post_status`
- **Scheduling:** `post_date` parsed via `strtotime()`. Future dates auto-set status to `future`. Past dates import as `publish`.
- **Post types:** `page` or `post` only (configurable default via form dropdown)
- **Statuses:** `publish`, `draft`, `pending`, `future`, `private`
- **Schema output:** `_page_schema` post meta emitted in `wp_head` as `application/ld+json` (read-only during import — schema is set via other tools, not CSV)
- **REST meta:** `_page_schema` registered as REST-visible on both `page` and `post`
- **Divi Builder** integration (optional, when Divi theme/plugin is active)
- **Duplicate detection** by slug, with optional update-existing toggle
- **Debug mode** for detailed row-by-row logging

### Version History

- **v1.3.0** — Stable baseline with CSV parsing (fgetcsv), schema import, Divi support. Parser handles edge cases: blank lines in content, escaped quotes, trailing triple quotes.
- **v1.4.0** — Removed schema import (output kept). Added `post_type`/`post_date`/`post_status` columns with auto-future logic. Registered `_page_schema` as REST meta. Added Default Post Type form dropdown.

## Architecture

Single-file plugin (`csv-page-importer.php`) with one class (`CSV_Page_Importer_v140`). Assets in `assets/css/` and `assets/js/`.

**CSV parser** uses PHP's native `fgetcsv` via an in-memory stream (`php://temp`). Do not replace this parser — it handles all edge cases correctly and was extensively tested.

## Deployment

- **Standard WP plugin** — upload zip via Plugins → Add New → Upload, or unzip into `wp-content/plugins/` on any WordPress site
- **Current test install:** `websitesmia.com` on InMotion VPS (cPanel user `websi124`), with v1.3.0 alongside as fallback
- **SSH/WP-CLI notes for InMotion:** See `C:\Users\alsot\inmotion-connect.md` for that server's connection details and gotchas. For other servers, standard WP-CLI applies.
- **Build tooling:** at `C:\ams\wpcontrol\` — `build-v140-zip.js` creates the installable zip with forward-slash paths (do NOT use PowerShell `Compress-Archive`, it writes backslashes that break WP install)

## Do NOT Add (Explicit Scope Boundaries)

These have been explicitly rejected. Do not add without the user asking:

- `post_excerpt`, `post_author`, `post_category`, `post_tags`, `featured_image` columns
- SEO title/description columns (handled post-import via SSH)
- REST-style column aliases (`title`, `slug`, `content`)
- Default category form field, taxonomy auto-creation
- Custom post types beyond `page`/`post`

## Project Goals & Context

This plugin is one piece of a **topical map / content pipeline** for local business SEO sites. The full workflow:

1. **Plan** — a topical map defines dozens/hundreds of service pages and blog posts for a local business site, scheduled out over weeks or months for natural-looking content velocity.
2. **Generate** — the Local Business Content Creator (Node/Claude API app) produces HTML content + metadata per page, exports as CSV.
3. **Import** — this plugin bulk-imports the CSV into WordPress, setting correct dates, statuses, slugs, and parent pages. Future-dated content gets `post_status=future` so WordPress auto-publishes on schedule.
4. **Post-process** — SEO titles, meta descriptions, and schema markup are applied after import via SSH/WP-CLI scripts (not this plugin's job).

### Why scheduling matters

A topical map for a local business might have 50+ service pages and 100+ blog posts scheduled over 6 months. Importing them all at once with future dates means the site owner sets it and forgets it — WordPress handles the drip publishing.

### Why schema import was removed

Schema was previously imported from the CSV's `page_schema` column. This was removed in v1.4.0 because schema is better managed post-import — it varies by page type, often needs per-page customization, and the generation tool handles it separately. The `output_schema()` hook remains so any page with `_page_schema` meta still emits it in `wp_head`.

### Potential future directions (not committed, just discussed)

- Integrating with the content creator app more tightly (API endpoint instead of CSV?)
- Category/tag support for blog posts (deferred — handle post-import for now)
- Multi-site batch import (import same CSV across multiple client sites)
- Progress bar / async import for very large CSVs (100+ rows)

## Testing

- **Parser test CSV:** `C:\Users\alsot\Downloads\csv-importer-parser-test.csv` (6 rows, edge cases)
- **Scheduling test CSV:** `C:\Users\alsot\Downloads\csv-importer-scheduling-test.csv` (6 rows: immediate/auto-future/draft/past-date)
- **Test runner:** `C:\ams\wpcontrol\run-v140-import-test.js` — uploads CSV to server, invokes plugin via reflection
- **Verification:** `C:\ams\wpcontrol\verify-v140-rows.js` — queries DB for post_status/post_date
- **Cleanup:** `C:\ams\wpcontrol\cleanup-v140-test.js` — deletes test rows (slugs matching `v140-test-*` and parser test slugs)

# KSM WordPress Importer

A heavily patched and enhanced fork of [WordPress Importer v2](https://github.com/humanmade/WordPress-Importer) by Krafty Sprouts Media LLC.

The original plugin was last updated by its authors in 2019. This fork brings it up to date for modern WordPress and PHP environments, fixes critical bugs, hardens security, redesigns the UI, and adds features needed for real-world production use.

---

## What's different from the original

### Bug fixes
- Featured images now remap correctly after import (was broken — used a deprecated property that was never populated)
- Post meta loop no longer aborts early when a single meta item is filtered out
- Menu item processing no longer crashes on unknown item types
- Re-uploading the same XML file no longer returns a false "could not cache" error
- Fatal error on import from sites with plugins not installed on the destination (e.g. Link Whisper, Rank Math) — serialized objects are now detected and skipped safely instead of crashing

### Security
- All steps now require `import` capability — previously any logged-in user could access the importer
- Error messages are properly escaped before output
- `wp_kses()` calls fixed — were passing a string instead of an allowed-tags array, stripping all HTML
- Local file path input is restricted to the uploads directory — prevents pointing at `wp-config.php` or other sensitive files

### Performance
- Preliminary XML scan (step 1 → step 2) rewritten from scratch — no longer calls `expand()` on every post node, which was loading full post content into DOM objects just to count them. A 17MB file that took 60 seconds now takes ~2 seconds
- Memory limit raised at import start via `wp_raise_memory_limit()`
- Object cache flushed every 200 posts to keep memory flat on large imports

### UI
- All three steps redesigned with a clean, modern layout using standard WordPress admin components
- Step 1: Drag-drop upload zone, media library picker, and a direct file path option for large files
- Step 2: Two-column summary grid (what will be imported + source metadata), author mapping in cards
- Step 3: Stat cards per type (posts/media/users/comments/terms), single overall progress bar, log hidden by default with a toggle

### New features
- **Cancel Import button** — stops the stream, clears server state, keeps already-imported posts. Safe to re-run — duplicates are skipped automatically
- **Large file support** — copy your XML directly into the uploads folder and enter the path. Bypasses browser upload limits entirely. Tested with 175MB+ files
- **Local/offline testing** — attachment fetching falls back to a local file copy when the source URL points to a file already in the uploads directory, so imports work without internet access
- **Deduplication** — posts, terms, and comments are all checked against existing database records before inserting. Safe to run multiple times or resume after a network interruption

---

## Installation

### From GitHub
1. [Download the latest release ZIP](https://github.com/Krafty-Sprouts-Media-LLC/KSM-WordPress-Importer/archive/refs/heads/main.zip)
2. Go to **Plugins → Add New → Upload Plugin**
3. Upload the ZIP and activate
4. Deactivate the original WordPress Importer if it's active
5. Go to **Tools → Import → WordPress (v2)**

### For large files (100MB+)
Instead of uploading through the browser:
1. Copy your `.xml` file into `wp-content/uploads/` via FTP/SFTP
2. On the import page, use the **"Use a file already on the server"** form at the bottom
3. Paste the full server path (shown on the page for reference) — no quotes needed

### Via WP-CLI
```sh
wp wxr-importer import /path/to/export.xml
wp wxr-importer import /path/to/export.xml --verbose
wp wxr-importer import /path/to/export.xml --default-author=1
```

---

## What gets imported

| Content | Preserved |
|---|---|
| Posts, pages, CPTs | ✅ Full content, meta, status, date, slug |
| Categories, tags, custom taxonomies | ✅ Created if missing, mapped if existing |
| Comments | ✅ Including comment meta |
| Authors | ✅ Map to existing users or create new |
| Featured images | ✅ Remapped to new post IDs |
| Parent/child relationships | ✅ Remapped after full import |
| Menu items | ✅ Remapped to new post/term IDs |
| Media files | ✅ If "Download attachments" is checked, or if files exist locally |
| Plugin-specific meta (missing plugin) | ⚠️ Skipped with warning, import continues |
| Site settings / theme options | ❌ Not in WXR format |
| User passwords | ❌ Never exported by WordPress |

---

## Requirements

- WordPress 5.0+
- PHP 7.4+

---

## License

GPLv2 or later. See [LICENSE](LICENSE).

## Credits

Original plugin by Ryan Boren, Jon Cave, Andrew Nacin, and Peter Westwood.
Redux (v2) by [Ryan McCue](https://github.com/rmccue) and [contributors](https://github.com/humanmade/WordPress-Importer/graphs/contributors).
This fork maintained by [Krafty Sprouts Media LLC](https://github.com/Krafty-Sprouts-Media-LLC).

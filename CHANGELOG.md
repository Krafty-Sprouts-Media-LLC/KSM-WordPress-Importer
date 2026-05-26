# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [2.0.3] - 26/05/2026

### Added
- **Cancel Import button** on the import progress page — closes the SSE stream in the browser, sends an AJAX request to clear `_wxr_import_settings` on the server (preventing auto-resume on reconnect), and shows a confirmation message. Posts already imported are kept. Re-running the import later is safe — existing posts are skipped automatically via GUID deduplication.
- `handle_cancel_import()` AJAX handler (`wp_ajax_wxr-cancel-import`) — validates nonce and capability, then deletes `_wxr_import_settings` post meta to cleanly stop the import session.
- `cancelUrl`, `cancelNonce`, and `importId` added to localized JS data for the cancel flow.
- **Local file path import** for large files — a second form on the upload page accepts a full server path to an XML file already copied into the uploads folder. This bypasses browser upload limits entirely (no Plupload involved). The file is registered as a WordPress attachment in-place (no copy needed) and the rest of the import flow runs unchanged. Includes path traversal protection (restricted to inside `ABSPATH`) and XML content validation.
- `handle_local_file()` method — sanitises and validates the supplied path, restricts it to inside the **uploads directory** (not just `ABSPATH`) to prevent pointing at `wp-config.php` or plugin files, does a quick XML header check, then registers the file as a private attachment so the standard import flow works unchanged.
- Upload page now shows the server's uploads directory path as a reference so you know exactly where to drop large files.

### Changed
- `display_author_step()` now checks for `$_POST['local_file']` in addition to `$_REQUEST['id']` and the standard file upload, routing to the appropriate handler.
- Cancel button hides automatically on import complete or error.

---

## [2.0.2] - 23/05/2026

### Fixed

**Critical bugs**
- `remap_featured_images()` referenced `$this->processed_posts` which is never populated in v2 — fixed to use `$this->mapping['post']`. The method was also commented out in `import()` so featured images were never remapped; now enabled.
- `process_post_meta()` used `return false` inside the meta loop — if any single meta item was filtered out, all remaining meta for that post was silently dropped. Changed to `continue`.
- `process_menu_item_meta()` referenced undefined variable `$item` in the `default` case — fixed to use `$data`.
- `get_data_for_attachment()` returned a false error on re-upload of the same XML file because `update_post_meta()` returns `false` when the value is unchanged (not just on failure). Fixed with a proper existence check via `get_post_meta()`.

**Fatal error on import — serialized objects from missing plugins**
- Importing a WXR file from a site with plugins not installed locally (e.g. Link Whisper `Wpil_Model_Link`) caused a fatal error in `wp_unslash()` / `map_deep()` when WordPress tried to process an `__PHP_Incomplete_Class` object from `maybe_unserialize()`. Added `value_has_incomplete_class()` helper that recursively checks unserialized values and skips any meta that contains an incomplete class object, logging a warning instead of crashing.

**Security**
- `display_error()` echoed raw error messages without escaping — fixed with `esc_html()`.
- Two `wp_kses()` calls in `select-options.php` passed a string `'data'` as the allowed-tags argument instead of an array, stripping all HTML. Fixed with proper arrays.
- `stream_import()` had no capability check — added `current_user_can('import')` guard.
- `dispatch()` had no capability check — any logged-in user could access the importer. Added `current_user_can('import')` guard with a proper 403 response.
- `$this->id` cast order was wrong: `wp_unslash((int)$_REQUEST['id'])` unslashes an already-cast integer. Fixed to `(int) wp_unslash(...)`.

**JavaScript**
- Variable shadowing in `import.js`: `var message` declared twice in the same closure scope (outer event parameter + inner DOM element). Renamed inner variable to `msgCell`.
- Log rows were appended to `<table>` instead of `<tbody>`, breaking table structure.
- Two `console.log()` debug calls left in production code in `intro.js` — removed.
- `EventSource` had no `onerror` handler — connection drops showed nothing to the user.
- `complete` SSE action ignored the `error` field it carries — errors were silently shown as success.
- `intro.js` `renderStatus()` was called for both in-progress and success states using the same function, causing the "Continue" button to not appear after upload. Split into `renderProgress()` and `renderDone()`.

**CSS — global style bleed**
- `.wrap { background, min-height, padding }` and `.wrap * { box-sizing }` overrode WordPress core admin styles globally, affecting every admin page.
- `form { background, border, padding }` styled every `<form>` on the page, not just the importer's.
- `#import-log tbody { display: block }` + `tbody tr { display: table }` CSS hack broke column alignment.
- `#completed-total { display: none }` hid the total progress counter.
- `.progress` bare class conflicted with WP core's own `.progress` usage.
- `@keyframes pulse` generic name could conflict with other plugins — renamed to `wxr-pulse`.

**Performance — large file hang at step 1**
- `get_preliminary_information()` called `$reader->expand()` on every `<item>` node, loading the entire post (content, meta, comments) into a DOM tree just to count it. On a 17MB file this caused a 30-60 second hang or PHP timeout. Rewrote to use a depth-tracking flag + `readString()` on `<wp:post_type>` only — no DOM allocation per post. Parse time reduced from ~60s to ~2s.
- Added `set_time_limit(0)` and `wp_raise_memory_limit('admin')` to `display_author_step()` — the preliminary parse runs as a regular page request with default PHP limits, not as an unlimited AJAX call.

### Changed
- `get_preliminary_information()` completely rewritten as a lightweight streaming scan — no `expand()` calls on item nodes.
- `import_start()` now calls `wp_raise_memory_limit('admin')` to request more RAM for large imports.
- `process_post()` now calls `wp_cache_flush()` every 200 posts to keep memory usage flat on large imports.
- Import log is now hidden by default with a "Show log" toggle — prevents the browser from rendering thousands of DOM rows during large imports. Log is capped at 200 rows (oldest drop off the top).
- UI completely redesigned across all three steps:
  - Step 1 (upload): Clean card layout, proper drag-drop zone, "or" divider, media library button with icon.
  - Step 2 (settings): Two-column grid with import summary and source metadata. Author mapping and attachment options in cards.
  - Step 3 (progress): Stat cards per type showing count + done. Single overall progress bar. Log hidden by default.
- SSE `complete` action now handles the `error` field and displays it with correct styling.
- SSE `error` action now handled explicitly in JS.
- Log rows now get a CSS class (`log-warning`, `log-error`, etc.) for colour-coded styling.

### Added
- `value_has_incomplete_class()` method — recursively detects `__PHP_Incomplete_Class` objects in unserialized meta values to prevent fatal errors when importing from sites with plugins not installed locally.
- Local file fallback in `fetch_remote_file()` — for offline/local testing, checks if the attachment already exists in the local uploads directory and copies it directly instead of making an HTTP request that would fail.
- `onerror` handler on `EventSource` — shows a user-facing message if the SSE stream drops unexpectedly.
- Localized strings for `showLog`, `hideLog`, `interrupted`, `error`, and `importing` states in the import JS.

---

## [2.0.1] - 30/12/2025

### Fixed
- Fixed critical bug: Variable name typo in `post_process_comments()` method - changed `$comment_ID` to `$comment_id` (line 2020)
- Fixed PHP 8.0+ compatibility: Updated `libxml_disable_entity_loader()` usage to check PHP version before calling deprecated function
- Fixed asset path issues: Corrected `plugins_url()` calls to use plugin root directory instead of class file paths
- Fixed missing class properties: Added `$id`, `$version`, and `$authors` properties to `WXR_Import_UI` class
- Fixed plugin basename detection for update checks
- Fixed Plupload uploader UI: Added missing JavaScript dependencies (jquery, underscore, wp-util, media-upload)
- Fixed Plupload settings: Added missing `max_file_size` and `url` parameters
- Fixed asset URL paths: Changed from `plugins_url()` with incorrect path to `plugin_dir_url()` for correct asset loading
- Fixed async upload handler: Changed action hook from `admin_action_` to `wp_ajax_` for proper Plupload integration
- Improved upload error handling: Added proper error responses and validation for file uploads
- Fixed file validation: Changed from extension-based to content-based validation (checks XML/WXR content instead of file extension)
- Fixed logger null checks: Added safety checks to prevent errors when logger is not initialized
- Fixed preliminary information logger: Uses HTML logger instead of ServerSentEvents for better error display
- Fixed XML parsing errors: Added validation for XMLReader::expand() failures to handle corrupted XML files gracefully
- Fixed undefined array key errors: Added checks for missing post_type and other required fields
- Improved error handling: All parse methods now validate nodes before accessing childNodes
- Fixed XML error suppression: Added libxml_use_internal_errors() to suppress PHP warnings and handle errors gracefully
- Added safe_expand() helper method: Centralized XML node expansion with proper error handling and logging
- Fixed corrupted export handling: Added detection and skipping of HTML error pages embedded in postmeta values (from failed WordPress exports)
- Fixed UI/UX issues: Completely redesigned CSS for modern, readable interface with proper colors and spacing
- Fixed dark/black screen issues: Added proper background colors, text colors, and styling for all UI elements
- Added proper file headers to all PHP files with version tracking
- Improved error handling and security

### Changed
- Updated plugin version to 2.0.1
- Enhanced compatibility with modern WordPress versions
- Better handling of XML entity loading for security
- Updated composer.json: PHP requirement from >=5.2 to >=7.4
- Updated composer.json: Modernized dev dependencies (wpcs ^3.0, phpcs ^3.7)
- Completely redesigned UI/UX: Modern, clean interface with proper WordPress admin styling
- Updated CSS files: Added comprehensive styling for upload interface and import progress screens
- Updated asset version numbers to match plugin version

### Added
- CHANGELOG.md file for version tracking
- File headers with metadata to all PHP files

---

## [2.0.0] - 2015-01-01

### Added
- Initial release of WordPress Importer v2
- Web UI for importing WordPress XML files
- CLI support via WP-CLI
- Server-Sent Events for real-time import progress
- Support for posts, pages, comments, custom fields, categories, tags, and media


### Fixed

**Critical bugs**
- `remap_featured_images()` referenced `$this->processed_posts` which is never populated in v2 — fixed to use `$this->mapping['post']`. The method was also commented out in `import()` so featured images were never remapped; now enabled.
- `process_post_meta()` used `return false` inside the meta loop — if any single meta item was filtered out, all remaining meta for that post was silently dropped. Changed to `continue`.
- `process_menu_item_meta()` referenced undefined variable `$item` in the `default` case — fixed to use `$data`.
- `get_data_for_attachment()` returned a false error on re-upload of the same XML file because `update_post_meta()` returns `false` when the value is unchanged (not just on failure). Fixed with a proper existence check via `get_post_meta()`.

**Fatal error on import — serialized objects from missing plugins**
- Importing a WXR file from a site with plugins not installed locally (e.g. Link Whisper `Wpil_Model_Link`) caused a fatal error in `wp_unslash()` / `map_deep()` when WordPress tried to process an `__PHP_Incomplete_Class` object from `maybe_unserialize()`. Added `value_has_incomplete_class()` helper that recursively checks unserialized values and skips any meta that contains an incomplete class object, logging a warning instead of crashing.

**Security**
- `display_error()` echoed raw error messages without escaping — fixed with `esc_html()`.
- Two `wp_kses()` calls in `select-options.php` passed a string `'data'` as the allowed-tags argument instead of an array, stripping all HTML. Fixed with proper arrays.
- `stream_import()` had no capability check — added `current_user_can('import')` guard.
- `dispatch()` had no capability check — any logged-in user could access the importer. Added `current_user_can('import')` guard with a proper 403 response.
- `$this->id` cast order was wrong: `wp_unslash((int)$_REQUEST['id'])` unslashes an already-cast integer. Fixed to `(int) wp_unslash(...)`.

**JavaScript**
- Variable shadowing in `import.js`: `var message` declared twice in the same closure scope (outer event parameter + inner DOM element). Renamed inner variable to `msgCell`.
- Log rows were appended to `<table>` instead of `<tbody>`, breaking table structure.
- Two `console.log()` debug calls left in production code in `intro.js` — removed.
- `EventSource` had no `onerror` handler — connection drops showed nothing to the user.
- `complete` SSE action ignored the `error` field it carries — errors were silently shown as success.
- `intro.js` `renderStatus()` was called for both in-progress and success states using the same function, causing the "Continue" button to not appear after upload. Split into `renderProgress()` and `renderDone()`.

**CSS — global style bleed**
- `.wrap { background, min-height, padding }` and `.wrap * { box-sizing }` overrode WordPress core admin styles globally, affecting every admin page.
- `form { background, border, padding }` styled every `<form>` on the page, not just the importer's.
- `#import-log tbody { display: block }` + `tbody tr { display: table }` CSS hack broke column alignment.
- `#completed-total { display: none }` hid the total progress counter.
- `.progress` bare class conflicted with WP core's own `.progress` usage.
- `@keyframes pulse` generic name could conflict with other plugins — renamed to `wxr-pulse`.

**Performance — large file hang at step 1**
- `get_preliminary_information()` called `$reader->expand()` on every `<item>` node, loading the entire post (content, meta, comments) into a DOM tree just to count it. On a 17MB file this caused a 30-60 second hang or PHP timeout. Rewrote to use a depth-tracking flag + `readString()` on `<wp:post_type>` only — no DOM allocation per post. Parse time reduced from ~60s to ~2s.
- Added `set_time_limit(0)` and `wp_raise_memory_limit('admin')` to `display_author_step()` — the preliminary parse runs as a regular page request with default PHP limits, not as an unlimited AJAX call.

### Changed
- `get_preliminary_information()` completely rewritten as a lightweight streaming scan — no `expand()` calls on item nodes.
- `import_start()` now calls `wp_raise_memory_limit('admin')` to request more RAM for large imports.
- `process_post()` now calls `wp_cache_flush()` every 200 posts to keep memory usage flat on large imports.
- Import log is now hidden by default with a "Show log" toggle — prevents the browser from rendering thousands of DOM rows during large imports. Log is capped at 200 rows (oldest drop off the top).
- UI completely redesigned across all three steps:
  - Step 1 (upload): Clean card layout, proper drag-drop zone, "or" divider, media library button with icon.
  - Step 2 (settings): Two-column grid with import summary and source metadata. Author mapping and attachment options in cards.
  - Step 3 (progress): Stat cards per type showing count + done. Single overall progress bar. Log hidden by default.
- SSE `complete` action now handles the `error` field and displays it with correct styling.
- SSE `error` action now handled explicitly in JS.
- Log rows now get a CSS class (`log-warning`, `log-error`, etc.) for colour-coded styling.
- Cancel button hides automatically on import complete or error.

### Added
- `value_has_incomplete_class()` method — recursively detects `__PHP_Incomplete_Class` objects in unserialized meta values to prevent fatal errors when importing from sites with plugins not installed locally.
- Local file fallback in `fetch_remote_file()` — for offline/local testing, checks if the attachment already exists in the local uploads directory and copies it directly instead of making an HTTP request that would fail.
- `onerror` handler on `EventSource` — shows a user-facing message if the SSE stream drops unexpectedly.
- Localized strings for `showLog`, `hideLog`, `interrupted`, `error`, and `importing` states in the import JS.
- **Cancel Import button** on the import progress page — closes the SSE stream in the browser, sends an AJAX request to clear `_wxr_import_settings` on the server (preventing auto-resume on reconnect), and shows a confirmation message. Posts already imported are kept. Re-running the import later is safe — existing posts are skipped automatically via GUID deduplication.
- `handle_cancel_import()` AJAX handler (`wp_ajax_wxr-cancel-import`) — validates nonce and capability, then deletes `_wxr_import_settings` post meta to cleanly stop the import session.
- `cancelUrl`, `cancelNonce`, and `importId` added to localized JS data for the cancel flow.
- **Local file path import** for large files — a second form on the upload page accepts a full server path to an XML file already copied into the uploads folder. This bypasses browser upload limits entirely (no Plupload involved). The file is registered as a WordPress attachment in-place (no copy) and the rest of the import flow runs unchanged. Includes path traversal protection (restricted to inside `ABSPATH`) and XML content validation.

---

## [2.0.1] - 30/12/2025

### Fixed
- Fixed critical bug: Variable name typo in `post_process_comments()` method - changed `$comment_ID` to `$comment_id` (line 2020)
- Fixed PHP 8.0+ compatibility: Updated `libxml_disable_entity_loader()` usage to check PHP version before calling deprecated function
- Fixed asset path issues: Corrected `plugins_url()` calls to use plugin root directory instead of class file paths
- Fixed missing class properties: Added `$id`, `$version`, and `$authors` properties to `WXR_Import_UI` class
- Fixed plugin basename detection for update checks
- Fixed Plupload uploader UI: Added missing JavaScript dependencies (jquery, underscore, wp-util, media-upload)
- Fixed Plupload settings: Added missing `max_file_size` and `url` parameters
- Fixed asset URL paths: Changed from `plugins_url()` with incorrect path to `plugin_dir_url()` for correct asset loading
- Fixed async upload handler: Changed action hook from `admin_action_` to `wp_ajax_` for proper Plupload integration
- Improved upload error handling: Added proper error responses and validation for file uploads
- Fixed file validation: Changed from extension-based to content-based validation (checks XML/WXR content instead of file extension)
- Fixed logger null checks: Added safety checks to prevent errors when logger is not initialized
- Fixed preliminary information logger: Uses HTML logger instead of ServerSentEvents for better error display
- Fixed XML parsing errors: Added validation for XMLReader::expand() failures to handle corrupted XML files gracefully
- Fixed undefined array key errors: Added checks for missing post_type and other required fields
- Improved error handling: All parse methods now validate nodes before accessing childNodes
- Fixed XML error suppression: Added libxml_use_internal_errors() to suppress PHP warnings and handle errors gracefully
- Added safe_expand() helper method: Centralized XML node expansion with proper error handling and logging
- Fixed corrupted export handling: Added detection and skipping of HTML error pages embedded in postmeta values (from failed WordPress exports)
- Fixed UI/UX issues: Completely redesigned CSS for modern, readable interface with proper colors and spacing
- Fixed dark/black screen issues: Added proper background colors, text colors, and styling for all UI elements
- Added proper file headers to all PHP files with version tracking
- Improved error handling and security

### Changed
- Updated plugin version to 2.0.1
- Enhanced compatibility with modern WordPress versions
- Better handling of XML entity loading for security
- Updated composer.json: PHP requirement from >=5.2 to >=7.4
- Updated composer.json: Modernized dev dependencies (wpcs ^3.0, phpcs ^3.7)
- Completely redesigned UI/UX: Modern, clean interface with proper WordPress admin styling
- Updated CSS files: Added comprehensive styling for upload interface and import progress screens
- Updated asset version numbers to match plugin version

### Added
- CHANGELOG.md file for version tracking
- File headers with metadata to all PHP files

## [2.0.0] - 2015-01-01

### Added
- Initial release of WordPress Importer v2
- Web UI for importing WordPress XML files
- CLI support via WP-CLI
- Server-Sent Events for real-time import progress
- Support for posts, pages, comments, custom fields, categories, tags, and media


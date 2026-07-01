# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.6.0] - 01/07/2026

### Added
- Two import-queue indexes (schema version `1.2.0`): `job_type_old` (`job_id, entity_type, old_entity_id`) powering `Better_Import_Queue_Repository::get_new_entity_id()` for O(1) old→new ID resolution, and `job_status_entity` (`job_id, status, entity_index`) so fetching the next work item no longer scans over completed rows.
- Deferred-relationship remapping phase: after the queue drains, `Better_Importer::process_remap_chunk()` resolves `_better_import_parent` and `_better_import_user_slug` markers left by posts that were created before their parent or author existed, then the job completes.
- `unknown_post_type_strategy` import option (`import_as_draft` default, or `skip` / `fail`). Unknown post types are imported as drafts with the source type preserved in `_better_import_original_post_type` instead of failing the post.
- `better_importer.meta.unique_keys` filter listing single-value meta keys that are replaced rather than appended.
- `meta_write_mode` import option (`bulk` default, or `hooked`). In `hooked` mode post meta is written with `add_post_meta()` so plugin hooks run and `_edit_last` is remapped to the local user, for stacks that depend on meta hooks.
- Real attachment import: when "Download and import file attachments" is enabled, media files are fetched from the source URL, sideloaded into the uploads directory, inserted with `wp_insert_attachment()`, and given generated metadata/thumbnails. Adds `update_attachment_guids` / `remap_content_urls` options and a `better_importer.attachment.download_timeout` filter.
- Source media URLs in post content are rewritten to the local uploads URL during the remapping phase (controlled by `remap_content_urls`, default on when media import is enabled).
- Settings UI controls for the unknown-post-type strategy and post-meta write mode.

### Fixed
- Child posts whose parent or author appears later in the export file now have their `post_parent` / `post_author` resolved during the remapping phase instead of being left at 0 / the default author.
- Deferred parent/author markers are now actually written to the database. They were previously appended to a discarded copy of the meta array and never persisted, so cross-order relationships were silently lost.
- Comments now remap `comment_parent` to the local parent comment and `user_id` to the local author instead of hardcoding a top-level comment and reusing the source user ID.
- Single-value post meta keys (`_thumbnail_id`, `_wp_page_template`, etc.) are replaced instead of appended, preventing duplicate rows — and broken featured images/templates — when a post is re-imported.
- Category/term parent hierarchy is now imported. `import_term()` resolves the parent by slug (deferring forward references to the remapping phase), so nested taxonomies keep their structure instead of importing flat.
- Sticky posts are re-stuck on import (`is_sticky` was parsed but ignored).
- Nav menu items have their `_menu_item_object_id` and `_menu_item_menu_item_parent` remapped to local IDs during the remapping phase, so imported menus point at the right posts/terms instead of source-site IDs.
- Comment counts are populated in job stats (comments are sub-steps, so they are counted from the imported posts), instead of always showing 0.

### Changed
- `Better_Install::maybe_install()` runs `dbDelta` only when the stored schema version changed, instead of on every admin page load.
- Batch processing no longer rewrites the full job row (including the immutable entity manifest and growing ID mapping) after every sub-step. A new `Better_Import_Job_Repository::save_state()` persists only the volatile progress columns at batch boundaries, and per-sub-step checkpoints now write only the queue row. This removes the O(total-entities) write amplification that made large imports slow down as they progressed.
- The batch loop polls for external pause/cancel requests with a single-column `Better_Import_Job_Repository::get_status()` read instead of re-hydrating (and JSON-decoding) the entire job manifest on every iteration.
- `Better_Import_Remapper` now resolves the large `post`, `user`, and `term_id` mappings on demand from the queue table instead of carrying them in the job row. Only the small derived buckets (`user_slug`, `term`, `comment`) are persisted in `mapping_state`, keeping the persisted mapping bounded regardless of import size.
- Progress polling derives per-status totals from the per-type summary, running one aggregate queue query per poll instead of two.

## [1.5.0] - 30/06/2026

### Added
- `Better_Format_Detector` — detects WXR XML vs Better Package (`.bwxr`/ZIP) and rejects unsupported formats with a clear message.
- `Better_Chunked_Upload` — Plupload 8mb chunked assembly into private XML attachments with web-protected session directories under `uploads/better-importer-chunks/`.
- Daily `better_importer_cleanup_chunks` cron for abandoned chunk sessions.
- Format validation on preflight scan, local-file path checks, and both single and chunked AJAX uploads.

- Author Mapping on the import settings screen, allowing each source WXR author to be mapped to a destination user before the job starts.
- Full chronological Activity Log output for import jobs, including queue/start messages, mapped existing entities, batch summaries, explicit errors, and completion.

### Fixed
- Chunked uploads no longer redirect to settings on partial chunk responses; redirect only happens when `attachment_id` is returned.

- Queue rows are now seeded with persistent `parsed_payload` data during the initial parse so batch processing never reopens XML to seek by entity index.
- Existing users are mapped by login/email instead of repeatedly failing with "username already exists" errors.
- Missing custom taxonomy assignments no longer fail the whole post; they are preserved in `_better_import_skipped_taxonomies` and summarized in the Activity Log.
- Activity Log rendering now clears stale browser-side entries and escapes log output.
- WP-CLI `--no-attachments` handling now maps correctly to the importer attachment option.
- Disabled media imports now bulk-skip attachment queue rows instead of walking and saving thousands of attachment rows one at a time.
- Source terms whose taxonomy is not registered on the destination are skipped with an explicit message instead of failing the import.
- Duplicate term assignment values are de-duplicated before calling `wp_set_post_terms()`, avoiding duplicate relationship database warnings.
- Progress cards now show failed counts per content type and completed jobs with failures use distinct completion copy.

### Changed
- README, CONTRIBUTING, and `composer.json` updated for the 1.0 rebuild; removed obsolete `.travis.yml` and `find-deprecated-usage.php`.
- Reference copies consolidated under `.legacy/`; duplicate `legacy/` and root `WordPress-Importer-master/` trees removed.

- Admin CSS and JS assets use file modification times for cache-busting during active development.
- Import progress counts now include comments in total/scanned entity reporting.
- Progress polling now uses an incremental log cursor instead of returning the entire Activity Log on every request.
- Post meta chunks are imported with bulk SQL inserts and larger default chunk sizes to reduce per-row WordPress hook overhead.
- Batch processing temporarily defers term/comment counting and cache invalidation during hot import loops.

---

## [1.4.3] - 30/06/2026

### Fixed
- Choose File button did nothing because upload JS required `wp.Uploader` but only `plupload-all` was enqueued. Now loads `wp-plupload` via `wp_enqueue_media()` and `wp_plupload_default_settings()`.
- Added a native file-input fallback uploader when Plupload is unavailable.

---

## [1.4.2] - 30/06/2026

### Fixed
- Tools → Import → WordPress (v2) (`admin.php?import=wordpress`) rendered a broken page because `noheader` skipped `admin-header.php` and our UI never loaded it. The importer now loads the admin header inside `render_header()`, matching the legacy pattern.
- Admin CSS/JS now enqueue on the core import screen (`?import=wordpress`), not only on Tools submenu pages.

---

## [1.4.1] - 30/06/2026

### Fixed
- Tools → Better Importer and Import History pages returned “Sorry, you are not allowed to access this page” because submenu registration ran on `admin_init` after WordPress had already fired `admin_menu`.
- Restored `Better_Install::schedule_cron()` in the plugin bootstrap (accidentally dropped during the menu fix).

---

## [1.4.0] - 30/06/2026

### Added
- Phase F legacy cleanup: `Better_Legacy_Cleanup` detects v3 `wxr_import_*` tables, meta, chunk dirs, diagnostic logs, and legacy cron events.
- Guarded upgrade path in `Better_Install::maybe_upgrade_from_legacy()` — flags legacy data without auto-dropping tables.
- Tools → Importer Settings maintenance screen with nonce-protected cleanup actions.
- WP-CLI legacy alias `wp wxr-importer` delegating to `Better_CLI_Command`.

### Changed
- Settings tab added to Better Importer admin navigation.
- `uninstall.php` clears legacy cron hooks and new maintenance options.
- Diagnostic log files and local test XML exports are gitignored; removed `wxr-upload-debug.log` from the plugin root.

### Security
- Destructive “drop legacy tables” action requires explicit confirmation checkbox and `manage_options` capability.

---

## [1.3.0] - 30/06/2026

### Added
- WP-CLI command `wp better-importer import <file>` using the same `Better_Import_Processor` queue engine as AJAX.
- CLI subcommands: `status`, `cancel`, `list`, and `report`.
- `--dry-run`, `--no-attachments`, `--default-author`, and `--batch-seconds` flags for CLI imports.

### Changed
- Release numbering aligned to semver: new feature phases use minor versions (`1.1.0`, `1.2.0`, `1.3.0`), not patch releases.
- WP-Cron now resumes `remapping` jobs in addition to `queued` and `processing` (paused jobs are still skipped).

---

## [1.2.0] - 30/06/2026

### Added
- Phase D admin UI: upload, settings, progress, and history screens under Tools → Better Importer.
- Tools → Import registration as WordPress (v2) pointing at the new importer.
- AJAX endpoints for upload, preflight, start, batch, status, pause, resume, and cancel.
- Polling-based progress UI with honest counters, current entity step, activity log, and pause/resume/cancel controls.

---

## [1.1.0] - 30/06/2026

### Added
- Phase C processing engine: `Better_Import_Processor` with time-based batches, per-entity payloads, and sub-step checkpoints.
- `Better_WXR_Parser` parses each entity once from XML into a gzipped queue payload.
- `Better_Importer` creates users, terms, and posts with chunked meta/comment imports.
- `Better_Logger`, `Better_Import_Remapper`, and WP-Cron hook `better_importer_process_batch`.

---

## [1.0.0] - 30/06/2026

### Added
- Fresh 1.0.0 rebuild bootstrap on `main` with new `src/` architecture.
- `Better_Install` creates `better_import_jobs`, `better_import_queue`, and `better_import_log` tables.
- `Better_Preflight` streaming WXR scan that builds a compact manifest for all entities with no byte offsets.
- `Better_Import_Job::create()` stores the full manifest (no 500-item cap) and seeds one queue row per entity.
- Legacy experimental `wxr_import_*` data is detected on activation but never dropped automatically.

### Changed
- `plugin.php` now loads only the new 1.0 engine; legacy v3 files remain in the repo for reference until Phase F cleanup.
- v3.0.x engine, templates, assets, tests, and reference copies moved under `legacy/` per `docs/IMPLEMENTATION.md`.
- `master` branch renamed to `legacy-v3` (frozen v3.0.8 snapshot); `main` is the default development branch.

---

## [3.0.8] - 29/06/2026

### Fixed
- Large import batches no longer loop at entity 1/50 after the first successful work. Job saves now keep cursor and counters monotonic, reload fresh state after acquiring the batch lock, and repair progress from persisted item records when a stale request tries to save older progress.
- Batch XML scanning no longer advances the `XMLReader` inside each processed entity, which was causing sibling importable nodes to be skipped and could produce a non-advancing `next_item_index`.
- Processor now logs and repairs a batch cursor if a processed batch ever reports no forward movement.
- Progress copy now distinguishes the XML scan cursor from actual imported content totals, avoiding misleading "entities processed" / "batch complete" messages.
- Batch start logs are now persisted before heavy XML work begins, and the progress UI shows a running elapsed timer while an AJAX batch is still in flight.
- Attachment entities are now skipped immediately when media fetching is disabled, avoiding unnecessary duplicate checks/post-processing and preventing skipped media from being counted as imported media.
- Progress cards now show skipped and failed counts per content type, so disabled media imports clearly show skipped media instead of only showing imported media.
- Pause/resume now syncs correctly with server status, survives page refreshes, and preserves pause requests made while a batch is still running.
- Repeated timeout loops now back off the active batch size, escape single-entity dead loops, and record timed-out posts with their local post ID when WordPress created the post before Apache/FastCGI killed the request.
- Import batches now set the standard `WP_IMPORTING` flag before inserting content so compatible plugins can skip expensive save-time processing during WXR imports.
- Large posts now import post meta in resumable chunks with per-post checkpoints, keeping the XML cursor on the same entity until that post is actually complete instead of timing out, retrying, or falsely marking the whole post as finished.

### Changed
- Web imports now use 100 entities per batch by default for large WXR files instead of automatically throttling files over 5,000 entities down to 10.

---

## [3.0.7] - 29/06/2026

### Fixed
- Import stuck after first batch: batch/pause/resume AJAX used the wrong nonce field (`_ajax_nonce` vs `nonce`), so every batch after cron’s first pass failed with `-1` and the UI showed “Connection lost”.
- Early progress showed **0%** when only a few entities were processed (e.g. 50 of 11,673); now shows at least 1% once work has started.
- Progress UI runs batches sequentially (no overlapping interval timers), surfaces real AJAX error messages, auto-opens the log, and uses proper ellipsis in phase labels.
- Large imports (>5k entities) cap batch size at 10 on existing jobs, not only new ones; mapping `exists` caches are no longer persisted (smaller job rows).
- Batch handler raises time/memory limits and catches fatals with a logged error response.

---

## [3.0.6] - 29/06/2026

### Fixed
- Large imports stuck at 95% remapping after only ~50 entities: manifest JSON no longer round-trips reliably on big WXR files, so completion now uses persisted `manifest_entity_total` instead of `count( item_manifest )`.
- Jobs that entered remapping too early are auto-recovered back to `processing` on the next batch.
- Refreshing the import page no longer shows "Missing import file ID" — step 2 redirects to `step=3&job_id=N`, and GET requests resume the active job.
- Progress page shows remapping/download log lines and surfaces AJAX batch errors.

---

## [3.0.5] - 29/06/2026

### Fixed
- Fatal error after upload: restored missing `get_import_options()` used during WXR preflight scan (settings step).

---

## [3.0.4] - 29/06/2026

### Fixed
- Upload progress percentage was hidden inside the 8px progress bar; percent now displays above the bar with correct percentage-based fill width.

---

## [3.0.3] - 29/06/2026

### Added
- `class-wxr-import-item.php` — per-entity tracking in `wp_wxr_import_items` (imported/skipped/failed per batch).
- Deferred attachment download phase: attachments queued during content import, downloaded 3 at a time **before** remapping (`downloading_attachments` status).
- `wxr_importer_cleanup_import_meta()` — removes all `_wxr_import_*` post/comment meta on completion and uninstall.
- `bin/test-import.sh` — Phase 0 test harness (DB restore, WP-CLI import, JSON results).

### Changed
- Job processor records `item_results` from each batch into the items table.
- WP-Cron and active-job queries include `downloading_attachments` status.
- Progress UI shows "Downloading attachments…" phase label.
- `uninstall.php` drops tables, clears meta, removes chunk dirs, unschedules cron hooks.

### Removed
- `assets/import.js`, `templates/import.php`, `class-logger-serversentevents.php` (legacy SSE path).
- Deprecated unused `WXR_Importer` properties (`processed_posts`, `processed_terms`, `processed_menu_items`, `menu_item_orphans`).

---

## [3.0.2] - 29/06/2026

### Added
- Tabbed upload UI on step 0 (Upload file | Media library | Server path) instead of stacked options.
- Step breadcrumb navigation across upload, settings, and import screens.
- Per-type progress bars on the import page (`12 / 45` with fill bar).
- Entity counter on progress page (`3,241 of 4,597 entities processed`).
- `wxr_importer.web_batch_size` filter (default **50** for web UI).

### Changed
- Web UI default batch size increased from 10 to **50** items per AJAX request.
- Settings step (author mapping) now loads shared importer CSS.
- Progress page status banner and cards use consistent styling (no broken WP notice classes).
- Resume/last-import banners use styled banners instead of raw admin notices.

### Fixed
- Author mapping step missing styles (cards, grid, author list).
- Media library picker submits via dedicated tab form.
- Progress page CSS/JS conflicts from mixed `notice` and custom classes.

---

## [3.0.1] - 29/06/2026

### Added
- **Import resume flow** — step 3 (`?step=3&job_id=N`) reopens progress for in-progress or completed jobs.
- **Final import report** on the progress page when a job completes (imported/skipped/failed breakdown).
- Intro page banners: resume in-progress imports, view last completed report.
- `WXR_Import_Job::get_final_report()` and repository helpers `get_active_job_for_user()` / `get_last_completed_for_user()`.
- Legacy session cleanup on upgrade (`_wxr_import_settings` meta removed).
- One-time admin notice after upgrade explaining job-based architecture.

### Changed
- **Retired SSE import stream** — `stream_import()` now returns HTTP 410 with job resume URL when available.
- Removed SSE helper methods (`emit_sse_message`, `imported_post`, etc.) from active UI path.
- Author slug overrides now correctly restored from job options during batch processing.

### Fixed
- Author mapping structure (`mapping` + `slug_overrides`) now passed correctly to the batch processor.

---

## [3.0.0] - 29/06/2026

### Added
- **Resumable import job architecture** with custom tables `wp_wxr_import_jobs` and `wp_wxr_import_items`.
- `WXR_Import_Job` model with persisted progress, mapping state, and job-scoped logging.
- `WXR_Import_Job_Repository` for database access.
- `WXR_Import_Processor` batch engine with per-job locking and WP-Cron fallback (`wxr_importer_process_batch`).
- `WXR_Import_Remapper` for batched post-processing (parents, authors, URLs, featured images).
- `WXR_Importer::import_batch()` and `build_import_manifest()` for slice-based WXR processing.
- `WXR_Importer::get_mapping_state()` / `set_mapping_state()` for cross-request remapping persistence.
- Job-based admin UI (`templates/job-progress.php`, `assets/job-status.js`) with polling instead of SSE.
- AJAX endpoints: `wxr-import-batch`, `wxr-import-status`, `wxr-import-pause`, `wxr-import-resume`.
- WP-CLI enhancements: `--batch-size`, `--no-attachments`, `--dry-run`, plus `status`, `cancel`, `list`, `report`, `clean` subcommands.
- `install.php` activation schema and `uninstall.php` cleanup.
- Test fixtures (`tests/fixtures/basic-export.xml`) and PHPUnit tests for jobs, batches, and preflight.
- `docs/IMPLEMENTATION_STATUS.md` tracking rebuild progress.

### Changed
- Plugin renamed to **Better WordPress Importer** (v3.0.0).
- Import step 3 now creates a persisted job and uses batch polling (SSE path retained but deprecated).
- `get_preliminary_information()` records `item_positions` byte offsets per `<item>`.
- `cache_flush_interval` option (default 200) replaces hardcoded cache flush interval.
- Diagnostic upload logging gated behind `WP_DEBUG && WXR_IMPORTER_DIAGNOSTICS`, writes to `error_log` not plugin root.
- Chunk upload directories get `web.config` (IIS) protection alongside `.htaccess`.
- `.gitignore` allows `tests/fixtures/*.xml`.

### Fixed
- Abandoned chunk directories cleaned via daily `wxr_importer_cleanup_chunks` cron.
- Cancel import now marks associated jobs as `cancelled`.

### Security
- Removed web-accessible `wxr-upload-debug.log` writes from plugin root.

### Known Limitations
- Legacy SSE endpoint (`wxr-import`) remains for backward compatibility; will be removed in a future cleanup pass.
- `XMLReader` fast-forward to batch cursor is O(n) per batch — WP-CLI recommended for very large files.
- Phase 6 cleanup (full SSE removal, legacy meta migration) not yet complete.

---

## [2.1.0] - 29/06/2026

### Added
- **Chunked XML browser uploads** using Plupload `chunk_size` (`8mb`) so large WXR/XML files are no longer sent as one huge request.
- Server-side chunk assembly for XML uploads, including upload-session isolation, temporary chunk storage, final XML validation, attachment registration, and scheduled cleanup.
- Upload diagnostics written to `wxr-upload-debug.log` for cases where webserver/proxy/FastCGI failures happen before WordPress can write to `WP_DEBUG_LOG`.
- Detailed upload error messages in `assets/intro.js`, including HTTP status, response snippet, and filename.
- Import-stream diagnostics for `stream_import()`, including stream entry, settings load, file resolution, import start/return, cleanup, completion emission, and shutdown state.
- Stronger SSE flushing for normal progress messages and logger events.
- Import-stream frontend error context showing the last received stream action before interruption.
- Import JS cache busting via `filemtime()` so browser refreshes load the current import handler after plugin changes.
- Auditor/rebuild documentation under `docs/`:
  - `AUDITOR_PROMPT.md`
  - `PROJECT_CONTEXT_FOR_AUDIT.md`
  - `ARCHITECTURE_AUDIT.md`
  - `REBUILD_PLAN.md`
  - `TEST_PLAN.md`
  - `UI_UX_PLAN.md`
  - `MIGRATION_AND_COMPATIBILITY.md`
  - `BUILDER_PROMPT.md`
  - `REVIEW_CHECKLIST.md`

### Changed
- Upload script versioning now uses `filemtime()` instead of a static or false version.
- Plupload settings now pass upload action, nonce, and upload session through top-level uploader params.
- No-JS upload field now uses `name="import"` to match the async upload handler.
- Chunked upload handling now uses the original filename from the Plupload `name` request parameter instead of validating the temporary chunk filename (`blob`).
- Browser-uploaded assembled XML files are registered as private import attachments with `application/xml`.
- Import progress handling now attempts reconnects after a dropped EventSource connection as a short-term mitigation.
- Cancel import now clears any pending reconnect timer before closing the stream.
- `stream_import()` now restores the stored `is_local_file` flag before cleanup so local-path imports are not treated as browser uploads.

### Fixed
- Fixed false "Invalid file type" errors on chunked uploads caused by Plupload sending chunk file names as `blob`.
- Fixed missing diagnostics for upload failures that never reached WordPress/PHP.
- Fixed import JS error handling so malformed SSE payloads show a user-facing error instead of silently breaking the page.
- Fixed possible corrupted Plupload JSON responses by discarding unexpected buffered output before sending async upload responses.
- Fixed SSE messages and log events not explicitly flushing PHP output buffers before `flush()`.

### Security
- Chunk upload temporary directories now include basic `index.php` and `.htaccess` protections.
- Chunked upload validates XML extension and leading XML/WXR content before registering the assembled file.
- Diagnostic logging was added for debugging but is not suitable as the final production logging architecture; the audit identifies this as a must-fix before a rebuild release.

### Documentation
- Added an architecture audit and phased rebuild plan for the future **Better WordPress Importer** direction.
- Added a builder prompt and review checklist for handing the rebuild to another model/developer.
- Documented that the current long `admin-ajax.php`/SSE import flow is fragile and should be replaced with a real resumable job/batch architecture.
- Documented that reconnect/deduplication is only a temporary mitigation and not true server-side resumability.

### Development / Local Environment
- During local debugging, LocalWP Apache/FastCGI settings were adjusted outside the plugin to allow larger and longer-running local requests:
  - `FcgidMaxRequestLen 1073741824`
  - `FcgidIOTimeout 3600`
  - `FcgidBusyTimeout 3600`
- These LocalWP server changes are not plugin code, do not affect production hosting, and should not be considered the long-term importer architecture.

### Known Limitations
- The import engine still fundamentally relies on one long request for the actual import.
- EventSource reconnects can rerun the import and rely on deduplication, but this is not true persisted resumability.
- A full rebuild should introduce import jobs, persisted mappings, batch processing, resumable finalization, and real server-side progress.

---

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


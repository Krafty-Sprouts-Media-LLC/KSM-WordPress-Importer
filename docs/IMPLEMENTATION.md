# Implementation Plan: Better WordPress Importer

## Version

**Fresh start at 1.0.0.** This is a new independent plugin, not a fork and not a v3 continuation. Experimental v3.0.x data is detected on activation but never dropped automatically. Cleanup requires an explicit Settings-page action.

## MVP Scope

The MVP is import-only:

- Reliable WXR upload and local-file selection.
- Fast preflight scan with compact manifest metadata.
- Persistent queue rows for every XML entity.
- Parse each XML entity once, store its normalized payload in the queue row, and resume all sub-steps from that payload.
- Time-based processing, per-entity checkpoints, pause/resume/cancel, structured job logs, and honest progress UI.

Exporter and Better Package format are Phase 2. Do not build exporter UI, exporter AJAX, package writer, package reader, or export WP-CLI commands in the MVP.

## Target File Structure

```text
better-wordpress-importer/
  plugin.php
  uninstall.php
  AGENTS.md
  src/
    Core/
      class-better-import-job.php
      class-better-import-job-repository.php
      class-better-import-queue-item.php
      class-better-import-queue-repository.php
      class-better-import-processor.php
      class-better-import-remapper.php
      class-better-install.php
      class-better-logger.php
    Importer/
      class-better-importer.php
      class-better-wxr-parser.php
      class-better-preflight.php
    Format/
      class-better-format-detector.php
    Upload/
      class-better-chunked-upload.php
    CLI/
      class-better-cli-command.php
  admin/
    class-better-admin-ui.php
    class-better-import-ajax.php
  templates/
    import-upload.php
    import-settings.php
    import-progress.php
    history.php
  assets/
    js/
      import-upload.js
      import-progress.js
    css/
      admin.css
  .legacy/
    class-wxr-importer.php
    class-wxr-import-ui.php
  tests/
    bootstrap.php
    fixtures/
    test-class-better-importer.php
    test-class-better-wxr-parser.php
    test-class-better-import-job.php
    test-class-better-import-processor.php
  docs/
```

Existing reference folders such as `WordPress-Importer-master/` and `export-media-with-selected-content/` are reference material only. Keep them under `.legacy/reference/` or exclude them from the plugin build and bootstrap. They must not be loaded by `plugin.php`.

## Architecture Rule

Do not implement byte-offset seeking.

Do not implement:

- `fseek()` into XML for entity processing.
- `seek_by_byte_offset()`.
- `advance_to_entity()` by counting XMLReader reads for normal batch processing.
- Byte-offset manifest fields.
- Parsed-data transient caches.

The queue table is the source of truth. Each queue row owns status, sub-step cursors, counters, errors, and `parsed_payload`. `parsed_payload` is gzipped serialized normalized entity data. It is created once, reused across sub-steps, and cleared when the entity reaches `complete`, `skipped`, or terminal `failed`.

## Phase A: Preflight Manifest and Queue Seed

**Goal:** Replace the 500-item cap with a compact manifest for all entities and queue rows for every entity.

Manifest entries contain only:

```json
{ "i": 123, "t": "post", "id": "456", "title": "Example title" }
```

Acceptance criteria:

- A real 11,673-entity WXR creates a complete compact manifest.
- 11,673 queue rows are inserted.
- No manifest entry contains a byte offset or raw XML payload.
- No XML seek helper exists in the implementation.

## Phase B: Queue Schema

Create `better_import_queue` with at least:

- `job_id`
- `entity_index`
- `entity_type`
- `old_entity_id`
- `new_entity_id`
- `status`
- `step`
- `step_cursor`
- `step_total`
- `parsed_payload` as `LONGBLOB`
- `payload_hash`
- `attempts`
- `error_code`
- `error_message`
- timestamps

Statuses: `pending`, `in_progress`, `partial`, `complete`, `skipped`, `failed`.

Steps for posts: `parse`, `create`, `import_meta`, `import_comments`, `assign_terms`, `complete`.

Activation must create new tables and flag old experimental data. It must not drop or truncate old `wxr_import_*` tables automatically.

## Phase C: Processing Engine

Processing is time-based, not entity-count-based. A browser poll or cron tick should process until the configured time budget is nearly exhausted, then return a clean status response.

Pseudo-flow:

```php
while ( $timer->has_time_remaining() ) {
    $item = $queue->next_work_item( $job_id );
    if ( ! $item ) {
        break;
    }

    $processor->ensure_payload( $item );
    $processor->process_next_step( $item );
    $processor->checkpoint( $item );
}
```

Important behavior:

- Job creation parses XML once and stores `parsed_payload` before a queue row is processed.
- `ensure_payload()` validates that the queue row has its persisted payload; missing payload is an explicit failure, not a reason to reopen XML.
- Sub-steps read from `parsed_payload`, not from XML.
- Meta imports are chunked and idempotent.
- A PHP crash after post creation resumes from the saved step and cursor.
- A duplicate existing post with missing import markers is treated as a possible partial import, not blindly skipped.
- Failed expensive meta rows are surfaced as failed/retryable. They are never silently dropped.

## Phase D: UI and AJAX

The UI must show phase-aware progress:

- Scanning
- Queueing
- Importing
- Remapping
- Complete

Status responses must distinguish:

- total entities
- scanned
- queued
- imported
- skipped
- failed
- partial
- by-type totals and counters
- current entity title and sub-step
- last structured log entries
- pause/resume/cancel capability

The Pause button must be authoritative: it updates job status, prevents new batches, and makes cron/AJAX release locks cleanly.

## Phase E: Cron and WP-CLI

Cron hook: `better_importer_process_batch`.

Primary WP-CLI command: `wp better-importer import <file>`.

Optional legacy aliases may be provided, but they must delegate to the new command and be covered by tests.

Acceptance criteria:

- Browser-driven import continues while the page is open.
- Cron can continue an abandoned job.
- WP-CLI import uses the same queue engine as AJAX.
- No separate CLI-only import logic.

## Phase F: Cleanup and Migration Safety

Remove or quarantine dead experimental code:

- SSE import path.
- Old `assets/import.js` and `templates/import.php`.
- Root diagnostic log files.
- Direct dependence on the legacy `WP_Importer` engine.

Guarded migration:

```php
if ( get_option( 'wxr_importer_db_version' ) && ! get_option( 'better_importer_db_version' ) ) {
    better_importer_install_tables();
    better_importer_flag_legacy_experimental_data();
    // No automatic DROP TABLE. No automatic TRUNCATE.
}
```

The Settings page may expose a clearly labeled cleanup action for old experimental tables. That action requires nonce, capability check, confirmation, and structured logging.

## Backward Compatibility

| Concern | Handling |
|---------|----------|
| Plugin folder | `better-wordpress-importer` |
| Tools import registration | Register as a better WordPress/WXR importer while remaining independent from the legacy plugin |
| WXR compatibility | Support WXR 1.0 to 1.2 |
| WP-CLI | Primary command is `wp better-importer import` |
| Legacy WP-CLI | Optional aliases may delegate to the new command |
| Primary hooks | `better_importer.pre_process.*`, `better_importer.processed.*` |
| Legacy hooks | Optional compatibility bridge only if documented and tested |
| Core hooks | Fire `import_start` and `import_end` |
| PHP / WordPress | PHP 7.4+ and WordPress 5.0+ unless project requirements change |

## Phase 2

Exporter and Better Package format belong in Phase 2:

- Streaming WXR exporter.
- Better Package `.bwxr` writer/reader.
- Media resolver for filtered exports.
- Export UI screens.
- Export WP-CLI commands.

Do not let Phase 2 files or UI block the MVP importer.

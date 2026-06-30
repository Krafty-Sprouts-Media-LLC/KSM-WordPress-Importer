# Architecture Audit: Better WordPress Importer

> **Design principle:** Better WordPress Importer must be an **independent plugin**. It does not depend on the legacy WordPress Importer plugin or its engine. The old `humanmade/WordPress-Importer` code is a compatibility reference only. The plugin registers under `Tools -> Import` for familiarity, but its MVP architecture - parser, job engine, work queue, progress UI - is self-contained. Exporter work is Phase 2.

## 1. What Was Built

A job-based import architecture was built as 16 new files (all uncommitted) layered on top of the original v2 codebase:

| File | Lines | Role |
|------|-------|------|
| `class-wxr-import-job.php` | 609 | Job model — create, state transitions, manifest management |
| `class-wxr-import-job-repository.php` | 412 | Database access layer for `better_import_jobs` |
| `class-wxr-import-item.php` | 309 | Per-entity item model + repository for `better_import_items` |
| `class-wxr-import-processor.php` | 709 | Orchestrates batch processing, locking, phase management |
| `class-wxr-import-remapper.php` | 226 | Post-import remapping in resumable batches |
| `class-logger-job.php` | 55 | Logger writing to job record |
| `install.php` | 147 | Table creation, cleanup, activation hooks |
| `uninstall.php` | 44 | Table drop, option cleanup |
| `templates/job-progress.php` | 169 | New step 3 template (replaced `import.php`) |
| `assets/job-status.js` | 391 | Polling-based progress UI (replaced `import.js`) |
| `assets/intro-tabs.js` | 19 | Tab switching for upload sources |

Plus modifications to every original file:

| File | Major Changes |
|------|--------------|
| `plugin.php` | 6 new class loads, 4 new AJAX hooks, cron schedules, activation hook, version → 1.0.0 |
| `class-wxr-importer.php` | 1200+ new lines: `import_batch()`, `get_mapping_state()`, `set_mapping_state()`, `process_post_meta_resumable()`, manifest scanning, deferred attachment queue, 30+ new methods |
| `class-wxr-import-ui.php` | `handle_import_batch()`, `handle_import_status()`, `handle_import_pause()`, `handle_import_resume()`, job-based redirects |
| `class-wxr-import-info.php` | Preflight entity counts and compact manifest metadata |
| `class-command.php` | Rewritten for batch-based WP-CLI |

**Deleted:** `class-logger-serversentevents.php`, `templates/import.php`, `assets/import.js` — SSE path dead.

---

## 2. Failure Evidence

Tested against a real 11,673-entity WXR file (4,597 posts, 6,460 media, 599 terms, 17 users):

### 2.1 Manifest size cap is the root architectural flaw

`class-wxr-import-job.php` (line ~271 in `create()`):

```php
// Only store full manifest for exports <= 500 items
if ( $total_items <= 500 ) {
    $manifest = $importer->build_import_manifest( $file, $preflight );
    $job->item_manifest = wp_json_encode( $manifest );
}
```

For 11,673 entities, this means **the manifest is stored as empty JSON**. Every `import_batch()` call must re-scan the XML from position zero to find the current cursor entity. At entity index 7220:

- XMLReader must call `read()` 7,220+ times, passing through every prior entity's opening tag
- Each `read()` that encounters a post-level `<item>` has no DOM cost, but 7,220 I/O operations add up
- With 30-second cron windows, the scan alone can consume the entire batch window — zero actual import work gets done

This produces the observed symptom: **"Scanning XML batch from entity 7220 (100 items)..." with no progress**. The scan never finishes before the timeout.

### 2.2 Repeated XML re-scanning

Even when a manifest IS stored, the failed rebuild still re-opens XMLReader and advances by *entity index* (ordinal position). That requires O(n) sequential reads from the top of the file:

```php
// Conceptually — advance reader to entity at $start_index
while ( $reader->read() ) {
    if ( $reader->nodeType === XMLReader::ELEMENT && $reader->name === 'item' ) {
        $current_index++;
        if ( $current_index >= $start_index ) break;
    }
}
```

This is O(cursor_position) per batch - O(n^2) overall for the full import.

The fix is not byte seeking. XMLReader cannot safely resume from the middle of a WXR document. The fix is to parse each entity once into a persistent queue payload, then run all import sub-steps from that payload.

### 2.3 Entity-level atomicity without sub-entity checkpointing

`process_manifest_entity()` at line 3269 treats one XML entity as one atomic unit of work. For a post with 200 meta rows, all 200 must be processed within a single request or the entity is left partially imported.

The rebuild partially addresses this with `process_post_meta_resumable()` (line 1484) which chunks meta import in groups of 25 — but this only activates when `job_id` is set and the entity spans multiple AJAX calls. The `import_batch()` method at line 3002 calls `process_manifest_entity()` which does NOT use the resumable meta path by default.

### 2.4 Partial post creation leaks dirty state

"Some posts were inserted before the request died, causing partial imports, false failed counts, duplicate/confusing state."

When `wp_insert_post()` succeeds but meta/comment import dies:
- The post exists in the database (visible, indexed, queryable)
- The queue item is marked `failed` because meta import failed
- On retry/re-run, `post_exists()` returns true, the post is skipped
- No meta is imported because the post is treated as "already exists"
- Result: post exists but is missing all custom fields, featured image, comments, and terms

The `mark_resumable_import_post()` method (line 2703) tries to fix this by tagging posts with `_better_import_job_id` and `_better_import_original_id`, but these markers only work if the same job instance resumes — not on a fresh import of the same file.

### 2.5 Entity timeout handling is absent

`import_batch()` at line 3002 processes entities in a `while` loop until the batch size is reached. There is no per-entity timeout. If one post has a 5MB serialized meta value in Rank Math or Link Whisper format, `maybe_unserialize()` can consume seconds or exhaust memory. The entire batch dies with no per-entity error recovery.

### 2.6 Stale batch logic is fragile

`class-wxr-import-processor.php` at line ~200 attempts to detect stale batches (where the cursor hasn't advanced between calls) by comparing entity IDs. If the same entity is returned twice, it halves the batch size. At batch size 1, it skips the entity. This is reactive (detect failure after it happened) rather than proactive (time-limit the work).

### 2.7 Progress UI shows "scanned" + "queued" as the same thing

The progress endpoint returns `processed_posts` etc. as counters, but during the scanning phase (when the manifest is being rebuilt on-the-fly for large files), the UI shows no progress. Users see "Scanning XML batch..." with no counters advancing, which looks like a hang.

---

## 3. Wrong Assumptions

| Assumption | Reality |
|-----------|---------|
| "Storing the manifest for >500 items uses too much DB space" | A compact manifest with index, type, old ID, and title is small enough for normal jobs. Large payloads belong in queue rows, not in one giant job blob. |
| "XMLReader can efficiently advance N entities by counting read() calls" | 7,220 sequential read() calls per batch × 460 batches = 3.3M read() calls over the import. Each must traverse XML structure. |
| "A post entity with 200 meta rows can be processed atomically" | The user's real data has some meta values that are megabytes. A single entity can take >30 seconds. |
| "Batch size = number of entities per request" | Should be: "process until N seconds have elapsed, regardless of entity count." |
| "WP-Cron fires reliably enough for continuous batch processing" | On low-traffic sites, cron may not fire for hours. Admin-ajax polling is the primary driver, cron is a fallback. |
| "Partial post creation is recoverable on re-import" | GUID-based deduplication skips the post entirely, leaving it in a permanently broken state. |
| "The manifest is only needed for small files" | The manifest is the single most performance-critical data structure for large files. |

---

## 4. What Is Salvageable

### Keep (fix, do not discard)

| Component | Reason | Fix Needed |
|-----------|--------|------------|
| `better_import_jobs` table schema | Correct design, covers all needed state | Add `phase`, `phase_cursor`, `last_error` columns |
| `better_import_items` table schema | Good per-entity tracking | Add `sub_step`, `sub_step_cursor`, `parsed_data_hash` |
| `WXR_Import_Job` class | Well-structured model | Remove manifest size cap; always store compact manifest metadata |
| `WXR_Import_Job_Repository` class | Clean DB abstraction | Add batch update methods, cursor methods |
| `WXR_Import_Processor` orchestration | Good phase/state flow | Rewrite execution loop to be time-based, not count-based |
| `WXR_Import_Remapper` | Correct remapping logic | Make idempotent per-entity |
| `get_preliminary_information()` preflight scan | Fast, works | Always store compact manifest metadata and seed queue rows |
| `get_mapping_state()` / `set_mapping_state()` | Correct serialization approach | Add compression for large maps |
| `import_batch()` method | Right approach, wrong execution | Use queue payloads; add entity-level timeouts; use resumable meta processing by default |
| `process_post_meta_resumable()` | Correct chunked meta approach | Enable by default for all posts, not just when job_id is set |
| Chunked upload handler | Works, needed | Move chunk dir protection into `install.php` |
| `handle_import_status()` AJAX endpoint | Correct polling design | Add honest phase reporting (scanning vs processing vs remapping) |
| Pause/resume endpoints | Correct state management | Fix race condition between cron and AJAX |
| Transient-based locking (60s TTL) | Correct concurrency control | Keep as-is |
| `install.php` / `uninstall.php` | Clean lifecycle management | Add migration from corrupted v3.0.x state |
| WP-CLI commands | Solid foundation | Add `--force` flag to re-import posts with missing meta |

### Discard (replace entirely)

| Component | Reason |
|-----------|--------|
| Manifest size cap (500 items) | Root cause of stall — always store full manifest |
| `process_manifest_entity()` | Too coarse — one entity = one atomic unit of work |
| Sequential XMLReader advancement by entity count | O(n^2) performance - replace with one-time XML parse into persistent queue payloads |
| Stale batch detection via entity ID comparison | Reactive, fragile — use per-entity timeouts instead |
| `import_batch()` count-based loop | Replace with time-based loop that checks elapsed seconds |
| SSE `stream_import()` endpoint (already returns 410) | Remove entirely in this rebuild |
| `wxr-upload-debug.log` diagnostic file | Already flagged as must-fix — replace with structured job log |
| Current `job-status.js` progress model | "scanned/queued/imported" conflated — need separate phases |

---

## 5. Core Architecture for the Fix

### 5.1 Compact manifest plus persistent payload queue

```
During preflight:
  For each entity found in the XML:
    manifest[] = {
      index: N,
      type: 'post'|'term'|'user'|'comment',
      old_id: wp:post_id or wp:term_id or wp:author_id content,
      title: post_title or term_name or user_login (for human reference)
    }

Store as JSON in better_import_jobs.item_manifest (longtext).
No byte offsets. No full entity payload in the job record.

For each manifest entry:
  Insert one row into better_import_queue.
  Store status, sub-step cursors, counters, and error state in that row.
  When an entity is first processed, parse the full XML entity once and store it in
  better_import_queue.parsed_payload as gzipped serialized normalized data.
  All post/meta/comment/term sub-steps read from parsed_payload.
  Delete parsed_payload after the entity reaches a terminal state.
```

### 5.2 No byte-offset seeking

```
Do not implement:
  fseek()
  seek_by_byte_offset()
  advance_to_entity() by XMLReader counting
  byte-offset manifests

If a queue item has parsed_payload:
  Resume directly from that payload.

If a queue item is missing parsed_payload:
  Mark it failed with a missing-payload error. Do not reopen XML during batch processing.
```

### 5.3 Time-based batch processing

```
function import_batch(job, max_seconds = 25):
  start = time()
  while (time() - start < max_seconds):
    entity = next_queued_entity()
    if not entity: break
    
    entity_start = time()
    result = process_one_entity(entity)
    
    if result.was_timeout:
      mark entity as 'failed', record error
    elif result.was_fatal:
      mark job as 'failed', return
    
    save job cursor
    
  return batch result
```

### 5.4 Sub-entity checkpointing

For post entities specifically, break processing into independent steps that each checkpoint:

```
process_one_entity(entity):
  switch entity.step:
    case 'parse_and_create':
      read parsed_payload → wp_insert_post → store new_id → step = 'import_meta'
      save checkpoint
      
    case 'import_meta':
      process next chunk of 25 meta rows from previously parsed data
      if more meta rows: save meta_cursor checkpoint, return 'more_work'
      else: step = 'import_comments'
      
    case 'import_comments':
      process next chunk of 10 comments
      if more comments: save comment_cursor, return 'more_work'
      else: step = 'assign_terms'
      
    case 'assign_terms':
      assign all terms in one go
      step = 'complete'
      
    case 'complete':
      mark entity done
```

Each checkpoint persists the entity's current step + cursor to `better_import_items`. Resume picks up exactly where it left off.

### 5.5 Post-without-meta recovery

When a post exists in the DB but `_better_import_job_id` doesn't match the current job, the importer must detect partial state:

```
if post_exists_by_guid(entity.data):
  existing_id = get_existing_post_id()
  if is_partial_import(existing_id):
    // Post was created but meta/terms never imported
    new_id = existing_id  // reuse the existing post
    step = 'import_meta'  // continue from meta import step
  else:
    return 'skipped'  // fully imported post
```

---

## 6. Phase-Based Job Lifecycle

```
SCANNING → PROCESSING → DOWNLOADING_ATTACHMENTS → REMAPPING → COMPLETE
     ↓           ↓               ↓                     ↓
  (fast,     (processes     (deferred media        (post_process,
   read-      entities       downloads,            remap parents,
   only)      from queue)    3 at a time)          featured images,
                                                   URL replacement)
```

Each phase has its own progress counter and can be resumed independently.

## 7. Summary of Required Changes

| # | Change | Priority |
|---|--------|----------|
| 1 | Remove manifest size cap; always store compact metadata and queue rows | **Critical** |
| 2 | Persist parsed entity payloads in the queue; do not implement byte-offset seeking | **Critical** |
| 3 | Replace count-based batch loop with time-based loop (25s max per batch) | **Critical** |
| 4 | Add per-entity timeout (10s default) | **Critical** |
| 5 | Split entity processing into sub-steps with independent checkpointing | **Critical** |
| 6 | Add partial-post detection and recovery on re-import | **High** |
| 7 | Record parsed entity data hash so re-import can detect changed XML | **High** |
| 8 | Separate UI progress counters: scanned / imported / skipped / failed / partial | **High** |
| 9 | Enable `process_post_meta_resumable()` by default for all posts | **Medium** |
| 10 | Add WP-CLI `--force-meta` flag to re-import missing meta on existing posts | **Medium** |
| 11 | Remove SSE endpoint code entirely (not just 410) | **Low** |
| 12 | Replace `wxr-upload-debug.log` with structured job-attached log | **Low** |

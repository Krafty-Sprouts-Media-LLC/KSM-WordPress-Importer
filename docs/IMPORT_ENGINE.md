# Import Engine Design: Better WordPress Importer

> **Architecture:** Parse entities once from XML, store the normalized payload in the queue row. All sub-steps read from the persisted payload — no XML re-parsing, no byte-offset seeking, no transient caching. The queue table IS the source of truth.

## 1. Data Model

### 1.1 `better_import_jobs` — one row per import session

```sql
CREATE TABLE {$wpdb->prefix}better_import_jobs (
    id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    status          VARCHAR(20) NOT NULL DEFAULT 'created',
    phase           VARCHAR(30) NOT NULL DEFAULT '',
    phase_cursor    INT UNSIGNED NOT NULL DEFAULT 0,
    file_path       VARCHAR(500) NOT NULL,
    attachment_id   BIGINT UNSIGNED DEFAULT 0,
    total_posts     INT UNSIGNED NOT NULL DEFAULT 0,
    total_comments  INT UNSIGNED NOT NULL DEFAULT 0,
    total_terms     INT UNSIGNED NOT NULL DEFAULT 0,
    total_users     INT UNSIGNED NOT NULL DEFAULT 0,
    total_media     INT UNSIGNED NOT NULL DEFAULT 0,
    scanned_posts   INT UNSIGNED NOT NULL DEFAULT 0,
    scanned_comments INT UNSIGNED NOT NULL DEFAULT 0,
    scanned_terms   INT UNSIGNED NOT NULL DEFAULT 0,
    scanned_users   INT UNSIGNED NOT NULL DEFAULT 0,
    scanned_media   INT UNSIGNED NOT NULL DEFAULT 0,
    imported_posts  INT UNSIGNED NOT NULL DEFAULT 0,
    imported_comments INT UNSIGNED NOT NULL DEFAULT 0,
    imported_terms  INT UNSIGNED NOT NULL DEFAULT 0,
    imported_users  INT UNSIGNED NOT NULL DEFAULT 0,
    imported_media  INT UNSIGNED NOT NULL DEFAULT 0,
    skipped_posts   INT UNSIGNED NOT NULL DEFAULT 0,
    skipped_comments INT UNSIGNED NOT NULL DEFAULT 0,
    skipped_terms   INT UNSIGNED NOT NULL DEFAULT 0,
    skipped_users   INT UNSIGNED NOT NULL DEFAULT 0,
    skipped_media   INT UNSIGNED NOT NULL DEFAULT 0,
    failed_items    INT UNSIGNED NOT NULL DEFAULT 0,
    options         LONGTEXT DEFAULT NULL,
    preflight_data  LONGTEXT DEFAULT NULL,
    item_manifest   LONGTEXT DEFAULT NULL,
    mapping_state   LONGTEXT DEFAULT NULL,
    user_id         BIGINT UNSIGNED NOT NULL DEFAULT 0,
    started_at      DATETIME DEFAULT NULL,
    completed_at    DATETIME DEFAULT NULL,
    created_at      DATETIME NOT NULL,
    updated_at      DATETIME NOT NULL,
    KEY status (status),
    KEY user_id (user_id)
) {$charset_collate};
```

**Key changes from v3.0.x attempt:**
- `scanned_*` counters separate scanning progress from import progress — UI can report truthfully
- `phase` + `phase_cursor` replace `xml_cursor_item` — supports multi-phase lifecycle
- `mapping_state` stores serialized ID mapping (separate from `options`)
- `started_at` / `completed_at` for elapsed time calculation
- All per-type counters have `scanned`, `imported`, `skipped` variants

### 1.2 `better_import_queue` — one row per entity, payload persisted during queueing

```sql
CREATE TABLE {$wpdb->prefix}better_import_queue (
    id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    job_id          BIGINT UNSIGNED NOT NULL,
    entity_index    INT UNSIGNED NOT NULL,
    entity_type     VARCHAR(20) NOT NULL,
    old_entity_id   VARCHAR(100) NOT NULL DEFAULT '',
    new_entity_id   BIGINT UNSIGNED DEFAULT NULL,
    status          VARCHAR(20) NOT NULL DEFAULT 'pending',
    step            VARCHAR(30) NOT NULL DEFAULT 'create',
    step_cursor     INT UNSIGNED NOT NULL DEFAULT 0,
    step_total      INT UNSIGNED NOT NULL DEFAULT 0,
    parsed_payload  LONGBLOB DEFAULT NULL,
    title           VARCHAR(500) DEFAULT NULL,
    attempts        TINYINT UNSIGNED NOT NULL DEFAULT 0,
    error_message   TEXT DEFAULT NULL,
    error_code      VARCHAR(50) DEFAULT NULL,
    last_error_at   DATETIME DEFAULT NULL,
    created_at      DATETIME NOT NULL,
    updated_at      DATETIME NOT NULL,
    KEY job_entity (job_id, entity_index),
    KEY job_status (job_id, status),
    KEY job_status_step (job_id, status, step)
) {$charset_collate};
```

**`parsed_payload`** is the critical column. It stores the full entity data (post fields, meta, comments, terms) as gzipped serialized PHP. It is populated while queue rows are created, before processing begins, and deleted when the entity reaches `step=complete` to reclaim space.

For a post with 200 meta rows: gzipped payload is typically 5-30KB. Payloads are durable queue state until each entity reaches a terminal status, then completed entities delete theirs.

### 1.3 `better_import_log` — structured log (optional, created on first use)

```sql
CREATE TABLE {$wpdb->prefix}better_import_log (
    id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    job_id      BIGINT UNSIGNED NOT NULL,
    level       VARCHAR(10) NOT NULL DEFAULT 'info',
    message     TEXT NOT NULL,
    entity_index INT UNSIGNED DEFAULT NULL,
    context     LONGTEXT DEFAULT NULL,
    created_at  DATETIME NOT NULL,
    KEY job_id (job_id),
    KEY job_level (job_id, level)
);
```

---

## 2. Preflight + Queue Population (Parse Once)

### 2.1 Preflight scan → entity manifest

The preflight scan uses `XMLReader` in streaming mode (no `expand()`) to discover all entities in the WXR file. For each entity, it records:

```json
[
  {"i": 0, "t": "user",     "id": "12",   "title": "admin"},
  {"i": 1, "t": "user",     "id": "45",   "title": "editor"},
  {"i": 616, "t": "post",    "id": "1543", "title": "Hello World"},
  {"i": 617, "t": "post",    "id": "1544", "title": "Sample Page"},
  {"i": 7076, "t": "attachment", "id": "890", "title": "image.jpg"}
]
```

**No byte offsets.** The manifest records only entity metadata (index, type, old ID, title). Byte offsets were proven fragile — XMLReader cannot seek, and the `read()` counting approach caused the entity-7220 stall.

The manifest is stored in `better_import_jobs.item_manifest` (LONGTEXT, always stored, no cap). ~900KB for 11,673 entities in minimal format.

### 2.2 Queue population from one streaming parse

During job creation, the WXR parser streams forward once. For each entity it builds the compact manifest entry, parses the normalized payload, and inserts a queue row with `parsed_payload` already populated:

```php
foreach ( $manifest as $entry ) {
    $this->queue_repo->insert( array(
        'job_id'        => $job->id,
        'entity_index'  => $entry['i'],
        'entity_type'   => $entry['t'],
        'old_entity_id' => $entry['id'],
        'title'         => $entry['title'],
        'status'        => 'pending',
        'step'          => 'create',       // all entities start at 'create'
        'parsed_payload' => gzcompress( serialize( $payload ), 5 ),
        'payload_hash'   => hash( 'sha256', serialize( $payload ) ),
    ) );
}
```

At this point, the XML file is no longer needed for import processing. The queue IS the work plan.

### 2.3 Entity parsing happens ONCE, while queue rows are seeded

The parser never seeks by byte offset and never advances by entity index. It streams the document once and calls the queue seeder for each parsed entity:

```php
$parser->parse_file( $path, function( $manifest_entry, $payload ) use ( $queue_repo, $job ) {
    $queue_repo->seed_from_manifest( $job->id, array( $manifest_entry ), array(
        $manifest_entry['i'] => $payload,
    ) );
} );
```

**Key:** The expensive part is `expand()` + `parse_entity_node()` — this runs ONCE per entity. All sub-steps (create post, import meta chunks, import comments, assign terms) read from `$queue_item->parsed_payload` — a gzipped blob in the database. No more XML.

### 2.4 Payload lifecycle

```
PENDING (payload already stored)
  → first batch: read payload → step = 'create'
  → create entity → step = 'import_meta', step_cursor = 0
  → import meta chunk 0-24 → step_cursor = 25
  → import meta chunk 25-49 → step_cursor = 50
  ...
  → import all meta → step = 'import_comments'
  → import all comments → step = 'assign_terms'
  → assign terms → step = 'complete', status = 'complete'
  → DELETE parsed_payload (free space)
```

Each pending or in-progress entity has a `parsed_payload`. The payload is cleared as soon as that entity reaches `complete`, `skipped`, or terminal `failed`.

### 2.5 Resume is payload-aware

On resume (next cron/batch):
1. Query for next `status='pending'` OR `status='in_progress'` queue item
2. If `status='pending'` → read `parsed_payload` → process
3. If `status='in_progress'` AND `parsed_payload IS NOT NULL` → read payload → continue from `step` + `step_cursor`
4. If `status='in_progress'` AND `parsed_payload IS NULL` → mark the row failed with a missing-payload error

No transient cache. No re-parsing the same entity. The payload lives in the database until the entity is done.

---

## 3. Job State Machine

### 3.1 Overall job lifecycle

```
CREATED
  ↓ (preflight scan completes)
SCANNED  
  ↓ (manifest built, queue populated)
PROCESSING ←──────┐
  ↓               │ (cron picks up next batch)
  │  ┌─ pause ────┘ (user pauses, cron skips)
  │  │
  │  └─ timeout ──┐ (batch times out, cron retries)
  │               │
  ↓               │
REMAPPING ←───────┘
  ↓
COMPLETE

Any state → FAILED (fatal error)
Any state → CANCELLED (user cancels)
```

### 3.2 Queue item state machine

```
PENDING → IN_PROGRESS → COMPLETE
              ↓
           FAILED (error, retryable)
              ↓
           PENDING (retry, attempts < max)
              ↓
           FAILED (max attempts reached)
              
PENDING → SKIPPED (entity already exists, fully imported)
IN_PROGRESS → SKIPPED (detected as duplicate mid-processing)
```

### 3.3 Transitions

| From | To | Trigger |
|------|----|---------|
| `CREATED` | `SCANNED` | Preflight scan + manifest build complete |
| `SCANNED` | `PROCESSING` | First batch request |
| `PROCESSING` | `PROCESSING` | Each subsequent batch |
| `PROCESSING` | `PAUSED` | User clicks Pause |
| `PAUSED` | `PROCESSING` | User clicks Resume |
| `PROCESSING` | `REMAPPING` | All queue items complete |
| `REMAPPING` | `COMPLETE` | All remapping complete |
| `*` | `FAILED` | Fatal error (unreadable file, DB error) |
| `*` | `CANCELLED` | User clicks Cancel |

---

## 4. Processing Loop

### 4.1 Time-based batch processing

```php
/**
 * Process queue items until N seconds have elapsed.
 *
 * @since 1.0.0
 *
 * @param Better_Import_Job $job             The active import job.
 * @param int               $max_seconds     Max wall-clock time for this batch (default 25).
 * @param int               $entity_timeout  Max seconds per entity before marking failed/retryable (default 10).
 *
 * @return array Batch result with progress counters.
 */
function process_batch( $job, $max_seconds = 25, $entity_timeout = 10 ) {
    $start      = microtime( true );
    $processed  = 0;
    $skipped    = 0;
    $failed     = 0;

    while ( ( microtime( true ) - $start ) < $max_seconds ) {
        $item = $this->queue_repo->get_next_pending_or_inprogress( $job->id );
        if ( ! $item ) {
            return array( 'is_complete' => true );
        }

        $entity_start = microtime( true );
        $result       = $this->process_queue_item( $job, $item );

        if ( ( microtime( true ) - $entity_start ) > $entity_timeout ) {
            $item->mark_failed( 'Entity timeout after ' . round( microtime( true ) - $entity_start, 1 ) . 's' );
            $failed++;
            continue;
        }

        if ( $result['status'] === 'complete' ) $processed++;
        if ( $result['status'] === 'skipped' )   $skipped++;
        if ( $result['status'] === 'failed' )    $failed++;

        $this->job_repo->update_counters( $job );
    }

    return array( 'processed' => $processed, 'skipped' => $skipped, 'failed' => $failed, 'is_complete' => false );
}
```

### 4.2 Entity processing with persistent payload

Every sub-step reads `$item->parsed_payload` — never opens the XML after initial parsing.

```php
function process_queue_item( $job, $item ) {
    set_time_limit( 30 );

    // Parse XML → populate payload if this is the first time
    if ( ! $item->parsed_payload ) {
        $this->parse_entity_into_payload( $item, $job );
    }

    // Uncompress the stored payload
    $data = unserialize( gzuncompress( $item->parsed_payload ) );

    switch ( $item->step ) {
        case 'create':
            return $this->step_create_entity( $job, $item, $data );
        case 'import_meta':
            return $this->step_import_meta_chunk( $job, $item, $data );
        case 'import_comments':
            return $this->step_import_comments_chunk( $job, $item, $data );
        case 'assign_terms':
            return $this->step_assign_terms( $job, $item, $data );
        case 'complete':
            $item->parsed_payload = null; // free storage
            $this->queue_repo->save( $item );
            return array( 'status' => 'complete' );
    }
}

function step_create_entity( $job, $item, $data ) {
    $existing = $this->check_existing( $data, $item->entity_type );

    if ( $existing && $this->is_fully_imported( $existing, $item->entity_type ) ) {
        return array( 'status' => 'skipped' );
    }

    if ( ! $existing ) {
        $new_id = $this->create_entity( $data, $item->entity_type, $job );
    } else {
        $new_id = $existing; // partial import — reuse existing post
    }

    $item->new_entity_id = $new_id;
    $this->mapping->add( $item->entity_type, $item->old_entity_id, $new_id );

    $meta_count    = count( $data['meta'] ?? array() );
    $comment_count = count( $data['comments'] ?? array() );

    if ( $meta_count > 0 ) {
        $item->step        = 'import_meta';
        $item->step_cursor = 0;
        $item->step_total  = $meta_count;
    } elseif ( $comment_count > 0 ) {
        $item->step        = 'import_comments';
        $item->step_cursor = 0;
        $item->step_total  = $comment_count;
    } else {
        $item->step        = 'assign_terms';
    }

    $this->queue_repo->save( $item );
    return array( 'status' => 'more_work' );
}

function step_import_meta_chunk( $job, $item, $data ) {
    $chunk_size = $job->get_option( 'meta_chunk_size', 25 );
    $start      = $item->step_cursor;
    $end        = min( $start + $chunk_size, $item->step_total );
    $meta_rows  = array_slice( $data['meta'], $start, $chunk_size );

    foreach ( $meta_rows as $meta_item ) {
        $this->import_single_meta( $item->new_entity_id, $meta_item, $job );
    }

    $item->step_cursor = $end;
    if ( $item->step_cursor >= $item->step_total ) {
        $item->step = ( count( $data['comments'] ?? array() ) > 0 ) ? 'import_comments' : 'assign_terms';
        $item->step_cursor = 0;
        $item->step_total  = count( $data['comments'] ?? array() );
    }

    $this->queue_repo->save( $item );
    return array( 'status' => 'more_work' );
}
```

**Key:** `$data` comes from `unserialize(gzuncompress($item->parsed_payload))` — the entity was parsed from XML once and its full normalized data (post fields, meta, comments, terms) is stored as a gzipped blob in the queue row. No XMLReader. No transient cache. No byte offsets. The queue IS the state.

---

## 5. Idempotency and Duplicate Detection

### 5.1 Entity-level idempotency

| Entity Type | Uniqueness Key | Check |
|-------------|---------------|-------|
| Post | GUID | `post_exists()` + GUID lookup |
| Comment | `sha1(author:date)` | `comment_exists()` |
| Term | `sha1(taxonomy:slug)` | `term_exists()` |
| User | `user_login` slug | `username_exists()` |

### 5.2 Partial import detection

When a post already exists (GUID match), check if it was partially imported:

```php
function is_partial_import( $existing_post_id, $expected_meta_count ) {
    // Check for our marker — was this post created by us but not finished?
    $job_id      = get_post_meta( $existing_post_id, '_wxr_import_job_id', true );
    $meta_cursor = get_post_meta( $existing_post_id, '_wxr_import_meta_cursor', true );

    if ( $job_id && $meta_cursor !== '' ) {
        // Post was created by this importer and meta import was interrupted
        return true;
    }

    // Heuristic: if the post has fewer meta rows than expected from the WXR,
    // treat as partial (might be from a different import, but we err on the side of re-importing)
    $actual_meta = $this->count_non_internal_meta( $existing_post_id );
    if ( $actual_meta < $expected_meta_count * 0.5 ) {
        return true;
    }

    return false;
}
```

### 5.3 Idempotent meta import

```php
function import_single_meta( $post_id, $meta_item, $job ) {
    // Do not import internal WordPress attachment/edit-lock meta that WordPress regenerates.
    if ( in_array( $meta_item['key'], array( '_wp_attached_file', '_wp_attachment_metadata', '_edit_lock' ) ) ) {
        return true;
    }

    // Check for incomplete class objects (crash prevention)
    $value = maybe_unserialize( $meta_item['value'] );
    if ( $this->value_has_incomplete_class( $value ) ) {
        // Surface as a failed/retryable meta sub-step. Do not silently drop plugin meta.
        return new WP_Error( 'incomplete_class', 'Meta contains incomplete class object' );
    }

    // Use add_post_meta with unique=false to avoid duplicates
    // BUT: this may create duplicates if the same meta key already exists
    // with a different value. Use update_post_meta if the key should be unique.
    $existing = get_post_meta( $post_id, $meta_item['key'], false );
    $value_serialized = maybe_serialize( $value );

    foreach ( $existing as $existing_value ) {
        if ( $existing_value === $value_serialized || $existing_value === $value ) {
            // Exact match already exists — skip
            return true;
        }
    }

    // Meta doesn't exist yet — add it
    $result = add_post_meta( $post_id, wp_slash( $meta_item['key'] ), wp_slash( $value ) );

    return $result;
}
```

### 5.4 Cleanup of checkpoint markers

On entity completion (step = `complete`):
```php
delete_post_meta( $post_id, '_wxr_import_job_id' );
delete_post_meta( $post_id, '_wxr_import_meta_cursor' );
delete_post_meta( $post_id, '_wxr_import_meta_total' );
delete_post_meta( $post_id, '_wxr_import_original_id' );
```

---

## 6. Timeout Strategy

### 6.1 Batch-level timeout
- Each batch processes until 25 seconds of wall-clock time have elapsed
- Leaves 5 seconds for PHP shutdown, HTTP response, and JS processing
- Configurable via `wxr_importer_batch_timeout` filter

### 6.2 Entity-level timeout
- Each entity has 10 seconds to complete its sub-step
- If exceeded, the entity is marked `failed` and processing moves to the next queue item
- Configurable via `wxr_importer_entity_timeout` filter

### 6.3 Meta chunk timeout
- Each meta chunk (25 rows) gets 5 seconds
- If exceeded, the entity is marked `failed` — the meta was too expensive
- The post itself (already created) is preserved

### 6.4 Implementation

```php
// In process_batch()
$entity_start = microtime( true );
register_shutdown_function( function() use ( $item, $entity_start ) {
    $elapsed = microtime( true ) - $entity_start;
    if ( $elapsed > 10 && $item->status === 'in_progress' ) {
        $item->mark_failed( 'Entity timeout after ' . round( $elapsed, 1 ) . 's' );
        $this->queue_repo->save( $item );
    }
} );

$result = $this->process_queue_item( $job, $item );

// Cancel the shutdown function if we finished in time
// (can't unregister, so use a flag)
```

Better approach: **set_time_limit(30)** at the start of each entity, and catch the PHP fatal error by checking `connection_status()` after each meta chunk:

```php
function step_import_meta_chunk( $job, $item ) {
    foreach ( $meta_rows as $i => $meta_item ) {
        // Check if we're approaching the PHP time limit
        if ( $this->approaching_time_limit() ) {
            // Save progress and defer to next batch
            $item->step_cursor = $start + $i;
            $this->queue_repo->save( $item );
            return array( 'status' => 'more_work' );
        }

        $this->import_single_meta( $item->new_entity_id, $meta_item, $job );
    }
}

function approaching_time_limit() {
    $max = ini_get( 'max_execution_time' );
    if ( $max <= 0 ) return false; // unlimited
    
    $usage = $this->get_execution_time();
    return $usage > ( $max * 0.8 ); // 80% of limit
}
```

---

## 7. Retry Strategy

### 7.1 Per-entity retry
- Max 3 attempts per entity
- On each failure, increment `attempts` counter
- On 3rd failure, mark entity as `failed` permanently — do not retry
- User can manually retry failed entities from the UI

### 7.2 Retry backoff
- Attempt 2: process entity with half the normal timeout (5s instead of 10s)
- Attempt 3: isolate the expensive sub-step or meta row, record structured diagnostics, and leave the item failed/retryable if it still cannot complete
- Never silently skip plugin meta. Large meta values must be preserved, imported as raw stored values when possible, or surfaced as an explicit failed/retryable item.

### 7.3 Full import retry
- Creating a new job from the same file:
  - New job gets a new `id`
  - `post_exists_by_guid()` detects existing posts — marks them as `skipped`
  - `is_partial_import()` detection (see 5.2) reopens partial posts for meta/comment import
  - Failed entities from the previous job get fresh attempts in the new job

---

## 8. Concurrency Control

### 8.1 Transient-based locking (kept from v3.0.x)
```php
$lock_key = "wxr_import_job_lock_{$job->id}";
if ( get_transient( $lock_key ) ) {
    return; // Another process is already working on this job
}
set_transient( $lock_key, 1, 60 ); // 60s TTL — auto-releases on crash
```

### 8.2 Cron vs AJAX coordination
- AJAX endpoint `wxr-import-batch` processes one batch immediately (if unlocked)
- Cron hook `better_importer_process_batch` runs every 60s as fallback
- Both check the lock before processing
- Cron skips jobs where `updated_at` is within the last 60 seconds (recently touched by AJAX)
- AJAX is the primary driver; cron ensures progress on stalled browsers

### 8.3 Multiple browser tabs
- The lock prevents two tabs from processing the same job simultaneously
- Second tab's AJAX call receives `{ status: 'locked', message: 'Another process is working on this import.' }`
- UI should show "Processing... (another window may be open)"

---

## 9. Remapping Phase

After all queue items are `complete`, the job enters `REMAPPING` phase:

### 9.1 Remapping sub-phases (ordered)
1. **Post parents** — update `post_parent` for posts with `_wxr_import_parent` meta
2. **Post authors** — update `post_author` for posts with `_wxr_import_user_slug`
3. **Menu items** — update `_menu_item_object_id` for nav_menu_item posts
4. **Comment parents** — update `comment_parent` for orphaned comments
5. **Comment authors** — update `user_id` for comments with `_wxr_import_user`
6. **Featured images** — update `_thumbnail_id` for posts with featured images
7. **Attachment URLs** — replace old URLs in `post_content` with new local URLs

### 9.2 Remapping idempotency
Each remapping operation is idempotent:
- Check the current value before updating
- If already correct, skip
- Each batch of remapping tracks a cursor (post ID or comment ID) and resumes from last processed

---

## 10. Active Plugins Compatibility

The importer works on sites with active plugins. Mitigations are built-in and always active:

| Plugin / Issue | Mitigation |
|----------------|------------|
| Plugins with `save_post` hooks | `wp_suspend_cache_invalidation( true )` + `wp_defer_term_counting( true )` suppress heavy core processing. Third-party hooks are NOT suppressed — plugin meta must be preserved. |
| SEO plugins storing large serialized data in postmeta | All meta values are imported in full. No size limit. If a value causes a timeout, the entity is marked for retry with a longer timeout on the next attempt. |
| Caching plugins (object cache, page cache) | Periodic `wp_cache_flush()` every 200 posts keeps memory flat. Interval is configurable via `better_importer.cache_flush_interval` filter. |
| Security plugins blocking remote requests | Attachment download failures don't block content import — attachments are deferred to a separate phase. |
| Missing custom post types | Detected during preflight, warned to user. Posts with unregistered CPTs are imported as `post_type = post` with original type stored in `_better_import_original_type` meta. |

Hook suppression (`remove_all_actions('save_post')`) is available as an **advanced opt-in only** via the `better_importer.suppress_plugin_hooks` filter — defaults to `false`.

---

## 11. Summary: Key Design Decisions

| Decision | Rationale |
|----------|-----------|
| Parse once, persist payload in queue | Entity parsed from XML ONE time. Gzipped payload stored in `better_import_queue.parsed_payload`. No XML re-reading. No byte-offset seeking. No transient caching of parsed data. |
| Time-based batch loop, not count-based | 25s of work per batch regardless of entity count — one 200-meta post or fifty 2-meta posts |
| Sub-step checkpointing per entity | A post with 200 meta rows can span 8+ batches without losing progress. Payload lives in queue row until entity is complete. |
| Partial import detection via `_better_import_*` markers | Re-import can detect and repair broken partial posts |
| Per-entity timeout with graceful retry | One broken post doesn't kill the entire import. Retry with longer timeout. |
| Plugin meta always preserved | No meta-skipping defaults, no size limits. Time-budget approach instead. |
| Hook suppression is opt-in only | `wp_suspend_cache_invalidation()` + `wp_defer_term_counting()` are always on. Plugin hooks are never suppressed by default. |
| Transient lock + cron fallback | Safe concurrency without external dependencies |

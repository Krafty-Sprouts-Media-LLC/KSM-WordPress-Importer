# Implementation Status — Better WordPress Importer v3.0.3

Last updated: 29/06/2026

## Completed

### Phase 1: Stabilize for Controlled Testing ✅
### Phase 2: Import Job Model ✅
### Phase 3: Batch/Resumable Processing Engine ✅
- Deferred attachment download phase (`downloading_attachments`, 3 per batch, runs before remapping)
- Per-entity item tracking in `wp_wxr_import_items`

### Phase 4: Admin UI ✅
- Tabbed upload, step nav, live progress bars, batch size 50

### Phase 5: WP-CLI ✅
- `--batch-size`, `--no-attachments`, `dry-run`, subcommands

### Phase 6: Migration and Cleanup ✅
- Legacy SSE files removed (`import.js`, `import.php`, `class-logger-serversentevents.php`)
- Deprecated `WXR_Importer` counter properties removed
- `wxr_importer_cleanup_import_meta()` on job completion and uninstall
- Legacy `wxr-import` AJAX returns 410 with resume URL

## Tests Added
| File | Coverage |
|------|----------|
| `tests/test-importer-plugin.php` | Plugin loads |
| `tests/test-class-wxr-import-job.php` | Job create, persist, percent |
| `tests/test-class-wxr-import-processor.php` | Batch import, full job, resume |
| `tests/test-class-wxr-import-item.php` | Per-entity item rows |
| `tests/test-class-wxr-importer-preflight.php` | Preflight scan, manifest |
| `tests/fixtures/basic-export.xml` | Smoke fixture |

## Tooling
- `bin/test-import.sh` — restore DB snapshot, run WP-CLI import, write JSON results to `test-results/`

## Remaining (optional / manual)
- Browser upload integration tests
- Large XML performance tests (5000+ items) — use `bin/test-import.sh`
- Real WXR validation on production-like data (requires explicit DB approval)
- PHPUnit must be installed locally (`bash bin/install-wp-tests.sh`)

## Compatibility
- Importer slug: `wordpress` (Tools → Import → WordPress (v2))
- Legacy `wxr-import` AJAX returns 410 with resume URL when a job exists
- Hooks: `wxr_importer.job.created`, `wxr_importer.job.completed`
- Filter: `wxr_importer.web_batch_size` (default 50)

## Migration
- Reactivate plugin to ensure tables exist (db version 3.0.3)
- Resume URL: `admin.php?import=wordpress&step=3&job_id={id}`

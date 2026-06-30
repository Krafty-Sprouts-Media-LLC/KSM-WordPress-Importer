# AGENTS.md — Better WordPress Importer

> **Purpose:** This file instructs AI coding agents (and human contributors) on how to work with this codebase. It supplements the skill files in `/.agents/` with project-specific rules derived from the architecture audit.

## Project identity

- **Plugin:** Better WordPress Importer
- **Folder:** `better-wordpress-importer`
- **Registered as:** `wordpress` (Tools → Import → WordPress (v2))
- **Reference:** [humanmade/WordPress-Importer](https://github.com/humanmade/WordPress-Importer) — compatibility reference only, our engine is independent
- **Text Domain:** `better-wordpress-importer`
- **Version:** 1.5.0 (1.0 rebuild on `main`; see [Versioning](#versioning-and-releases) below)
- **Target:** WordPress 5.0+ | PHP 7.4+
- **License:** GPLv2+

## Audit status (June 2026)

The full codebase was audited. See `docs/ARCHITECTURE.md`. Key findings driving the rebuild:

1. **The single SSE request architecture is fragile** — imports over a few thousand items fail due to timeouts (FastCGI, proxy, browser). This is the root cause of all reliability issues.
2. **No true resumability** — the auto-reconnect JS patch is cosmetic. Progress resets on reconnect.
3. **Manifest size cap (500 items)** — caused the "stalled at entity 7220" bug. Byte-offset manifest must be stored unconditionally.
4. **No entity-level timeout** — a single post with large meta could stall the entire import.

There are uncommitted emergency patches in the working tree (chunked upload, reconnect, diagnostics). These are temporary. Do not build on them — they will be replaced in the rebuild.

## Reference: skill files in `/.agents/`

Load the relevant skill before writing code in that domain:

| Skill | When |
|-------|------|
| `wp-plugin-development` | Plugin architecture, hooks, Settings API, activation/uninstall |
| `wp-standards.md` | File headers, docblocks, naming, escaping, sanitization, security checklist |
| `wp-performance` | Performance profiling, caching, query optimization |
| `wp-wpcli-and-ops` | WP-CLI commands, deployment, testing |
| `wp-rest-api` | REST API endpoints (if added) |
| `wp-plugin-directory-guidelines` | wp.org submission compliance |
| `blueprint` | Playground blueprints |

## Workflow: rebuild phases

Development follows the phased plan in `docs/IMPLEMENTATION.md`. **Current phase: G+ / Phase 2 prep** (Phase F shipped in `1.4.0`). Phases A–F are shipped in `1.0.0`–`1.4.0`. Full doc set:

| Doc | Purpose |
|-----|---------|
| `docs/ARCHITECTURE.md` | What failed, what's salvageable, what must be discarded |
| `docs/IMPORT_ENGINE.md` | Data model, state machine, time-based batching, sub-step checkpointing, retry/idempotency |
| `docs/IMPLEMENTATION.md` | Phase-by-phase build plan (A–J), migration from experimental v3.0.x |
| `docs/TEST_PLAN.md` | Unit, integration, large fixture, failure injection, browser, WP-CLI tests |
| `docs/UI_UX.md` | Screen designs, progress model, pause/resume/cancel, accessibility |
| `docs/EXPORTER.md` | Export engine, WXR format, Better Package format, background processing |
| `docs/PACKAGE_FORMAT.md` | `.bwxr` Better Package format specification (JSON schemas, ZIP structure) |

## Versioning and releases

This project follows [Semantic Versioning](https://semver.org/). Release notes live in `CHANGELOG.md`.

### Semver rules (mandatory)

| Bump | When | Example |
|------|------|---------|
| **PATCH** `1.x.y` | Bug fixes only, backward compatible | `1.3.1` fixes a cron lock bug |
| **MINOR** `1.x.0` | New features, backward compatible | `1.4.0` ships Phase F cleanup |
| **MAJOR** `x.0.0` | Breaking changes | `2.0.0` drops a public API |

**Do not** use patch releases (`1.0.1`, `1.0.2`, `1.0.3`, …) for new rebuild phases. Each implementation phase from `docs/IMPLEMENTATION.md` is a **minor** release (`1.1.0`, `1.2.0`, …), not a patch.

### Phase → version map (1.0 rebuild)

| Phase | Version | What shipped |
|-------|---------|--------------|
| A+B | **1.0.0** | `Better_Install`, `Better_Preflight`, job model, queue seeding |
| C | **1.1.0** | `Better_Import_Processor`, parser, importer, logger, remapper, WP-Cron |
| D | **1.2.0** | Admin UI, AJAX, templates, assets, pause/resume/status |
| E | **1.3.0** | `Better_CLI_Command`, `wp better-importer` subcommands |
| F | **1.4.0** | Legacy cleanup, settings maintenance, guarded v3 migration |
| G | **1.5.0** | Format detector, chunked browser uploads, chunk dir protection |
| G+ / Phase 2 | **1.6.0+** | Continue incrementing **minor** per phase unless the change is patch-only |

When starting a new phase:

1. Bump **minor** version in `plugin.php` header and `BETTER_IMPORTER_VERSION`.
2. Add a new section at the top of `CHANGELOG.md` with today's date (`dd/MM/yyyy`).
3. Tag new symbols with `@since` matching that minor version (see below).

Patch releases (`1.3.1`, etc.) are only for bug fixes on top of an already-shipped minor — never for new classes, endpoints, or phases.

## Coding standards

### PHP

#### File headers (`@since` is mandatory)

Every PHP file must start with a file-level docblock:

```php
<?php
/**
 * [ One-line summary of what this file/class is for. ]
 *
 * @package Better_WordPress_Importer
 * @since 1.0.0
 */
```

For the main plugin file (`plugin.php`), use the full WordPress plugin header:

```php
<?php
/**
 * Plugin Name: Better WordPress Importer
 * Plugin URI:  https://github.com/Krafty-Sprouts-Media-LLC/KSM-WordPress-Importer
 * Description: Import posts, pages, comments, custom fields, categories, tags and more from a WordPress export file. Resumable, batch-based, large-file safe.
 * Version:     1.0.0
 * Author:      Krafty Sprouts Media, LLC
 * License:     GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: better-wordpress-importer
 * Requires at least: 5.0
 * Requires PHP: 7.4
 *
 * @package Better_WordPress_Importer
 * @since 1.0.0
 */

defined( 'ABSPATH' ) || exit;
```

#### Class files

Every class file needs both a file-level and class-level docblock:

```php
<?php
/**
 * Import job model — represents one import session.
 *
 * Replaces the fragile SSE-based import with persisted job state
 * that survives timeouts, browser closes, and server restarts.
 *
 * @package Better_WordPress_Importer
 * @since 1.0.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Import job model.
 *
 * @since 1.0.0
 */
class Better_Import_Job {
```

#### Public methods

Every `public` method must have a complete PHPDoc block:

```php
/**
 * Create a new import job from an uploaded WXR file.
 *
 * Performs a preflight scan, records item counts and byte offsets,
 * and persists job state to the database.
 *
 * @since 1.0.0
 *
 * @param int    $attachment_id WordPress attachment ID of the WXR file.
 * @param string $file_path     Absolute filesystem path to the WXR file.
 * @param array  $options       Import options (author mapping, fetch_attachments, etc.).
 *
 * @return WXR_Import_Job|WP_Error Job instance on success, error otherwise.
 */
public static function create( int $attachment_id, string $file_path, array $options = array() ) {
```

#### Protected/private methods

Document non-trivial methods. Simple helpers (<10 lines, obvious purpose) can skip the full docblock but should still have `@since`:

```php
/**
 * Advance the XML reader to the item at the given index in the manifest.
 *
 * Uses pre-recorded byte offsets from the preflight scan for O(1) seeks.
 * Falls back to sequential reading if the manifest is missing.
 *
 * @since 1.0.0
 *
 * @param XMLReader $reader       Active XML reader instance.
 * @param array     $manifest     Item manifest from preflight scan.
 * @param int       $target_index Zero-based item index to advance to.
 *
 * @return bool True on success, false if the reader reached EOF.
 */
protected function seek_to_item( XMLReader $reader, array $manifest, int $target_index ): bool {
```

#### `@since` tag rules

`@since` is the plugin version when a symbol was **first added**. Never update `@since` when modifying existing code. One `@since` per docblock.

| Location | Required | Value |
|----------|----------|-------|
| File-level docblock | Yes | Version when the file was first added |
| Class docblock | Yes | Version when the class was first added |
| Public method | Yes | Version when **that method** was first added |
| Protected/private non-trivial | Yes | Version when **that method** was first added |
| Hook registration comment | Yes | Version when the hook was first registered |
| Property | Recommended | Version when the property was first added |

**Debut version by rebuild phase** (use these instead of defaulting everything to `1.0.0`):

| Debut version | Symbols |
|---------------|---------|
| `1.0.0` | Phase A+B: install, preflight, job/queue models, `Better_Import_Job::create()`, queue seeding |
| `1.1.0` | Phase C: processor, parser, importer, logger, remapper, `better_importer_process_batch` cron |
| `1.2.0` | Phase D: admin UI, AJAX, templates, assets, `to_status_array()`, pause/resume/cancel, queue status helpers |
| `1.3.0` | Phase E: `Better_CLI_Command`, `better_importer_register_cli()` |
| `1.4.0` | Phase F: `Better_Legacy_Cleanup`, `Better_Admin_Settings`, `maybe_upgrade_from_legacy()` |
| `1.5.0` | Phase G: `Better_Format_Detector`, `Better_Chunked_Upload`, chunked upload AJAX + cron |
| `1.6.0+` | Next phase — use the actual minor version when the symbol ships |

New methods on an existing class get the version **that method** debuted in, not the class file's original version.

Legacy v3.x symbols under `.legacy/` keep their original `@since 3.x.x` tags — do not rewrite them.

#### Properties

```php
/**
 * Mapping of old entity IDs to new local IDs.
 *
 * Structure: { entity_type: { old_id: new_id, ... }, ... }
 * Entity types: 'post', 'comment', 'term', 'term_id', 'user', 'user_slug'.
 *
 * @since 1.0.0
 * @var array<string, array<int, int>>
 */
protected array $mapping = array();
```

#### Inline comments

Rule: explain **why**, not what. Never add comments that restate the code.

```php
// Use array_key_exists, not isset — isset returns false for null values
// and we need to preserve explicitly saved null mappings.
if ( array_key_exists( $old_id, $this->mapping['post'] ) ) {
```

Never:
- Section dividers (`// ============ SETTINGS ============`)
- Disabled/old code (delete it — git has the history)
- TODO markers — use `// @todo Issue #N: Description` if you must

### Naming

| Element | Style | Example | Prefix |
|---------|-------|---------|--------|
| Class | PascalCase | `Better_Import_Job` | `Better_` |
| Method | snake_case | `get_job_by_id()` | — |
| Variable | snake_case | `$import_job` | — |
| Constant | UPPER_SNAKE | `BETTER_IMPORT_MAX_WXR_VERSION` | — |
| Hook (action) | `wxr_importer.` | `wxr_importer.job.completed` | `wxr_importer.` (backward compat) |
| Hook (filter) | `wxr_importer.` | `wxr_importer.admin.import_options` | `wxr_importer.` (backward compat) |
| New hook (action) | `better_importer.` | `better_importer.job.created` | `better_importer.` (new code) |
| New hook (filter) | `better_importer.` | `better_importer.batch.size` | `better_importer.` (new code) |
| AJAX action | `better-import-` | `better-import-status` | `better-import-` |
| Nonce action | `better-import.` | `better-import.job:123` | `better-import.` |
| DB table | `better_import_` | `better_import_jobs` | `better_import_` |
| Option key | `better_importer_` | `better_importer_db_version` | `better_importer_` |
| Post meta (temp) | `_better_import_` | `_better_import_parent` | `_better_import_` |
| Transient | `better_import_` | `better_import_job_lock_123` | `better_import_` |

### Escaping output (always)

```php
echo esc_html( $message );            // HTML text content
echo esc_attr( $value );              // HTML attribute
echo esc_url( $url );                 // URL in href/src
echo esc_textarea( $text );           // Textarea content
wp_kses( $html, $allowed_tags );      // Trusted HTML with known tag whitelist
```

### Sanitizing input (always)

```php
sanitize_text_field( wp_unslash( $_POST['name'] ) );  // Text
absint( $_GET['id'] );                                  // Integer
sanitize_key( wp_unslash( $_POST['key'] ) );           // Slugs/keys
esc_url_raw( wp_unslash( $_POST['url'] ) );            // URL for storage
```

Always `wp_unslash()` before sanitize.

### Security checklist (per feature)

- [ ] Nonce verification on all forms and AJAX endpoints
- [ ] `current_user_can( 'import' )` or `current_user_can( 'upload_files' )` on admin actions
- [ ] Input sanitized with appropriate WordPress function
- [ ] Output escaped with appropriate WordPress function
- [ ] `$wpdb->prepare()` for all SQL queries with variables
- [ ] No user input concatenated into SQL strings
- [ ] `wp_safe_redirect()` + `exit` for redirects
- [ ] File paths validated with `realpath()` + boundary checks (stay inside uploads)

## Database: custom tables vs. post meta

The rebuild introduces custom tables (`wp_wxr_import_jobs`, `wp_wxr_import_items`). Rules:

- Table creation: `dbDelta()` in an activation hook
- Schema version tracked in `wp_options` (`wxr_importer_db_version`)
- Upgrade routine checks version and applies migrations
- `uninstall.php` drops tables and cleans meta
- Legacy `_wxr_import_*` post meta remains as the temporary remapping mechanism during imports

## Testing

Tests live in `tests/` and use PHPUnit via WordPress's test suite:

```bash
# Install test suite (one-time)
bash bin/install-wp-tests.sh wordpress_test root '' localhost latest

# Run tests
phpunit
```

Test conventions:
- One test file per source class: `tests/test-class-wxr-importer.php`
- Fixtures in `tests/fixtures/` (XML files, not committed unless small)
- `WP_UnitTestCase` base class provides DB transaction rollback
- Test method naming: `test_method_name_scenario()` — e.g. `test_process_post_duplicate_skipped()`

## Git workflow

- **Do not commit** until explicitly asked
- Uncommitted emergency patches (visible in `git diff`) will be replaced in the rebuild — do not build on them
- Commit messages: imperative, present tense, 50-char subject line
- The `.gitignore` currently blocks `*.xml`; this needs updating so test fixtures can be committed

## Key files reference (1.0 rebuild — active code)

| File | Role | Status |
|------|------|--------|
| `plugin.php` | Bootstrap, cron, WP-CLI registration | Active |
| `src/Core/class-better-import-job.php` | Job model | Active |
| `src/Core/class-better-import-processor.php` | Time-based batch engine | Active |
| `src/Core/class-better-import-queue-repository.php` | Queue persistence | Active |
| `src/Importer/class-better-preflight.php` | WXR preflight scan | Active |
| `src/Importer/class-better-importer.php` | Entity import logic | Active |
| `src/CLI/class-better-cli-command.php` | WP-CLI commands | Active (since 1.3.0) |
| `src/Core/class-better-legacy-cleanup.php` | Legacy v3 cleanup helpers | Active (since 1.4.0) |
| `admin/class-better-admin-settings.php` | Settings / maintenance screen | Active (since 1.4.0) |
| `admin/class-better-admin-ui.php` | Admin screens | Active |
| `admin/class-better-import-ajax.php` | AJAX endpoints | Active |
| `templates/import-*.php`, `templates/history.php`, `templates/settings.php` | Admin templates | Active |
| `assets/js/import-*.js`, `assets/css/admin.css` | Progress/upload UI | Active |
| `.legacy/` | Frozen v3 reference code | Not loaded — do not extend |

## Priority order for fixes

1. **Must fix before production:** Manifest cap removal (Phase A), diagnostic log removal, chunk dir web protection
2. **Should fix during rebuild:** Time-based batching (Phase C), sub-step checkpointing (Phase C), true resume (Phase C)
3. **Nice to have:** Cache flush configurability, prefill query optimization

## Priority order for fixes

1. **Must fix before production:** Diagnostic log removal (S3.1), chunk dir web protection (S3.2)
2. **Should fix during rebuild:** Architecture to job-based (R2.1), true resume (R2.2), state persistence (R2.3)
3. **Nice to have:** Cache flush configurability (P4.3), prefill query optimization (P4.2)

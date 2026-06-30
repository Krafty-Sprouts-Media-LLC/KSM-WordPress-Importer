# Better WordPress Importer

Resumable, batch-based WordPress WXR importer for large sites. Built by [Krafty Sprouts Media LLC](https://github.com/Krafty-Sprouts-Media-LLC).

Registers as **Tools → Import → WordPress (v2)** and adds **Tools → Better Importer** for history and settings.

---

## Features

| Capability | Details |
|---|---|
| **Resumable imports** | Job state persists in custom tables; survives timeouts, browser closes, and server restarts |
| **Time-based batching** | Each HTTP request processes only what fits in a time budget |
| **Sub-step checkpoints** | Large posts import meta and comments in chunks without re-parsing XML |
| **Chunked browser uploads** | Plupload 8 MB chunks assemble large XML files server-side |
| **Local file import** | Point at an `.xml` already in `wp-content/uploads/` to bypass browser limits |
| **Preflight scan** | Fast entity counts and queue seeding before import starts |
| **Pause / resume / cancel** | Full control from the progress screen |
| **WP-CLI** | `wp better-importer` (alias: `wp wxr-importer`) |
| **Legacy cleanup** | Settings screen to remove v3 experimental tables, meta, and chunk dirs |

Better Package (`.bwxr`) format detection is included; full package import is planned for a future release. Upload a standard WordPress XML export (`.xml`) today.

---

## Requirements

- WordPress 5.0+
- PHP 7.4+

---

## Installation

1. Copy or clone into `wp-content/plugins/better-wordpress-importer/`
2. Activate **Better WordPress Importer** under **Plugins**
3. Go to **Tools → Import → WordPress (v2)**

### Large files (100 MB+)

**Option A — server path (recommended for very large exports)**

1. Copy your `.xml` into `wp-content/uploads/` via SFTP
2. On the upload screen, use **Use a file already on the server**
3. Enter the full path shown on that screen

**Option B — chunked browser upload**

Upload through the drag-and-drop zone. Files larger than the PHP upload limit are sent in 8 MB Plupload chunks and assembled automatically.

---

## WP-CLI

```sh
wp better-importer import /path/to/export.xml
wp better-importer import /path/to/export.xml --default-author=1 --fetch-attachments
wp better-importer status <job_id>
wp better-importer list
wp better-importer cancel <job_id>
wp better-importer report <job_id>
```

The `wp wxr-importer` alias points to the same command.

---

## What gets imported

| Content | Imported |
|---|---|
| Posts, pages, custom post types | Yes — content, meta, status, dates, slugs |
| Categories, tags, custom taxonomies | Yes — created or mapped to existing terms |
| Comments | Yes — including comment meta |
| Authors | Yes — map to existing users or create new |
| Featured images & attachments | Yes — when **Download attachments** is enabled or files exist locally |
| Parent/child & menu relationships | Yes — remapped after import |
| Plugin-specific meta (missing plugin) | Skipped with a log warning; import continues |
| Site settings / theme options | Not in WXR format |
| User passwords | Never exported by WordPress |

---

## Architecture

The 1.0 rebuild replaces the fragile single-request SSE design with:

- `wp_better_import_jobs` — job state and options
- `wp_better_import_queue` — one row per XML entity with gzipped parsed payload
- `wp_better_import_log` — structured per-job log

See `docs/ARCHITECTURE.md`, `docs/IMPORT_ENGINE.md`, and `docs/IMPLEMENTATION.md` for the full design.

Legacy v3 experimental code is quarantined under `.legacy/` and is **not** loaded. The frozen v3.0.8 layout lives on the `legacy-v3` git branch.

---

## Development

```sh
# Install PHP dependencies
composer install

# Install WordPress test suite (one-time)
bash bin/install-wp-tests.sh wordpress_test root '' localhost latest

# Run tests (when test suite is present)
composer exec phpunit
```

Contributors: read `AGENTS.md` for coding standards, versioning, and phase map.

Test fixtures live in `tests/fixtures/`.

---

## License

GPLv2 or later. See [LICENSE](LICENSE).

## Credits

Inspired by [humanmade/WordPress-Importer](https://github.com/humanmade/WordPress-Importer). This is an independent engine, not a fork of that codebase.

Maintained by [Krafty Sprouts Media LLC](https://github.com/Krafty-Sprouts-Media-LLC).

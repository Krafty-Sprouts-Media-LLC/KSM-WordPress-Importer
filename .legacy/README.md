# Legacy code (v3.0.x experimental)

This folder holds the pre-1.0 import engine. It is **not loaded** by `plugin.php` on the `main` branch.

Use this code only as a reference. The frozen v3.0.8 layout at the plugin root is preserved on the `legacy-v3` git branch.

**Phase F (1.4.0)** quarantined this code here. The active engine lives under `src/`, `admin/`, `templates/`, and `assets/`. Legacy `wxr_import_*` tables and meta can be removed from **Tools → Importer Settings** when you are ready.

## Contents

| Path | Description |
|------|-------------|
| `class-wxr-*.php` | v3 job-based / SSE import engine |
| `class-command.php` | Legacy WP-CLI `wxr-importer` command (replaced by `Better_CLI_Command` in 1.3.0) |
| `class-logger*.php` | Logger implementations |
| `install.php` | v3 table installer (`wxr_import_*`) |
| `templates/` | v3 admin templates (SSE `import.js` path) |
| `assets/` | v3 admin JavaScript and CSS |
| `tests/` | PHPUnit tests for the v3 engine |
| `bin/` | v3 smoke / import scripts |
| `reference/` | Upstream and third-party reference copies |

## Git branches

| Branch | Purpose |
|--------|---------|
| `main` | 1.0.x rebuild (active development) |
| `legacy-v3` | Frozen snapshot of v3.0.8 with files at plugin root |

Do not require or bootstrap files from this folder in new code.

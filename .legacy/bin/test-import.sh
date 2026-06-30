#!/usr/bin/env bash
#
# WXR import test harness — restore DB, run import, record results.
#
# Usage:
#   ./bin/test-import.sh path/to/export.xml [snapshot.sql]
#
# Requires WP-CLI in PATH and a configured WordPress install.
#
# @package WordPress_Importer_v2
# @since 3.0.0

set -euo pipefail

WXR_FILE="${1:-}"
SNAPSHOT="${2:-pre-import.sql}"
RESULTS_DIR="$(dirname "$0")/../test-results"
TIMESTAMP="$(date +%Y%m%d-%H%M%S)"
BASENAME="$(basename "${WXR_FILE%.xml}")"

if [[ -z "$WXR_FILE" || ! -f "$WXR_FILE" ]]; then
	echo "Usage: $0 path/to/export.xml [snapshot.sql]" >&2
	exit 1
fi

mkdir -p "$RESULTS_DIR"

if [[ -f "$SNAPSHOT" ]]; then
	echo "Restoring database from ${SNAPSHOT}..."
	wp db import "$SNAPSHOT"
fi

echo "Resetting plugin state..."
wp plugin deactivate wordpress-importer-v2 2>/dev/null || true
wp plugin activate wordpress-importer-v2

START_TIME="$(date -u +%Y-%m-%dT%H:%M:%SZ)"

echo "Running import: ${WXR_FILE}"
wp wxr-importer import "$WXR_FILE" --batch-size=50

END_TIME="$(date -u +%Y-%m-%dT%H:%M:%SZ)"

POSTS="$(wp post list --post_type=post,page --format=count)"
TERMS="$(wp term list category --format=count 2>/dev/null || echo 0)"
COMMENTS="$(wp comment list --format=count 2>/dev/null || echo 0)"

OUTPUT="${RESULTS_DIR}/${TIMESTAMP}-${BASENAME}.json"

cat > "$OUTPUT" <<EOF
{
  "file": "$(basename "$WXR_FILE")",
  "started_at": "${START_TIME}",
  "finished_at": "${END_TIME}",
  "posts": ${POSTS},
  "terms": ${TERMS},
  "comments": ${COMMENTS}
}
EOF

echo "Results written to ${OUTPUT}"

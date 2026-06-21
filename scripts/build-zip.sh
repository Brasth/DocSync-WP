#!/usr/bin/env bash
#
# Build an installable plugin ZIP locally.
# Ensures built assets are included even though build/ is gitignored.
#
# Usage: ./scripts/build-zip.sh [output-dir]

set -euo pipefail

PLUGIN_SLUG="brasth-document-sync-for-google-docs"
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(cd "${SCRIPT_DIR}/.." && pwd)"
OUTPUT_DIR="${1:-${PROJECT_ROOT}}"
VERSION="$(
  awk -F':' '/^[[:space:]]*\*[[:space:]]*Version:/ {gsub(/^[[:space:]]+|[[:space:]]+$/, "", $2); print $2; exit}' \
    "${PROJECT_ROOT}/${PLUGIN_SLUG}.php"
)"

if [ -z "${VERSION}" ]; then
  echo "Could not parse plugin version from ${PLUGIN_SLUG}.php" >&2
  exit 1
fi

mkdir -p "${OUTPUT_DIR}"

STAGING_DIR="$(mktemp -d)"
RSYNC_EXCLUDES="$(mktemp)"
ZIP_NAME="${PLUGIN_SLUG}-v${VERSION}.zip"
ZIP_PATH="${OUTPUT_DIR}/${ZIP_NAME}"

cleanup() {
  rm -rf "${STAGING_DIR}" "${RSYNC_EXCLUDES}"
}
trap cleanup EXIT

# Build assets if missing
if [ ! -f "${PROJECT_ROOT}/build/manifest.json" ]; then
  echo "Built assets not found. Running pnpm build..."
  if ! command -v pnpm >/dev/null 2>&1; then
    echo "pnpm is not installed. Install it first: https://pnpm.io/installation" >&2
    exit 1
  fi
  (cd "${PROJECT_ROOT}" && pnpm install --frozen-lockfile && pnpm build)
fi

# Stage files using .distignore (keep leading / for root-only matching)
mkdir -p "${STAGING_DIR}/${PLUGIN_SLUG}"
cp "${PROJECT_ROOT}/.distignore" "${RSYNC_EXCLUDES}"
printf '%s\n' '*.zip' >> "${RSYNC_EXCLUDES}"
rsync -a "${PROJECT_ROOT}/" "${STAGING_DIR}/${PLUGIN_SLUG}/" --exclude-from="${RSYNC_EXCLUDES}"

# Validate
if [ ! -f "${STAGING_DIR}/${PLUGIN_SLUG}/build/manifest.json" ]; then
  echo "Staging failed: build/manifest.json is missing." >&2
  exit 1
fi

# Create ZIP
rm -f "${ZIP_PATH}"
(cd "${STAGING_DIR}" && zip -qr "${ZIP_PATH}" "${PLUGIN_SLUG}")

echo "Created ${ZIP_PATH}"

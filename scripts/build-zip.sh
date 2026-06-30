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
REQUIRED_BUILD_MANIFESTS=(
  "manifest.setup.json"
  "manifest.sources.json"
  "manifest.logs.json"
  "manifest.post-sync.json"
  "manifest.doc-source-modal.json"
  "manifest.drive-browser.json"
)
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

has_build_manifests() {
  local build_dir="$1"
  local manifest

  for manifest in "${REQUIRED_BUILD_MANIFESTS[@]}"; do
    if [ ! -f "${build_dir}/${manifest}" ]; then
      return 1
    fi
  done

  return 0
}

validate_build_manifests() {
  local build_dir="$1"
  local label="$2"
  local manifest

  for manifest in "${REQUIRED_BUILD_MANIFESTS[@]}"; do
    if [ ! -f "${build_dir}/${manifest}" ]; then
      echo "${label}: build/${manifest} is missing." >&2
      exit 1
    fi
  done
}

validate_forbidden_paths() {
  local plugin_dir="$1"
  local forbidden_path
  local forbidden_paths=(
    "cloudflare"
  )

  for forbidden_path in "${forbidden_paths[@]}"; do
    if [ -e "${plugin_dir}/${forbidden_path}" ]; then
      echo "Staging failed: installable plugin must not contain ${forbidden_path}." >&2
      exit 1
    fi
  done
}

# Build assets if missing
if ! has_build_manifests "${PROJECT_ROOT}/build"; then
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
validate_build_manifests "${STAGING_DIR}/${PLUGIN_SLUG}/build" "Staging failed"
validate_forbidden_paths "${STAGING_DIR}/${PLUGIN_SLUG}"

# Create ZIP
rm -f "${ZIP_PATH}"
(cd "${STAGING_DIR}" && zip -qr "${ZIP_PATH}" "${PLUGIN_SLUG}")

echo "Created ${ZIP_PATH}"

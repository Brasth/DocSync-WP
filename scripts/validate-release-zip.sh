#!/usr/bin/env bash
#
# Validate the installable plugin ZIP produced by scripts/build-zip.sh.
#
# Usage: bash scripts/validate-release-zip.sh /path/to/plugin.zip

set -euo pipefail

PLUGIN_SLUG="brasth-document-sync-for-google-docs"
ZIP_PATH="${1:?Usage: bash scripts/validate-release-zip.sh /path/to/plugin.zip}"

if [ ! -f "${ZIP_PATH}" ]; then
  echo "Release ZIP does not exist: ${ZIP_PATH}" >&2
  exit 1
fi

ZIP_LIST="$(mktemp)"
trap 'rm -f "${ZIP_LIST}"' EXIT

unzip -Z1 "${ZIP_PATH}" > "${ZIP_LIST}"

TOP_LEVELS="$(awk -F/ 'NF {print $1}' "${ZIP_LIST}" | sort -u)"
if [ "${TOP_LEVELS}" != "${PLUGIN_SLUG}" ]; then
  echo "ZIP must contain exactly one top-level ${PLUGIN_SLUG}/ directory." >&2
  echo "Found top-level paths:" >&2
  echo "${TOP_LEVELS}" >&2
  exit 1
fi

REQUIRED_FILES=(
  "${PLUGIN_SLUG}/${PLUGIN_SLUG}.php"
  "${PLUGIN_SLUG}/vendor/autoload.php"
  "${PLUGIN_SLUG}/build/manifest.setup.json"
  "${PLUGIN_SLUG}/build/manifest.sources.json"
  "${PLUGIN_SLUG}/build/manifest.logs.json"
  "${PLUGIN_SLUG}/build/manifest.post-sync.json"
  "${PLUGIN_SLUG}/build/manifest.doc-source-modal.json"
  "${PLUGIN_SLUG}/build/manifest.drive-browser.json"
  "${PLUGIN_SLUG}/readme.txt"
)

for required_file in "${REQUIRED_FILES[@]}"; do
  if ! grep -Fxq "${required_file}" "${ZIP_LIST}"; then
    echo "ZIP is missing required file: ${required_file}" >&2
    exit 1
  fi
done

REQUIRED_DIRS=(
  "${PLUGIN_SLUG}/build/"
  "${PLUGIN_SLUG}/resources/"
)

for required_dir in "${REQUIRED_DIRS[@]}"; do
  if ! grep -Fq "${required_dir}" "${ZIP_LIST}"; then
    echo "ZIP is missing required directory: ${required_dir}" >&2
    exit 1
  fi
done

FORBIDDEN_PATHS=(
  ".dockerignore"
  ".DS_Store"
  ".git"
  ".github"
  ".claude"
  ".agents"
  ".codex"
  ".devcontainer"
  ".opencode"
  "AGENTS.md"
  "node_modules"
  ".pnpm-store"
  "docs"
  "plans"
  "tests"
  "coverage"
  "phpcs.xml.dist"
  "assets"
  "cloudflare"
  "scripts"
)

for forbidden_path in "${FORBIDDEN_PATHS[@]}"; do
  full_path="${PLUGIN_SLUG}/${forbidden_path}"
  if grep -Fq "${full_path}/" "${ZIP_LIST}" || grep -Fxq "${full_path}" "${ZIP_LIST}"; then
    echo "ZIP contains excluded development path: ${full_path}" >&2
    exit 1
  fi
done

if grep -Eiq '\.zip$' "${ZIP_LIST}"; then
  echo "ZIP contains a nested ZIP file." >&2
  exit 1
fi

if grep -Eiq '(^|/)\.DS_Store$' "${ZIP_LIST}"; then
  echo "ZIP contains a macOS .DS_Store file." >&2
  exit 1
fi

if grep -Eiq '(^|/)(client_secret[^/]*\.json|\.secrets/|\.env($|\.))' "${ZIP_LIST}"; then
  echo "ZIP contains a credential file or directory." >&2
  exit 1
fi

echo "Release ZIP validation passed: ${ZIP_PATH}"

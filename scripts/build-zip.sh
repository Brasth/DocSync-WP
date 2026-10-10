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
PDF_RENDERER_ENTRY="resources/js/admin/features/add-content/pdf-renderer.ts"
REQUIRED_BUILD_MANIFESTS=(
  "manifest.setup.json"
  "manifest.sources.json"
  "manifest.folders.json"
  "manifest.logs.json"
  "manifest.post-sync.json"
  "manifest.doc-source-modal.json"
  "manifest.drive-browser.json"
  "manifest.pdf-renderer.json"
)

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

# Vite manifest paths must stay inside build/assets/js. Reject absolute paths,
# URL workers, parent segments, and unexpected characters before touching disk.
is_local_js_asset() {
  local relative="$1"

  case "${relative}" in
    ""|/*|../*|*/../*|*/..|..|*..*|*://*)
      return 1
      ;;
  esac

  case "${relative}" in
    assets/js/*) ;;
    *) return 1 ;;
  esac

  case "${relative}" in
    *[![:alnum:]./_-]*)
      return 1
      ;;
  esac

  return 0
}

# PHP is already required to prove Smalot\PdfParser\Parser. Manifest JSON and
# realpath use that same interpreter. Do not add another runtime.
require_php() {
  if ! command -v php >/dev/null 2>&1; then
    echo "Staging failed: php is unavailable, so ${1}." >&2
    exit 1
  fi
}

php_realpath() {
  local target="$1"
  local resolved

  require_php "staged build paths cannot be resolved"

  # Keep this -r program on one line. The parser check below is the only
  # multiline `php -r` block, and the fixture extracts that block by source.
  if ! resolved="$(php -d display_errors=stderr -d error_reporting=E_ALL -r '$path = realpath($argv[1] ?? ""); if (!is_string($path) || $path === "") { fwrite(STDERR, "realpath-failed\n"); exit(1); } echo $path;' -- "${target}")"; then
    echo "Staging failed: ${target} could not be resolved inside the staged build." >&2
    exit 1
  fi

  printf '%s' "${resolved}"
}

assert_staged_build_file() {
  local build_dir="$1"
  local relative="$2"
  local label="$3"
  local path build_real file_real

  if ! is_local_js_asset "${relative}"; then
    echo "Staging failed: ${label} is not a local build/assets/js file." >&2
    exit 1
  fi

  path="${build_dir}/${relative}"

  if [ ! -f "${path}" ] || [ ! -s "${path}" ]; then
    echo "Staging failed: ${label} is missing from the staged build." >&2
    exit 1
  fi

  build_real="$(php_realpath "${build_dir}")"
  file_real="$(php_realpath "${path}")"

  case "${file_real}" in
    "${build_real}/"*) ;;
    *)
      echo "Staging failed: ${label} is outside the staged build." >&2
      exit 1
      ;;
  esac
}

require_pdf_files() {
  local directory="$1"
  local label="$2"
  shift 2
  local found=""

  if [ ! -d "${directory}" ]; then
    echo "Staging failed: ${label} is missing." >&2
    exit 1
  fi

  found="$(find "${directory}" -type f \( "$@" \) -print -quit)"

  if [ -z "${found}" ]; then
    echo "Staging failed: ${label} does not contain the expected PDF.js files." >&2
    exit 1
  fi
}

# Read the staged pdf-renderer manifest. The renderer file is the Vite entry
# AssetRegistry loads. The worker is the local pdf.worker asset that manifest
# records, not a CDN URL and not a guessed filename.
read_pdf_renderer_manifest() {
  local manifest="$1"

  if [ ! -s "${manifest}" ]; then
    echo "Staging failed: build/manifest.pdf-renderer.json is missing." >&2
    exit 1
  fi

  require_php "build/manifest.pdf-renderer.json cannot be read"

  php -d display_errors=stderr -d error_reporting=E_ALL -- "${manifest}" "${PDF_RENDERER_ENTRY}" <<'PHP'
<?php
$manifestPath = $argv[1] ?? '';
$entrySrc = $argv[2] ?? '';

try {
    $raw = file_get_contents($manifestPath);
    if (!is_string($raw)) {
        throw new RuntimeException('unreadable');
    }
    $manifest = json_decode($raw, false, 512, JSON_THROW_ON_ERROR);
} catch (Throwable $exc) {
    fwrite(STDERR, 'manifest.pdf-renderer.json is not readable JSON: ' . $exc->getMessage() . "\n");
    exit(1);
}

if (!is_object($manifest)) {
    fwrite(STDERR, "manifest.pdf-renderer.json is not an object.\n");
    exit(1);
}

$entry = null;
if (property_exists($manifest, $entrySrc) && is_object($manifest->{$entrySrc})) {
    $entry = $manifest->{$entrySrc};
}

if (!is_object($entry)) {
    foreach ($manifest as $value) {
        if (
            is_object($value)
            && property_exists($value, 'isEntry')
            && $value->isEntry
            && property_exists($value, 'src')
            && $value->src === $entrySrc
        ) {
            $entry = $value;
            break;
        }
    }
}

if (!is_object($entry)) {
    fwrite(STDERR, "manifest.pdf-renderer.json has no pdf renderer entry.\n");
    exit(1);
}

$renderer = property_exists($entry, 'file') ? $entry->file : null;
if (!is_string($renderer) || $renderer === '') {
    fwrite(STDERR, "manifest.pdf-renderer.json renderer file is missing.\n");
    exit(1);
}

$workerFromAssets = null;
if (property_exists($entry, 'assets') && is_array($entry->assets)) {
    foreach ($entry->assets as $asset) {
        if (is_string($asset) && str_contains($asset, 'pdf.worker')) {
            $workerFromAssets = $asset;
            break;
        }
    }
}

$workerFromRecord = null;
foreach ($manifest as $key => $value) {
    if (!is_object($value)) {
        continue;
    }
    $src = (property_exists($value, 'src') && is_string($value->src)) ? $value->src : (string) $key;
    $fileName = property_exists($value, 'file') ? $value->file : null;
    if (is_string($src) && str_contains($src, 'pdf.worker') && is_string($fileName)) {
        $workerFromRecord = $fileName;
        break;
    }
}

if ($workerFromAssets !== null && $workerFromRecord !== null && $workerFromAssets !== $workerFromRecord) {
    fwrite(STDERR, "manifest.pdf-renderer.json worker asset does not match the worker record.\n");
    exit(1);
}

$worker = $workerFromAssets ?? $workerFromRecord;
if (!is_string($worker) || $worker === '' || !str_contains($worker, 'pdf.worker')) {
    fwrite(STDERR, "manifest.pdf-renderer.json has no local pdf.worker asset.\n");
    exit(1);
}

if (!str_ends_with($renderer, '.js') || !str_ends_with($worker, '.js')) {
    fwrite(STDERR, "manifest.pdf-renderer.json renderer and worker must be local .js files.\n");
    exit(1);
}

fwrite(STDOUT, $renderer . "\n" . $worker . "\n");
PHP
}

validate_pdf_renderer_assets() {
  local build_dir="$1"
  local manifest="${build_dir}/manifest.pdf-renderer.json"
  local pdf_root="${build_dir}/assets/pdf"
  local parsed renderer worker build_real license_real

  if ! parsed="$(read_pdf_renderer_manifest "${manifest}")"; then
    echo "Staging failed: build/manifest.pdf-renderer.json is not a usable pdf renderer manifest." >&2
    exit 1
  fi

  renderer="$(printf '%s\n' "${parsed}" | sed -n '1p')"
  worker="$(printf '%s\n' "${parsed}" | sed -n '2p')"

  if [ -z "${renderer}" ] || [ -z "${worker}" ]; then
    echo "Staging failed: build/manifest.pdf-renderer.json did not name a renderer and local worker." >&2
    exit 1
  fi

  case "${renderer}" in
    assets/js/pdfRenderer*.js) ;;
    *)
      echo "Staging failed: manifest pdf renderer file is not the local pdfRenderer bundle." >&2
      exit 1
      ;;
  esac

  assert_staged_build_file "${build_dir}" "${renderer}" "pdf renderer bundle"
  assert_staged_build_file "${build_dir}" "${worker}" "pdf.js local worker"

  require_pdf_files "${pdf_root}/cmaps" "build/assets/pdf/cmaps" -name '*.bcmap'
  require_pdf_files "${pdf_root}/standard_fonts" "build/assets/pdf/standard_fonts" -name '*.pfb' -o -name '*.ttf'
  require_pdf_files "${pdf_root}/wasm" "build/assets/pdf/wasm" -name '*.wasm'

  if [ ! -f "${pdf_root}/LICENSE" ] || [ ! -s "${pdf_root}/LICENSE" ]; then
    echo "Staging failed: build/assets/pdf/LICENSE is missing." >&2
    exit 1
  fi

  build_real="$(php_realpath "${build_dir}")"
  license_real="$(php_realpath "${pdf_root}/LICENSE")"
  case "${license_real}" in
    "${build_real}/"*) ;;
    *)
      echo "Staging failed: build/assets/pdf/LICENSE is outside the staged build." >&2
      exit 1
      ;;
  esac
}

validate_forbidden_paths() {
  local plugin_dir="$1"
  local credential_file
  local forbidden_path
  local forbidden_paths=(
    "cloudflare"
    ".secrets"
    ".rig"
    "docsync-wp-private"
    "docsync-journey-fixtures"
  )

  for forbidden_path in "${forbidden_paths[@]}"; do
    if [ -e "${plugin_dir}/${forbidden_path}" ] || [ -n "$(find "${plugin_dir}" -name "${forbidden_path}" -print -quit)" ]; then
      echo "Staging failed: installable plugin must not contain ${forbidden_path}." >&2
      exit 1
    fi
  done

  credential_file="$(find "${plugin_dir}" -type f \( -name 'client_secret*.json' -o -name '.env' -o -name '.env.*' -o -name 'owner-credentials.json' -o -name 'credentials.json' \) -print -quit)"

  if [ -n "${credential_file}" ]; then
    echo "Staging failed: installable plugin must not contain credential files." >&2
    exit 1
  fi
}

# Fail closed on the staged autoload only. A missing interpreter, a stub class,
# or a parser file outside vendor/smalot/pdfparser must not produce a ZIP.
validate_pdf_parser() {
  local plugin_dir="$1"
  local autoload="${plugin_dir}/vendor/autoload.php"

  if [ ! -f "${autoload}" ] || [ ! -s "${autoload}" ]; then
    echo "Staging failed: staged vendor/autoload.php is missing, so Smalot\\PdfParser\\Parser cannot load." >&2
    exit 1
  fi

  if ! command -v php >/dev/null 2>&1; then
    echo "Staging failed: php is unavailable, so staged vendor/autoload.php cannot be proven to load Smalot\\PdfParser\\Parser." >&2
    exit 1
  fi

  if ! php -d display_errors=stderr -d error_reporting=E_ALL -r '
$autoload = $argv[1];
require $autoload;
if (!class_exists("Smalot\\PdfParser\\Parser", true)) {
  fwrite(STDERR, "class-missing\n");
  exit(1);
}
$reflection = new ReflectionClass("Smalot\\PdfParser\\Parser");
$file = $reflection->getFileName();
if (!is_string($file) || $file === "") {
  fwrite(STDERR, "class-has-no-file\n");
  exit(1);
}
$real_file = realpath($file);
$real_vendor = realpath(dirname($autoload));
if ($real_file === false || $real_vendor === false || !str_starts_with($real_file, $real_vendor . DIRECTORY_SEPARATOR)) {
  fwrite(STDERR, "class-outside-staged-vendor\n");
  exit(1);
}
$normalized = str_replace("\\", "/", $real_file);
if (!str_contains($normalized, "/smalot/pdfparser/")) {
  fwrite(STDERR, "class-not-in-smalot-pdfparser\n");
  exit(1);
}
' "${autoload}"; then
    echo "Staging failed: staged vendor/autoload.php cannot load Smalot\\PdfParser\\Parser." >&2
    exit 1
  fi
}

main() {
  require_php "the pdf renderer manifest, staged paths, and Smalot\\PdfParser\\Parser cannot be checked"

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
  OUTPUT_DIR="$(cd "${OUTPUT_DIR}" && pwd)"

  STAGING_DIR="$(mktemp -d)"
  RSYNC_EXCLUDES="$(mktemp)"
  ZIP_NAME="${PLUGIN_SLUG}-v${VERSION}.zip"
  ZIP_PATH="${OUTPUT_DIR}/${ZIP_NAME}"

  cleanup() {
    rm -rf "${STAGING_DIR}" "${RSYNC_EXCLUDES}"
  }
  trap cleanup EXIT

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
  validate_pdf_renderer_assets "${STAGING_DIR}/${PLUGIN_SLUG}/build"
  validate_forbidden_paths "${STAGING_DIR}/${PLUGIN_SLUG}"
  validate_pdf_parser "${STAGING_DIR}/${PLUGIN_SLUG}"

  # Create ZIP
  rm -f "${ZIP_PATH}"
  (cd "${STAGING_DIR}" && zip -qr "${ZIP_PATH}" "${PLUGIN_SLUG}")

  echo "Created ${ZIP_PATH}"
}

if [[ "${BASH_SOURCE[0]}" == "$0" ]]; then
  main "$@"
fi

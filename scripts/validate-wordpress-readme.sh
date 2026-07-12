#!/usr/bin/env bash
#
# Submit readme.txt to WordPress.org's official Readme Validator.
#
# Usage: bash scripts/validate-wordpress-readme.sh [readme path]

set -euo pipefail

README_PATH="${1:-readme.txt}"
VALIDATOR_URL="https://wordpress.org/plugins/developers/readme-validator/"
RESPONSE_PATH="$(mktemp)"
trap 'rm -f "${RESPONSE_PATH}"' EXIT

if [ ! -f "${README_PATH}" ]; then
  echo "readme.txt does not exist: ${README_PATH}" >&2
  exit 1
fi

# The validator form accepts the readme body as a base64-encoded value.
ENCODED_README="$(php -r 'echo base64_encode(file_get_contents($argv[1]));' "${README_PATH}")"

curl \
  --fail \
  --location \
  --retry 3 \
  --retry-delay 2 \
  --silent \
  --show-error \
  --data-urlencode "readme_contents=${ENCODED_README}" \
  "${VALIDATOR_URL}" \
  > "${RESPONSE_PATH}"

if ! grep -Fq "notice notice-" "${RESPONSE_PATH}"; then
  echo "WordPress.org Readme Validator did not return validation results." >&2
  exit 1
fi

if grep -Eiq "notice notice-(warning|error)|class=['\"]errors['\"]|<li>[^<]*(error|fatal)[^<]*</li>" "${RESPONSE_PATH}"; then
  echo "WordPress.org Readme Validator reported warnings or errors." >&2
  exit 1
fi

echo "WordPress.org Readme Validator passed: ${README_PATH}"

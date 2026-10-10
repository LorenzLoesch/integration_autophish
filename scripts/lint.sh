#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."
directories=()
for directory in lib appinfo templates tests scripts; do [[ ! -d $directory ]] || directories+=("$directory"); done
while IFS= read -r -d '' file; do php -l "$file"; done < <(find "${directories[@]}" -name '*.php' -print0)
shopt -s nullglob
for file in js/*.js; do node --check "$file"; done
php scripts/check-metadata.php

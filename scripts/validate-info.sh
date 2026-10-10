#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."
cache=${INFO_SCHEMA_CACHE:-.cache/appstore}
mkdir -p "$cache"
if [[ ! -s "$cache/info.xsd" ]]; then
  temporary=$(mktemp "$cache/info.xsd.XXXXXX")
  trap 'rm -f "$temporary"' EXIT
  curl --fail --silent --show-error --location --proto '=https' --proto-redir '=https' --retry 3 --max-time 60 \
    https://apps.nextcloud.com/schema/apps/info.xsd -o "$temporary"
  mv "$temporary" "$cache/info.xsd"
fi
# Keep source XML in schema order. Unlike the store's XSLT, this does not drop
# unknown elements, so unsupported metadata fails rather than disappearing.
xmllint --nonet --noout --schema "$cache/info.xsd" appinfo/info.xml
python3 scripts/release.py check

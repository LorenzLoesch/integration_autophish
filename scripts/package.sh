#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."
php scripts/check-metadata.php
version=$(php -r '$xml=simplexml_load_file("appinfo/info.xml"); echo (string)$xml->version;')
[[ $version =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]] || { echo 'Invalid release version' >&2; exit 1; }
mkdir -p dist
stage=$(mktemp -d)
trap 'rm -rf "$stage"' EXIT
mkdir "$stage/integration_autophish"
# Runtime-only allowlist. Development dependencies and local state never enter a release.
for entry in appinfo lib LICENSE license.md README.md; do cp -R "$entry" "$stage/integration_autophish/"; done
for entry in templates js css img; do
  [[ ! -d $entry ]] || cp -R "$entry" "$stage/integration_autophish/"
done
find "$stage" -type d -exec chmod 755 {} +
find "$stage" -type f -exec chmod 644 {} +
archive="dist/integration_autophish-$version.tar.gz"
tar --sort=name --mtime='@0' --owner=0 --group=0 --numeric-owner --format=ustar -cf - -C "$stage" integration_autophish | gzip -n -9 > "$archive"
sha256sum "$archive" > "$archive.sha256"
php scripts/check-artifact.php "$archive"
printf 'Built %s\n' "$archive"

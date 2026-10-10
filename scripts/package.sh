#!/usr/bin/env bash
set -euo pipefail
set +x
cd "$(dirname "$0")/.."
php scripts/check-metadata.php
version=$(php -r 'echo simplexml_load_file("appinfo/info.xml")->version;')
mkdir -p dist
stage=$(mktemp -d)
key_dir=''
container=''
cleanup() {
  if [[ -n $container ]]; then docker rm -fv "$container" >/dev/null 2>&1 || true; fi
  rm -rf "$stage"
  if [[ -n $key_dir ]]; then rm -rf "$key_dir"; fi
}
trap cleanup EXIT
trap 'exit 130' INT
trap 'exit 143' TERM
mkdir "$stage/integration_autophish"
for entry in appinfo lib LICENSE license.md README.md CHANGELOG.md; do cp -R "$entry" "$stage/integration_autophish/"; done
for entry in templates js css img; do
  [[ ! -d $entry ]] || cp -R "$entry" "$stage/integration_autophish/"
done
# Never reuse a signature from the source tree.
rm -f "$stage/integration_autophish/appinfo/signature.json"
find "$stage" -type d -exec chmod 755 {} +
find "$stage" -type f -exec chmod 644 {} +
suffix='-unsigned'
validation=()
if [[ -n ${APP_PRIVATE_KEY:-} || -n ${APP_CERTIFICATE:-} || ${REQUIRE_SIGNING:-false} == true ]]; then
  source scripts/signing-material.sh
  image=$(python3 scripts/release.py image)
  container="autophish-sign-$RANDOM-$$"
  # UID matches the owner of the mode-600 key; keys and staged input stay read-only.
  docker run -d --name "$container" --network none --user "$(id -u):$(id -g)" \
    --cap-drop ALL --security-opt no-new-privileges --entrypoint sleep \
    --mount "type=bind,src=$stage/integration_autophish,dst=/staged,readonly" \
    --mount "type=bind,src=$key_dir,dst=/signing,readonly" \
    --mount "type=bind,src=$(pwd)/scripts,dst=/scripts,readonly" \
    --mount "type=bind,src=$(pwd)/dist,dst=/artifacts,readonly" "$image" infinity >/dev/null
  docker exec "$container" bash /scripts/sign-nextcloud.sh
  docker cp "$container:/tmp/nextcloud/custom_apps/integration_autophish/appinfo/signature.json" "$stage/integration_autophish/appinfo/signature.json"
  chmod 644 "$stage/integration_autophish/appinfo/signature.json"
  suffix=''
  validation=(--signed)
fi
archive="integration_autophish-$version$suffix.tar.gz"
# Create under a temporary name: failed verification must not leave a publishable archive.
tar --sort=name --mtime='@0' --owner=0 --group=0 --numeric-owner --format=ustar -cf - -C "$stage" integration_autophish | gzip -n -9 > "dist/$archive.pending"
python3 scripts/check-artifact.py "dist/$archive.pending" "${validation[@]}"
if [[ -n $container ]]; then docker exec "$container" bash /scripts/verify-nextcloud.sh "$archive.pending"; fi
mv "dist/$archive.pending" "dist/$archive"
(cd dist; sha256sum "$archive" > "$archive.sha256"; sha512sum "$archive" > "$archive.sha512")
printf 'Built %s\n' "dist/$archive"

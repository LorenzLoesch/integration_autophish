#!/usr/bin/env bash
set -euo pipefail
set +x
cd "$(dirname "$0")/.."
version=$(python3 scripts/release.py version)
if [[ ${GITHUB_REPOSITORY:-} != LorenzLoesch/nextcloud_autophish_plugin ]]; then
  printf 'App Store publication is restricted to the upstream repository\n' >&2; exit 1
fi
if [[ -z ${APPSTORE_TOKEN:-} || ( $version == *-* && ${PUBLISH_PRERELEASES:-false} != true ) ]]; then
  printf 'App Store publication skipped: token absent or prerelease publication disabled\n'
  if [[ -n ${GITHUB_OUTPUT:-} ]]; then printf 'published=false\n' >> "$GITHUB_OUTPUT"; fi
  exit 0
fi
archive="integration_autophish-$version.tar.gz"
python3 scripts/check-artifact.py "dist/$archive" --signed
url="https://github.com/LorenzLoesch/nextcloud_autophish_plugin/releases/download/v$version/$archive"
umask 077
temporary=$(mktemp -d)
trap 'rm -rf "$temporary"' EXIT
trap 'exit 130' INT
trap 'exit 143' TERM
# Wait at most two minutes for the public URL, without sending the store token to GitHub.
deadline=$((SECONDS + 120))
ready=false
while (( SECONDS < deadline )); do
  remaining=$((deadline - SECONDS))
  code=$(curl --silent --show-error --proto '=https' --proto-redir '=https' --location \
    --connect-timeout 5 --max-time "$remaining" --output "$temporary/download" --write-out '%{http_code}' "$url") || code=000
  if [[ $code == 200 ]]; then cmp "dist/$archive" "$temporary/download"; ready=true; break; fi
  sleep 3
done
[[ $ready == true ]] || { printf 'GitHub asset did not become available within two minutes\n' >&2; exit 1; }
printf 'Authorization: Token %s\n' "$APPSTORE_TOKEN" > "$temporary/headers"
unset APPSTORE_TOKEN
python3 - "$url" "dist/$archive.sig" > "$temporary/payload.json" <<'PY'
import json,sys
from pathlib import Path
print(json.dumps({'download': sys.argv[1], 'signature': Path(sys.argv[2]).read_text().strip(), 'nightly': False}))
PY
code=$(curl --silent --show-error --proto '=https' --max-time 120 --request POST \
  --header "@$temporary/headers" --header 'Content-Type: application/json' \
  --data-binary "@$temporary/payload.json" --output "$temporary/response" --write-out '%{http_code}' \
  https://apps.nextcloud.com/api/v1/apps/releases)
case "$code" in
  200|201) printf 'App Store accepted %s (HTTP %s)\n' "$version" "$code" ;;
  *) printf 'App Store publication failed (HTTP %s):\n' "$code" >&2; cat "$temporary/response" >&2; exit 1 ;;
esac
if [[ -n ${GITHUB_OUTPUT:-} ]]; then printf 'published=true\n' >> "$GITHUB_OUTPUT"; fi

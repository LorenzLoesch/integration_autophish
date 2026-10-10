#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."
temporary=$(mktemp -d)
trap 'rm -rf "$temporary"' EXIT
# The catalogue is public; do not pass credentials to a public cache/download endpoint.
for attempt in {1..20}; do
  if curl --fail --silent --show-error --proto '=https' --proto-redir '=https' --location --max-time 30 \
      https://apps.nextcloud.com/api/v1/apps.json > "$temporary/apps.json" \
      && python3 scripts/release.py store "$temporary/apps.json" > "$temporary/summary"; then
    cat "$temporary/summary"
    if [[ -n ${GITHUB_STEP_SUMMARY:-} ]]; then cat "$temporary/summary" >> "$GITHUB_STEP_SUMMARY"; fi
    exit 0
  fi
  if (( attempt < 20 )); then sleep 15; fi
done
printf 'Published release could not be verified in the store catalogue; rerun verification after inspecting the store\n' >&2
exit 1

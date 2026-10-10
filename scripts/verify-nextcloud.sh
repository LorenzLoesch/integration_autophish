#!/usr/bin/env bash
set -euo pipefail
cd /tmp/nextcloud
rm -rf custom_apps/integration_autophish
tar -xzf "/artifacts/$1" -C custom_apps
test -s custom_apps/integration_autophish/appinfo/signature.json
php occ app:enable integration_autophish
# The command also exits zero for a missing signature. Require the file AND empty output.
if ! result=$(php occ integrity:check-app integration_autophish); then
  printf '%s\n' "$result" >&2
  exit 1
fi
[[ -z $result ]] || { printf '%s\n' "$result" >&2; exit 1; }
printf 'Nextcloud installation and code integrity verified\n'

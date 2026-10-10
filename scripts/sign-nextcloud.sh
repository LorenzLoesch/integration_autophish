#!/usr/bin/env bash
# Executed in a disposable, non-root, network-isolated official Nextcloud container.
set -euo pipefail
mkdir /tmp/nextcloud
cp -a /usr/src/nextcloud/. /tmp/nextcloud/
cd /tmp/nextcloud
php occ maintenance:install --database sqlite --admin-user signing-check --admin-pass disposable-offline-only --data-dir /tmp/nextcloud/data
php occ config:system:set appstoreenabled --type boolean --value false
mkdir -p custom_apps/integration_autophish
cp -a /staged/. custom_apps/integration_autophish/
php occ integrity:sign-app --privateKey=/signing/key.pem --certificate=/signing/cert.crt --path=custom_apps/integration_autophish

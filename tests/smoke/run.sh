#!/usr/bin/env bash
set -euo pipefail
[[ $(id -u) == 33 ]]
mkdir /tmp/nextcloud
cp -a /usr/src/nextcloud/. /tmp/nextcloud/
cd /tmp/nextcloud
if [[ ${SMOKE_DATABASE:-sqlite} != sqlite ]]; then
  php occ maintenance:install --database "$SMOKE_DATABASE" --database-host "$SMOKE_DB_HOST" --database-name nextcloud --database-user smoke --database-pass mock-local-db-only --admin-user smoke-admin --admin-pass mock-local-only-password --data-dir /tmp/nextcloud/data
else
  php occ maintenance:install --database sqlite --admin-user smoke-admin --admin-pass mock-local-only-password --data-dir /tmp/nextcloud/data
fi
php occ config:system:set appstoreenabled --type boolean --value false
mkdir -p custom_apps
tar -xzf /release.tar.gz -C custom_apps
# Install an earlier app version, then upgrade to the actual release artifact.
php -r '$p="custom_apps/integration_autophish/appinfo/info.xml"; $x=simplexml_load_file($p); $x->version="0.9.0"; $x->asXML($p);'
php occ app:enable integration_autophish
sync_exit=0
php occ integration_autophish:sync || sync_exit=$?
[[ $sync_exit == 1 ]] # An unconfigured manual sync reports failure, without any network call.
php /probes/runtime.php
php occ background:cron
job_id=$(php /probes/job-id.php)
php occ background-job:execute "$job_id" --force-execute
php /probes/database.php
php occ config:app:set integration_autophish smoke_upgrade --value retained
tar -xzf /release.tar.gz -C custom_apps
php occ upgrade
[[ $(php occ config:app:get integration_autophish smoke_upgrade) == retained ]]
php /probes/upgrade-state.php
php occ integration_autophish:sync # Disabled fixture mappings cause no SaaS call.
php /probes/runtime.php
# Seed disposable sensitive/local state; offline cleanup must clear all of it.
php occ config:app:set integration_autophish smoke_key --value mock-encrypted-state
php occ config:app:set integration_autophish smoke_mapping --value mock-local-mapping
php /probes/hold-lock.php &
holder=$!
for attempt in {1..100}; do
  [[ -f /tmp/smoke-lock-ready ]] && break
  sleep 0.1
done
[[ -f /tmp/smoke-lock-ready ]]
php occ app:disable integration_autophish
touch /tmp/smoke-resume
wait "$holder"
php /probes/cleanup.php
php occ app:enable integration_autophish
php occ app:remove integration_autophish
php /probes/cleanup.php
printf 'Nextcloud %s install/update/cron/command/disable/removal smoke passed\n' "$NEXTCLOUD_MAJOR"

<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
require getcwd() . '/lib/base.php';
$config = \OCP\Server::get(\OCP\IConfig::class);
// Nextcloud may retain its own disabled/installed_version app markers.
foreach ($config->getAppKeys('integration_autophish') as $key) {
    if (!in_array($key, ['enabled', 'installed_version', 'types'], true)) {
        throw new RuntimeException('Local integration state survived cleanup: ' . $key);
    }
}
$jobs = \OCP\Server::get(\OCP\BackgroundJob\IJobList::class);
if (count($jobs->getJobs(\OCA\IntegrationAutoPhish\BackgroundJob\SyncJob::class, null, 0)) !== 0) {
    throw new RuntimeException('Sync job survived cleanup');
}
echo "Offline local-state/job cleanup verified\n";

<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
require getcwd() . '/lib/base.php';
$classes = [
    \OCA\IntegrationAutoPhish\Service\ConfigService::class,
    \OCA\IntegrationAutoPhish\Service\ApiClient::class,
    \OCA\IntegrationAutoPhish\Service\SyncRunner::class,
    \OCA\IntegrationAutoPhish\BackgroundJob\SyncJob::class,
    \OCA\IntegrationAutoPhish\Command\SyncCommand::class,
    \OCA\IntegrationAutoPhish\Controller\SettingsController::class,
    \OCA\IntegrationAutoPhish\Migration\Uninstall::class,
];
foreach ($classes as $class) {
    $service = \OCP\Server::get($class);
    if (!$service instanceof $class) { throw new RuntimeException('Service did not resolve'); }
}
$config = \OCP\Server::get(\OCA\IntegrationAutoPhish\Service\ConfigService::class);
$expected = $config->getConnection() === [] ? 'unconfigured' : 'success';
if (($config->getStatus()['state'] ?? '') !== $expected) { throw new RuntimeException('Unexpected runtime status'); }
$jobs = \OCP\Server::get(\OCP\BackgroundJob\IJobList::class);
if (count($jobs->getJobs(\OCA\IntegrationAutoPhish\BackgroundJob\SyncJob::class, null, 0)) !== 1) {
    throw new RuntimeException('Expected exactly one sync job');
}
echo "Runtime services and durable status resolved\n";

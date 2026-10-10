<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
require getcwd() . '/lib/base.php';
$config = \OCP\Server::get(\OCA\IntegrationAutoPhish\Service\ConfigService::class);
if (($config->getConnection()['apiKey'] ?? null) !== 'mock-smoke-key'
    || ($config->getConnection()['companyId'] ?? null) !== 'fixture-company'
    || ($config->getMappings()[0]['listId'] ?? null) !== 'fixture-list'
    || ($config->getMappings()[0]['enabled'] ?? null) !== false
    || ($config->getPendingCreation()['key'] ?? null) !== 'mock-pending-key'
    || ($config->getStatus()['lastSuccess'] ?? null) !== 12345) {
    throw new RuntimeException('Upgrade did not preserve configured encrypted integration state');
}
echo "Upgrade preserved encrypted connection, mappings, pending creation and status\n";

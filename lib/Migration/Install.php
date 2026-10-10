<?php
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);
namespace OCA\IntegrationAutoPhish\Migration;

use OCA\IntegrationAutoPhish\Service\ConfigService;
use OCP\IDBConnection;
use OCP\Migration\{IOutput, IRepairStep};

/** Seed only absent local rows; normal requests never upsert app configuration. */
class Install implements IRepairStep {
    public function __construct(private IDBConnection $db) {}
    public function getName(): string { return 'Initialize local AutoPhish integration state'; }
    public function run(IOutput $output): void {
        $state = ['revision' => bin2hex(random_bytes(16)), 'connection' => [], 'interval' => 3600, 'mappings' => [], 'status' => [], 'pendingCreation' => []];
        $this->db->insertIgnoreConflict('appconfig', ['appid' => ConfigService::APP_ID, 'configkey' => 'state', 'configvalue' => json_encode($state, JSON_THROW_ON_ERROR)]);
        $this->db->insertIgnoreConflict('appconfig', ['appid' => ConfigService::APP_ID, 'configkey' => 'sync_lock', 'configvalue' => '{}']);
        $output->info('Initialized local integration state without replacing existing settings.');
    }
}

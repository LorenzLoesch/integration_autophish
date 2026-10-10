<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
namespace OCA\IntegrationAutoPhish\Migration;

use OCA\IntegrationAutoPhish\BackgroundJob\SyncJob;
use OCP\BackgroundJob\IJobList;
use OCP\IConfig;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;

/** Nextcloud marks the app disabled before running this hook; cleanup must not wait on sync. */
class Uninstall implements IRepairStep {
    public function __construct(private IConfig $config, private IJobList $jobs) {}

    public function getName(): string {
        return 'Remove local AutoPhish integration configuration and jobs';
    }

    public function run(IOutput $output): void {
        // Configuration/status writes are conditional updates to existing active state.
        // Deleting it cancels an active run and cannot be undone by its stale callback.
        $this->config->deleteAppValues('integration_autophish');
        $this->jobs->remove(SyncJob::class);
        $output->info('Removed local integration state. Remote lists and API keys were not changed.');
    }
}

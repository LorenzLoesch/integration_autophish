<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
namespace OCA\IntegrationAutoPhish\Tests;
use OCA\IntegrationAutoPhish\BackgroundJob\SyncJob;
use OCA\IntegrationAutoPhish\Migration\Uninstall;
use OCP\BackgroundJob\IJobList;
use OCP\IConfig;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
class UninstallTest extends TestCase {
    public function testOfflineCleanupDoesNotDependOnNetworkOrSyncLock(): void {
        $config = $this->createMock(IConfig::class);
        $jobs = $this->createMock(IJobList::class);
        $jobs->expects($this->once())->method('remove')->with(SyncJob::class);
        $config->expects($this->once())->method('deleteAppValues')->with('integration_autophish');
        (new Uninstall($config, $jobs))->run($this->createMock(IOutput::class));
    }
}

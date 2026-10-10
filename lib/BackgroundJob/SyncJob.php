<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);
namespace OCA\IntegrationAutoPhish\BackgroundJob;

use OCA\IntegrationAutoPhish\Service\ConfigService;
use OCA\IntegrationAutoPhish\Service\SyncRunner;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;

class SyncJob extends TimedJob {
    public function __construct(ITimeFactory $time, private SyncRunner $runner, ConfigService $config) {
        parent::__construct($time);
        $this->setInterval(max(300, min(86400, $config->getInterval())));
        $this->setAllowParallelRuns(false);
    }

    protected function run($argument): void {
        $this->runner->run();
    }
}

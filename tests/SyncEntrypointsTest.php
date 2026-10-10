<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);
namespace OCA\IntegrationAutoPhish\Tests;

use OCA\IntegrationAutoPhish\BackgroundJob\SyncJob;
use OCA\IntegrationAutoPhish\Command\SyncCommand;
use OCA\IntegrationAutoPhish\Service\{ConfigService, SyncRunner};
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

class SyncEntrypointsTest extends TestCase {
    public function testOccCommandPrintsSafeCounters(): void {
        $runner = $this->createMock(SyncRunner::class);
        $runner->expects(self::once())->method('run')->willReturn(['state' => 'success', 'added' => 2, 'removed' => 1, 'skipped' => 0, 'duplicates' => 0]);
        $tester = new CommandTester(new SyncCommand($runner));
        self::assertSame(0, $tester->execute([]));
        self::assertStringContainsString('Added: 2; removed: 1;', $tester->getDisplay());
    }

    public function testOccCommandUnconfiguredHasClearNonzeroExit(): void {
        $runner = $this->createMock(SyncRunner::class);
        $runner->method('run')->willReturn(['state' => 'unconfigured', 'error' => 'Configure an AutoPhish connection before synchronization.']);
        $tester = new CommandTester(new SyncCommand($runner));
        self::assertSame(1, $tester->execute([]));
        self::assertStringContainsString('Configure an AutoPhish connection', $tester->getDisplay());
    }

    public function testTimedJobSharesRunnerAndReadsInterval(): void {
        $runner = $this->createMock(SyncRunner::class);
        $runner->expects(self::once())->method('run')->willReturn(['state' => 'success']);
        $config = $this->createMock(ConfigService::class);
        $config->method('getInterval')->willReturn(900);
        $job = new SyncJob($this->createMock(ITimeFactory::class), $runner, $config);
        self::assertSame(900, $job->getInterval());
        self::assertFalse($job->getAllowParallelRuns());
        $method = new \ReflectionMethod($job, 'run');
        $method->invoke($job, null);
    }
}

<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);
namespace OCA\IntegrationAutoPhish\Tests;

use OCA\IntegrationAutoPhish\Service\{ConfigService, SyncLock, SyncRunner, SyncService, ApiException};
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\TestCase;

class SyncRunnerTest extends TestCase {
    private function time(): ITimeFactory {
        $time = $this->createMock(ITimeFactory::class);
        $time->method('getTime')->willReturn(2000);
        return $time;
    }

    private function config(?callable $current = null, ?callable $statusWriter = null): ConfigService {
        $config = $this->createMock(ConfigService::class);
        $config->method('isActive')->willReturn(true);
        $config->method('isCurrent')->willReturnCallback($current ?? static fn () => true);
        $config->method('setSyncStatus')->willReturnCallback($statusWriter ?? static fn () => true);
        $config->method('getConnection')->willReturn(['baseUrl' => 'https://example.com', 'companyId' => 'company', 'apiKey' => 'secret']);
        $config->method('getStatus')->willReturn(['lastSuccess' => 1000]);
        $config->method('getRevision')->willReturn('revision');
        $config->method('getMappings')->willReturn([
            ['groupId' => 'a', 'listId' => 'a', 'enabled' => true],
            ['groupId' => 'b', 'listId' => 'b', 'enabled' => false],
        ]);
        return $config;
    }

    private function lock(): SyncLock {
        $lock = $this->createMock(SyncLock::class);
        $lock->method('acquire')->willReturn(true);
        $lock->method('isCurrent')->willReturn(true);
        $lock->method('getToken')->willReturn('owned-lock');
        $lock->expects(self::once())->method('release');
        return $lock;
    }

    public function testBusyRunDoesNotWriteStatusOrReadSecret(): void {
        $lock = $this->createMock(SyncLock::class);
        $lock->method('acquire')->willReturn(false);
        $lock->expects(self::never())->method('release');
        $config = $this->createMock(ConfigService::class);
        $config->method('isActive')->willReturn(true);
        $config->method('isCurrent')->willReturn(true);
        $config->method('setSyncStatus')->willReturn(true);
        $config->expects(self::never())->method('getConnection');
        $config->expects(self::never())->method('setSyncStatus');
        $result = (new SyncRunner($config, $this->createMock(SyncService::class), $lock, $this->time()))->run();
        self::assertSame('skipped', $result['state']);
    }

    public function testUnconfiguredInstallDoesNotCallApi(): void {
        $config = $this->createMock(ConfigService::class);
        $config->method('isActive')->willReturn(true);
        $config->method('isCurrent')->willReturn(true);
        $config->method('setSyncStatus')->willReturn(true);
        $config->method('getConnection')->willReturn([]);
        $config->method('getStatus')->willReturn([]);
        $config->expects(self::once())->method('setSyncStatus');
        $sync = $this->createMock(SyncService::class);
        $sync->expects(self::never())->method('sync');
        $result = (new SyncRunner($config, $sync, $this->lock(), $this->time()))->run();
        self::assertSame('unconfigured', $result['state']);
    }

    public function testSuccessfulRunSkipsDisabledMappingAndUpdatesLastSuccess(): void {
        $config = $this->config();
        $config->expects(self::exactly(3))->method('setSyncStatus');
        $sync = $this->createMock(SyncService::class);
        $sync->expects(self::once())->method('sync')->willReturnCallback(function ($connection, $mapping, $isCurrent) {
            self::assertSame('a', $mapping['groupId']);
            self::assertTrue($isCurrent());
            return ['added' => 2, 'removed' => 1, 'skipped' => 0, 'duplicates' => 0, 'removalBlocked' => false];
        });
        $result = (new SyncRunner($config, $sync, $this->lock(), $this->time()))->run();
        self::assertSame('success', $result['state']);
        self::assertSame(2000, $result['lastSuccess']);
        self::assertSame(2, $result['added']);
    }

    public function testFailurePreservesLastSuccessAndDoesNotExposeRawException(): void {
        $config = $this->config();
        $config->expects(self::exactly(2))->method('setSyncStatus');
        $sync = $this->createMock(SyncService::class);
        $sync->method('sync')->willThrowException(new \RuntimeException('secret-key private-user@example.com'));
        $result = (new SyncRunner($config, $sync, $this->lock(), $this->time()))->run();
        self::assertSame('failed', $result['state']);
        self::assertSame(1000, $result['lastSuccess']);
        self::assertStringNotContainsString('secret-key', json_encode($result));
        self::assertStringNotContainsString('private-user', json_encode($result));
    }

    public function testBlockedRemovalIsPartialAndPreservesLastSuccess(): void {
        $config = $this->config();
        $sync = $this->createMock(SyncService::class);
        $sync->method('sync')->willReturn(['added' => 1, 'removed' => 0, 'skipped' => 1, 'duplicates' => 0, 'removalBlocked' => true]);
        $result = (new SyncRunner($config, $sync, $this->lock(), $this->time()))->run();
        self::assertSame('partial', $result['state']);
        self::assertSame(1000, $result['lastSuccess']);
    }

    public function testLocalConfigurationErrorStillReleasesLock(): void {
        $config = $this->createMock(ConfigService::class);
        $config->method('isActive')->willReturn(true);
        $config->method('isCurrent')->willReturn(true);
        $config->method('setSyncStatus')->willReturn(true);
        $config->method('getStatus')->willThrowException(new \RuntimeException('private detail'));
        $result = (new SyncRunner($config, $this->createMock(SyncService::class), $this->lock(), $this->time()))->run();
        self::assertSame('failed', $result['state']);
        self::assertStringNotContainsString('private detail', json_encode($result));
    }
    public function testFailedRemovalRetainsProgressAndSafeApiCategory(): void {
        $config = $this->config();
        $snapshots = [];
        $config->method('setSyncStatus')->willReturnCallback(function (array $status) use (&$snapshots): bool { $snapshots[] = $status; return true; });
        $sync = $this->createMock(SyncService::class);
        $sync->method('sync')->willReturnCallback(function ($connection, $mapping, $current, $progress) {
            $progress(['added' => 2, 'removed' => 1, 'skipped' => 0, 'duplicates' => 0, 'removalBlocked' => false]);
            throw new ApiException('rate_limit', 'private response body', 429);
        });
        $result = (new SyncRunner($config, $sync, $this->lock(), $this->time()))->run();
        self::assertSame('running', $snapshots[0]['state']);
        self::assertSame(1, $snapshots[1]['removed']);
        self::assertSame('partial', $result['state']);
        self::assertSame(2, $result['added']);
        self::assertSame(1, $result['removed']);
        self::assertSame(1000, $result['lastSuccess']);
        self::assertSame('rate_limit', $result['errorCategory']);
        self::assertStringNotContainsString('private response body', json_encode($result));
    }

    public function testFinalResultDoesNotDoubleCountProgress(): void {
        $config = $this->config();
        $sync = $this->createMock(SyncService::class);
        $sync->method('sync')->willReturnCallback(function ($connection, $mapping, $current, $progress) {
            $result = ['added' => 2, 'removed' => 1, 'skipped' => 0, 'duplicates' => 0, 'removalBlocked' => false];
            $progress($result);
            return $result;
        });
        $result = (new SyncRunner($config, $sync, $this->lock(), $this->time()))->run();
        self::assertSame(2, $result['added']);
        self::assertSame(1, $result['removed']);
    }

    public function testUnavailableStatusStorageSurfacesSafeFailure(): void {
        $config = $this->config();
        $config->method('setSyncStatus')->willThrowException(new \RuntimeException('raw database detail'));
        $sync = $this->createMock(SyncService::class);
        $sync->expects(self::never())->method('sync');
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('AutoPhish synchronization could not persist its status.');
        (new SyncRunner($config, $sync, $this->lock(), $this->time()))->run();
    }
    public function testLifecycleCancellationAfterInflightMutationDoesNotRecreateStatus(): void {
        $active = true;
        $config = $this->config(static function () use (&$active): bool { return $active; });
        $config->expects(self::once())->method('setSyncStatus')->with(self::callback(fn ($status) => $status['state'] === 'running'), 'revision', 'owned-lock')->willReturn(true);
        $sync = $this->createMock(SyncService::class);
        $sync->method('sync')->willReturnCallback(function ($connection, $mapping, $current, $progress) use (&$active) {
            self::assertTrue($current());
            $active = false; // Lifecycle cleanup happens while the remote request is in flight.
            $progress(['added' => 1, 'removed' => 0, 'skipped' => 0, 'duplicates' => 0, 'removalBlocked' => false]);
            self::fail('Cancelled operation must not continue');
        });
        $result = (new SyncRunner($config, $sync, $this->lock(), $this->time()))->run();
        self::assertSame('cancelled', $result['state']);
        self::assertSame('configuration_changed', $result['errorCategory']);
        self::assertSame(1000, $result['lastSuccess']);
        self::assertSame(1, $result['added']);
    }

    public function testMonotonicDeadlineStopsProgressAndLaterMutations(): void {
        $snapshots = [];
        $config = $this->config(null, static function (array $value) use (&$snapshots): bool { $snapshots[] = $value; return true; });
        $config->expects(self::exactly(2))->method('setSyncStatus');
        $sync = $this->createMock(SyncService::class);
        $clock = 0.0;
        $sync->method('sync')->willReturnCallback(function ($connection, $mapping, $current, $progress) use (&$clock) {
            $clock = 301.0;
            self::assertFalse($current());
            $progress(['added' => 1, 'removed' => 0, 'skipped' => 0, 'duplicates' => 0, 'removalBlocked' => false]);
            self::fail('Expired operation must not continue');
        });
        $runner = $this->getMockBuilder(SyncRunner::class)->setConstructorArgs([$config, $sync, $this->lock(), $this->time()])->onlyMethods(['monotonicTime'])->getMock();
        $runner->method('monotonicTime')->willReturnCallback(static function () use (&$clock): float { return $clock; });
        $result = $runner->run();
        self::assertSame('cancelled', $result['state']);
        self::assertSame('deadline', $result['errorCategory']);
        self::assertSame(1000, $result['lastSuccess']);
        self::assertSame('running', $snapshots[0]['state']);
        self::assertSame('cancelled', $snapshots[1]['state']);
        self::assertSame('deadline', $snapshots[1]['errorCategory']);
    }

    public function testAtomicStatusCasRejectionDoesNotAttemptFallbackWrite(): void {
        $config = $this->config(null, static fn () => false);
        $config->expects(self::once())->method('setSyncStatus');
        $sync = $this->createMock(SyncService::class);
        $sync->expects(self::never())->method('sync');
        $result = (new SyncRunner($config, $sync, $this->lock(), $this->time()))->run();
        self::assertSame('cancelled', $result['state']);
    }
    public function testDeadlineBeforeInitialStatusPreservesPriorLastSuccess(): void {
        $config = $this->config();
        $config->expects(self::once())->method('setSyncStatus')->with(self::callback(fn ($value) => $value['lastSuccess'] === 1000 && $value['state'] === 'cancelled'), 'revision', 'owned-lock');
        $sync = $this->createMock(SyncService::class);
        $sync->expects(self::never())->method('sync');
        $runner = $this->getMockBuilder(SyncRunner::class)->setConstructorArgs([$config, $sync, $this->lock(), $this->time()])->onlyMethods(['monotonicTime'])->getMock();
        $runner->method('monotonicTime')->willReturnOnConsecutiveCalls(0.0, 301.0);
        $result = $runner->run();
        self::assertSame('deadline', $result['errorCategory']);
        self::assertSame(1000, $result['lastSuccess']);
    }
}

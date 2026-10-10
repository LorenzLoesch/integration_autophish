<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);
namespace OCA\IntegrationAutoPhish\Tests;

use OCA\IntegrationAutoPhish\Service\SyncLock;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\DB\IResult;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;

class SyncLockTest extends TestCase {
    private function lock(array &$rows, int &$now): SyncLock {
        $db = $this->createMock(IDBConnection::class);
        $db->method('executeQuery')->willReturnCallback(function () use (&$rows) {
            $result = $this->createMock(IResult::class);
            $result->method('fetch')->willReturn(isset($rows['sync_lock']) ? ['configvalue' => $rows['sync_lock']] : false);
            $result->method('closeCursor')->willReturn(true);
            return $result;
        });
        $db->method('executeStatement')->willReturnCallback(function ($sql, $params) use (&$rows): int {
            if (isset($params['owned'])) {
                if (($rows['sync_lock'] ?? null) !== $params['owned']) { return 0; }
                $rows['sync_lock'] = '{}';
                return 1;
            }
            if (($rows['enabled'] ?? '') !== 'yes' || !isset($rows['state']) || ($rows['sync_lock'] ?? null) !== $params['previous']) { return 0; }
            $rows['sync_lock'] = $params['value'];
            return 1;
        });
        $time = $this->createMock(ITimeFactory::class);
        $time->method('getTime')->willReturnCallback(static function () use (&$now) { return $now; });
        return new SyncLock($db, $time);
    }

    public function testExclusiveAcquireAndOwnerRelease(): void {
        $rows = ['sync_lock' => '{}', 'state' => '{}', 'enabled' => 'yes']; $now = 1000;
        $first = $this->lock($rows, $now); $second = $this->lock($rows, $now);
        self::assertTrue($first->acquire());
        self::assertFalse($first->acquire());
        self::assertFalse($second->acquire());
        self::assertTrue($first->isCurrent());
        self::assertNotSame('', $first->getToken());
        $first->release(); $first->release();
        self::assertSame('{}', $rows['sync_lock']);
        self::assertTrue($second->acquire());
    }

    public function testExpiredOwnerCannotReleaseSuccessor(): void {
        $rows = ['sync_lock' => '{}', 'state' => '{}', 'enabled' => 'yes']; $now = 1000;
        $first = $this->lock($rows, $now); $second = $this->lock($rows, $now);
        self::assertTrue($first->acquire());
        $old = $first->getToken();
        $now = 1600;
        self::assertFalse($first->isCurrent());
        self::assertTrue($second->acquire());
        $new = $second->getToken();
        self::assertNotSame($old, $new);
        $first->release();
        self::assertSame($new, $rows['sync_lock']);
        self::assertTrue($second->isCurrent());
    }

    public function testUninstallCannotBeRecreatedByOldReleaseOrAcquire(): void {
        $rows = ['sync_lock' => '{}', 'state' => '{}', 'enabled' => 'yes']; $now = 1000;
        $first = $this->lock($rows, $now);
        self::assertTrue($first->acquire());
        $rows = [];
        self::assertFalse($first->isCurrent());
        $first->release();
        self::assertSame([], $rows);
        self::assertFalse($first->acquire());
        self::assertSame([], $rows);
    }

    public function testDisabledAppCannotAcquireLease(): void {
        $rows = ['sync_lock' => '{}', 'state' => '{}', 'enabled' => 'no']; $now = 1000;
        self::assertFalse($this->lock($rows, $now)->acquire());
        self::assertSame('{}', $rows['sync_lock']);
    }

    public function testMissingStateCannotAcquireLease(): void {
        $rows = ['sync_lock' => '{}', 'enabled' => 'yes']; $now = 1000;
        self::assertFalse($this->lock($rows, $now)->acquire());
    }
}

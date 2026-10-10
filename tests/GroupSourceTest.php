<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);
namespace OCA\IntegrationAutoPhish\Tests;

use OCA\IntegrationAutoPhish\Service\GroupSource;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IUser;
use PHPUnit\Framework\TestCase;

class GroupSourceTest extends TestCase {
    private function user(?string $email, string $name = '', bool $enabled = true): IUser {
        $user = $this->createMock(IUser::class);
        $user->method('getEMailAddress')->willReturn($email);
        $user->method('getDisplayName')->willReturn($name);
        $user->method('isEnabled')->willReturn($enabled);
        return $user;
    }

    private function source(array $users): GroupSource {
        $group = $this->createMock(IGroup::class);
        $group->method('getUsers')->willReturn($users);
        $manager = $this->createMock(IGroupManager::class);
        $manager->method('get')->with('group')->willReturn($group);
        return new GroupSource($manager);
    }

    public function testNormalizeDedupeMissingAndDisabledUsers(): void {
        $result = $this->source([$this->user(' Alice@Example.com ', 'Alice Jones'),
            $this->user('alice@example.com', 'Duplicate'), $this->user(null),
            $this->user('invalid'), $this->user('disabled@example.com', '', false)])->read('group');
        self::assertSame(['alice@example.com' => ['email' => 'alice@example.com', 'firstName' => 'Alice Jones']], $result['members']);
        self::assertSame(2, $result['skipped']);
        self::assertSame(1, $result['duplicates']);
        self::assertTrue($result['removalBlocked']);
    }

    public function testCompleteEmptySourceCanPrune(): void {
        $result = $this->source([])->read('group');
        self::assertSame([], $result['members']);
        self::assertFalse($result['removalBlocked']);
    }

    public function testMissingGroupFailsRatherThanBecomingEmpty(): void {
        $manager = $this->createMock(IGroupManager::class);
        $manager->method('get')->willReturn(null);
        $this->expectException(\RuntimeException::class);
        (new GroupSource($manager))->read('missing');
    }

    public function testBackendReadFailurePropagates(): void {
        $group = $this->createMock(IGroup::class);
        $group->method('getUsers')->willThrowException(new \RuntimeException('backend failure'));
        $manager = $this->createMock(IGroupManager::class);
        $manager->method('get')->willReturn($group);
        $this->expectException(\RuntimeException::class);
        (new GroupSource($manager))->read('group');
    }

    public function testLongUnicodeDisplayNameIsClippedWithoutInventedLastName(): void {
        $result = $this->source([$this->user('user@example.com', str_repeat('é', 110))])->read('group');
        self::assertSame(str_repeat('é', 100), $result['members']['user@example.com']['firstName']);
        self::assertArrayNotHasKey('lastName', $result['members']['user@example.com']);
    }
}

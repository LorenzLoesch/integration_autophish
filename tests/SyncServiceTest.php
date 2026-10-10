<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);
namespace OCA\IntegrationAutoPhish\Tests;

use OCA\IntegrationAutoPhish\Service\{ApiClient, ApiException, GroupSource, SyncService};
use PHPUnit\Framework\TestCase;

class SyncServiceTest extends TestCase {
    private array $connection = ['baseUrl' => 'https://example.com', 'companyId' => 'company', 'apiKey' => 'secret'];
    private array $mapping = ['groupId' => 'group', 'listId' => 'list', 'enabled' => true];

    private function api(): ApiClient {
        $api = $this->createMock(ApiClient::class);
        $api->method('withRequestGuard')->willReturnCallback(static fn ($guard, $operation) => $operation());
        return $api;
    }

    private function source(array $emails, bool $blocked = false): GroupSource {
        $source = $this->createMock(GroupSource::class);
        $members = [];
        foreach ($emails as $email) {
            $members[$email] = ['email' => $email];
        }
        $source->method('read')->with('group')->willReturn(['members' => $members, 'skipped' => $blocked ? 1 : 0,
            'duplicates' => 0, 'removalBlocked' => $blocked]);
        return $source;
    }

    private function member(string $email, string $id = 'member'): array {
        return ['id' => $id, 'email' => $email, 'targetListId' => 'list'];
    }

    public function testRepeatKeepsExistingNamesAndMakesNoWrites(): void {
        $api = $this->api();
        $api->method('listMembers')->willReturn([$this->member('same@example.com') + ['firstName' => 'Keep']]);
        $api->expects(self::never())->method('addMembers');
        $api->expects(self::never())->method('removeMember');
        $result = (new SyncService($this->source(['same@example.com']), $api))->sync($this->connection, $this->mapping, fn () => true);
        self::assertSame(0, $result['added']);
    }

    public function testOver200UsersAreBatchedAndVerified(): void {
        $emails = [];
        $destination = [];
        for ($i = 0; $i < 450; $i++) {
            $emails[] = "user$i@example.com";
            $destination[] = $this->member("user$i@example.com", "id$i");
        }
        $api = $this->api();
        $api->method('listMembers')->willReturnOnConsecutiveCalls([], $destination);
        $sizes = [];
        $api->expects(self::exactly(3))->method('addMembers')->willReturnCallback(function ($connection, $list, $members) use (&$sizes) {
            $sizes[] = count($members);
            self::assertLessThan(64000, strlen(json_encode(['targets' => $members])));
            return count($members);
        });
        $result = (new SyncService($this->source($emails), $api))->sync($this->connection, $this->mapping, fn () => true);
        self::assertSame([200, 200, 50], $sizes);
        self::assertSame(450, $result['added']);
    }

    public function testFailedAdditionBlocksAllRemovals(): void {
        $api = $this->api();
        $api->method('listMembers')->willReturn([$this->member('old@example.com')]);
        $api->method('addMembers')->willThrowException(new \RuntimeException('failure'));
        $api->expects(self::never())->method('removeMember');
        $this->expectException(\RuntimeException::class);
        (new SyncService($this->source(['new@example.com']), $api))->sync($this->connection, $this->mapping, fn () => true);
    }

    public function testUnverifiedSuccessfulAdditionBlocksRemoval(): void {
        $api = $this->api();
        $api->method('listMembers')->willReturn([$this->member('old@example.com')]);
        $api->method('addMembers')->willReturn(0);
        $api->expects(self::never())->method('removeMember');
        $this->expectException(\RuntimeException::class);
        (new SyncService($this->source(['new@example.com']), $api))->sync($this->connection, $this->mapping, fn () => true);
    }

    public function testInvalidOrDuplicateDestinationFailsBeforeMutation(): void {
        $api = $this->api();
        $api->method('listMembers')->willReturn([$this->member('old@example.com', '1'), $this->member('OLD@example.com', '2')]);
        $api->expects(self::never())->method('addMembers');
        $api->expects(self::never())->method('removeMember');
        $this->expectException(\RuntimeException::class);
        (new SyncService($this->source([]), $api))->sync($this->connection, $this->mapping, fn () => true);
    }

    public function testStaleRevisionBlocksAddition(): void {
        $api = $this->api();
        $api->method('listMembers')->willReturn([]);
        $api->expects(self::never())->method('addMembers');
        $this->expectException(\RuntimeException::class);
        (new SyncService($this->source(['a@example.com']), $api))->sync($this->connection, $this->mapping, fn () => false);
    }
    public function testUnicodePayloadSizeSplitsBelowApiLimit(): void {
        $source = $this->createMock(GroupSource::class);
        $members = $destination = [];
        for ($i = 0; $i < 200; $i++) {
            $email = "user$i@example.com";
            $members[$email] = ['email' => $email, 'firstName' => str_repeat('é', 100)];
            $destination[] = $this->member($email, "id$i");
        }
        $source->method('read')->willReturn(['members' => $members, 'skipped' => 0, 'duplicates' => 0, 'removalBlocked' => false]);
        $api = $this->api();
        $api->method('listMembers')->willReturnOnConsecutiveCalls([], $destination);
        $batches = 0;
        $api->method('addMembers')->willReturnCallback(function ($connection, $list, $batch) use (&$batches) {
            $batches++;
            self::assertLessThan(63000, strlen(json_encode(['targets' => $batch])));
            self::assertLessThanOrEqual(200, count($batch));
            return count($batch);
        });
        $result = (new SyncService($source, $api))->sync($this->connection, $this->mapping, fn () => true);
        self::assertGreaterThan(1, $batches);
        self::assertSame(200, $result['added']);
    }

    public function testSourceFailureMakesNoDestinationCalls(): void {
        $source = $this->createMock(GroupSource::class);
        $source->method('read')->willThrowException(new \RuntimeException('Group backend failure'));
        $api = $this->api();
        $api->expects(self::never())->method('getList');
        $api->expects(self::never())->method('listMembers');
        $api->expects(self::never())->method('addMembers');
        $api->expects(self::never())->method('removeMember');
        $this->expectException(\RuntimeException::class);
        (new SyncService($source, $api))->sync($this->connection, $this->mapping, fn () => true);
    }

    public function testDestinationPaginationFailureMakesNoWrites(): void {
        $api = $this->api();
        $api->method('listMembers')->willThrowException(new ApiException('invalid_response', 'Incomplete pagination'));
        $api->expects(self::never())->method('addMembers');
        $api->expects(self::never())->method('removeMember');
        $this->expectException(ApiException::class);
        (new SyncService($this->source([]), $api))->sync($this->connection, $this->mapping, fn () => true);
    }
}

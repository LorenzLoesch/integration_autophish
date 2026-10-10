<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);
namespace OCA\IntegrationAutoPhish\Service;

use RuntimeException;

class SyncService {
    public function __construct(private GroupSource $source, private ApiClient $api) {}

    /** Add first, verify additions, then remove only memberships returned by the mapped list. */
    public function sync(array $connection, array $mapping, callable $isCurrent, ?callable $onProgress = null): array {
        return $this->api->withRequestGuard($isCurrent, fn (): array => $this->reconcile($connection, $mapping, $isCurrent, $onProgress));
    }

    private function reconcile(array $connection, array $mapping, callable $isCurrent, ?callable $onProgress): array {
        $listId = $mapping['listId'];
        $this->assertCurrent($isCurrent);
        $source = $this->source->read($mapping['groupId']);
        $this->assertCurrent($isCurrent);
        $this->api->getList($connection, $listId);
        $destination = $this->readDestination($connection, $listId);
        $missing = array_diff_key($source['members'], $destination);
        $result = ['added' => 0, 'removed' => 0, 'skipped' => $source['skipped'],
            'duplicates' => $source['duplicates'], 'removalBlocked' => $source['removalBlocked']];
        if ($onProgress !== null) { $onProgress($result); }
        foreach ($this->batches(array_values($missing)) as $batch) {
            $this->assertCurrent($isCurrent);
            $key = bin2hex(random_bytes(16));
            $result['added'] += $this->api->addMembers($connection, $listId, $batch, $key);
            if ($onProgress !== null) { $onProgress($result); }
        }
        if ($missing !== []) {
            // A successful HTTP response is insufficient if a batch silently omitted targets.
            $destination = $this->readDestination($connection, $listId);
            if (array_diff_key($source['members'], $destination) !== []) {
                throw new RuntimeException('Some required additions could not be verified; removals were blocked.');
            }
        }
        if (!$result['removalBlocked']) {
            foreach (array_diff_key($destination, $source['members']) as $member) {
                $this->assertCurrent($isCurrent);
                $key = bin2hex(random_bytes(16));
                try {
                    $this->api->removeMember($connection, $listId, $member['id'], $key);
                } catch (ApiException $e) {
                    if ($e->httpStatus !== 404) {
                        throw $e;
                    }
                }
                $result['removed']++;
                if ($onProgress !== null) { $onProgress($result); }
            }
        }
        return $result;
    }

    private function assertCurrent(callable $isCurrent): void {
        if (!$isCurrent()) {
            throw new RuntimeException('Configuration changed; synchronization stopped.');
        }
    }

    /** Reject malformed or ambiguous destination data before any mutation. */
    private function readDestination(array $connection, string $listId): array {
        $members = [];
        $ids = [];
        foreach ($this->api->listMembers($connection, $listId) as $member) {
            $email = GroupSource::normalizeEmail($member['email'] ?? null);
            $id = $member['id'] ?? null;
            if ($email === null || !is_string($id) || $id === '' || isset($ids[$id]) || isset($members[$email])) {
                throw new RuntimeException('The destination list returned invalid or ambiguous memberships.');
            }
            if (isset($member['targetListId']) && $member['targetListId'] !== $listId) {
                throw new RuntimeException('The destination list returned an unexpected membership.');
            }
            $ids[$id] = true;
            $members[$email] = ['id' => $id, 'email' => $email];
        }
        return $members;
    }

    /** Leave room below the API's 64 KiB limit for the targets envelope. */
    private function batches(array $members): array {
        $batches = [];
        $batch = [];
        foreach ($members as $member) {
            $candidate = [...$batch, $member];
            if (count($candidate) > 200 || strlen(json_encode(['targets' => $candidate], JSON_THROW_ON_ERROR)) >= 63000) {
                $batches[] = $batch;
                $batch = [$member];
            } else {
                $batch = $candidate;
            }
        }
        if ($batch !== []) {
            $batches[] = $batch;
        }
        return $batches;
    }
}

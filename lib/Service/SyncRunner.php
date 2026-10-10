<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);
namespace OCA\IntegrationAutoPhish\Service;

use OCP\AppFramework\Utility\ITimeFactory;
use RuntimeException;
use Throwable;

class SyncRunner {
    public function __construct(private ConfigService $config, private SyncService $sync,
        private SyncLock $lock, private ITimeFactory $time) {}

    protected function monotonicTime(): float {
        return hrtime(true) / 1000000000;
    }

    public function run(): array {
        if (!$this->lock->acquire()) {
            return ['state' => 'skipped', 'error' => 'Synchronization or configuration update is already running.'];
        }
        $deadline = $this->monotonicTime() + 300;
        $status = null;
        $revision = null;
        $reason = null;
        $guard = function () use (&$reason, &$revision, $deadline): bool {
            if (!$this->lock->isCurrent() || ($revision === null ? !$this->config->isActive() : !$this->config->isCurrent($revision))) {
                $reason = 'configuration_changed';
                return false;
            }
            if ($this->monotonicTime() >= $deadline) {
                $reason = 'deadline';
                return false;
            }
            return true;
        };
        $write = function (array $value) use ($guard, &$revision, &$reason): void {
            if (!$guard() || !$this->config->setSyncStatus($value, $revision, $this->lock->getToken())) {
                $reason ??= 'configuration_changed';
                throw new RuntimeException('Synchronization was cancelled.');
            }
        };
        try {
            $revision = $this->config->getRevision();
            if (!$guard()) {
                return $this->cancelled($status, $reason, $revision);
            }
            $previous = $this->config->getStatus();
            $status = ['state' => 'running', 'lastRun' => $this->time->getTime(),
                'lastSuccess' => $previous['lastSuccess'] ?? null, 'added' => 0, 'removed' => 0,
                'skipped' => 0, 'duplicates' => 0, 'removalBlocked' => false, 'error' => null, 'errorCategory' => null];
            $connection = $this->config->getConnection();
            if (empty($connection['apiKey']) || empty($connection['baseUrl']) || empty($connection['companyId'])) {
                $status['state'] = 'unconfigured';
                $status['error'] = 'Configure an AutoPhish connection before synchronization.';
                $status['errorCategory'] = 'configuration';
                $write($status);
                return $status;
            }
            $write($status);
            $failed = $completed = 0;
            foreach ($this->config->getMappings() as $mapping) {
                if (!$guard()) {
                    return $this->cancelled($status, $reason, $revision);
                }
                if (!$mapping['enabled']) {
                    continue;
                }
                $baseline = $status;
                $progressWriteFailed = false;
                $progress = function (array $result) use (&$status, $baseline, &$progressWriteFailed, $write): void {
                    foreach (['added', 'removed', 'skipped', 'duplicates'] as $counter) {
                        $status[$counter] = $baseline[$counter] + $result[$counter];
                    }
                    $status['removalBlocked'] = $baseline['removalBlocked'] || $result['removalBlocked'];
                    try {
                        $write($status);
                    } catch (Throwable) {
                        $progressWriteFailed = true;
                        throw new RuntimeException('Synchronization progress could not be persisted.');
                    }
                };
                try {
                    $result = $this->sync->sync($connection, $mapping, $guard, $progress);
                    $progress($result);
                    $completed++;
                } catch (Throwable $e) {
                    if ($reason !== null || !$guard()) {
                        return $this->cancelled($status, $reason, $revision);
                    }
                    if ($progressWriteFailed) {
                        throw $e;
                    }
                    $failed++;
                    [$status['errorCategory'], $status['error']] = $this->safeError($e);
                }
            }
            if ($failed > 0 || $status['removalBlocked']) {
                $status['state'] = $completed > 0 || $status['added'] > 0 || $status['removed'] > 0 ? 'partial' : 'failed';
                if ($failed === 0) {
                    $status['errorCategory'] = 'source_email';
                    $status['error'] = 'Missing or duplicate source emails prevented removals. Correct the group email addresses and retry.';
                }
            } else {
                $status['state'] = 'success';
            }
            $final = $status;
            if ($final['state'] === 'success') {
                $final['lastSuccess'] = $final['lastRun'];
            }
            $write($final);
            return $final;
        } catch (Throwable) {
            if ($reason !== null) {
                return $this->cancelled($status, $reason, $revision);
            }
            $status ??= ['lastSuccess' => null];
            $status['state'] = 'failed';
            $status['errorCategory'] = 'local_configuration';
            $status['error'] = 'Synchronization could not read or save local configuration.';
            try {
                $write($status);
            } catch (Throwable) {
                if ($reason !== null) {
                    return $this->cancelled($status, $reason, $revision);
                }
                throw new RuntimeException('AutoPhish synchronization could not persist its status.');
            }
            return $status;
        } finally {
            $this->lock->release();
        }
    }

    private function cancelled(?array $status, ?string $reason, ?string $revision): array {
        if ($status === null && $reason === 'deadline') {
            try {
                $status = ['lastSuccess' => $this->config->getStatus()['lastSuccess'] ?? null];
            } catch (Throwable) {
                throw new RuntimeException('AutoPhish synchronization could not read its previous status.');
            }
        }
        $status ??= [];
        $status['state'] = 'cancelled';
        $status['errorCategory'] = $reason ?? 'configuration_changed';
        $status['error'] = $reason === 'deadline'
            ? 'Synchronization reached its five-minute operation limit. The next scheduled run will continue reconciliation.'
            : 'Synchronization stopped because the app or its configuration changed.';
        // A deadline ends outbound work, but its bounded local result can still be recorded
        // while the original revision and lease remain current. Lifecycle cancellation never writes.
        if ($reason === 'deadline' && $revision !== null) {
            try {
                if ($this->lock->isCurrent() && $this->config->isCurrent($revision)) {
                    $this->config->setSyncStatus($status, $revision, $this->lock->getToken());
                }
            } catch (Throwable) {
                throw new RuntimeException('AutoPhish synchronization could not persist its cancellation status.');
            }
        }
        return $status;
    }

    private function safeError(Throwable $error): array {
        $messages = [
            'authentication' => 'AutoPhish rejected the API key. Check the configured credentials.',
            'permission' => 'AutoPhish denied access. Check the company and API key permissions.',
            'not_found' => 'The configured AutoPhish list is unavailable. Check the mapping.',
            'validation' => 'AutoPhish rejected recipients. Check the email addresses.',
            'domain' => 'AutoPhish rejected email domains. Verify those domains in AutoPhish before retrying.',
            'conflict' => 'AutoPhish reported a conflicting operation. Retry synchronization or check the mapping.',
            'rate_limit' => 'AutoPhish temporarily limited requests. Retry synchronization later.',
            'network' => 'AutoPhish could not be reached. Check the HTTPS connection.',
            'service' => 'AutoPhish encountered a temporary server error. Retry later.',
            'invalid_response' => 'AutoPhish returned incomplete or invalid data. Removals were blocked.',
            'configuration' => 'The AutoPhish connection configuration is invalid.',
            'managed_list' => 'The mapped list is managed by another directory integration.',
            'protected_list' => 'The mapped list is masked or pseudonymous and cannot be synchronized.',
        ];
        if ($error instanceof ApiException && isset($messages[$error->category])) {
            return [$error->category, $messages[$error->category]];
        }
        return ['sync_failed', 'A mapping could not complete. Check its group, email addresses and destination list.'];
    }
}

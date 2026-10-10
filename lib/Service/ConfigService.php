<?php
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\IntegrationAutoPhish\Service;

use OCP\IDBConnection;
use OCP\Security\ICrypto;

/** Call mutators only while holding the shared SyncLock. */
class ConfigService {
    public const APP_ID = 'integration_autophish';
    public function __construct(private IDBConnection $db, private ICrypto $crypto, private SyncLock $lock) {}

    /** Fresh reads deliberately bypass Nextcloud's process-local appconfig cache. */
    private function load(): array {
        $result = $this->db->executeQuery(
            "SELECT configkey, configvalue FROM *PREFIX*appconfig WHERE appid = :app AND configkey IN ('state', 'enabled')",
            ['app' => self::APP_ID],
        );
        $rows = $result->fetchAll();
        $result->closeCursor();
        $values = [];
        foreach ($rows as $row) { $values[$row['configkey']] = $row['configvalue']; }
        $raw = $values['state'] ?? null;
        return ['raw' => $raw, 'state' => $raw === null ? [] : json_decode($raw, true, 512, JSON_THROW_ON_ERROR), 'active' => ($values['enabled'] ?? '') === 'yes'];
    }
    private function read(string $key, mixed $default = []): mixed { return $this->load()['state'][$key] ?? $default; }

    /** Updates an existing row only: disable/removal can never be undone by stale workers. */
    private function compareAndSwap(array $previous, array $state, ?string $expectedLock = null): bool {
        if (!$previous['active'] || $previous['raw'] === null || !$this->lock->isCurrent()) { return false; }
        $expectedLock ??= $this->lock->getToken();
        if ($expectedLock === '' || $expectedLock !== $this->lock->getToken()) { return false; }
        $raw = json_encode($state, JSON_THROW_ON_ERROR);
        // The derived table's LIMIT prevents MySQL from merging a read of the update target.
        $changed = $this->db->executeStatement(
            "UPDATE *PREFIX*appconfig SET configvalue = :value WHERE appid = :app AND configkey = 'state' AND configvalue = :previous " .
            "AND EXISTS (SELECT 1 FROM (SELECT configvalue FROM *PREFIX*appconfig WHERE appid = :enabledApp AND configkey = 'enabled' LIMIT 1) active_app WHERE configvalue = 'yes') " .
            "AND EXISTS (SELECT 1 FROM (SELECT configvalue FROM *PREFIX*appconfig WHERE appid = :lockApp AND configkey = 'sync_lock' LIMIT 1) owned_lock WHERE configvalue = :expectedLock)",
            ['value' => $raw, 'app' => self::APP_ID, 'previous' => $previous['raw'], 'enabledApp' => self::APP_ID, 'lockApp' => self::APP_ID, 'expectedLock' => $expectedLock],
        );
        return $changed === 1;
    }
    private function mutate(\Closure $mutation): void {
        $previous = $this->load();
        if (!$previous['active'] || $previous['raw'] === null) { throw new \RuntimeException('The integration is disabled or its configuration was removed.'); }
        $state = $mutation($previous['state']);
        if (!$this->compareAndSwap($previous, $state)) { throw new \RuntimeException('The integration configuration changed during this operation.'); }
    }
    private function write(string $key, mixed $value): void {
        $this->mutate(static function (array $state) use ($key, $value): array { $state[$key] = $value; return $state; });
    }
    public function isActive(): bool { $loaded = $this->load(); return $loaded['active'] && $loaded['raw'] !== null; }
    public function isCurrent(string $revision): bool {
        $loaded = $this->load();
        return $revision !== '' && $loaded['active'] && ($loaded['state']['revision'] ?? '') === $revision;
    }
    public function getConnection(): array {
        $connection = $this->read('connection');
        if ($connection === []) { return []; }
        $connection['apiKey'] = $this->crypto->decrypt($connection['apiKey']);
        return $connection;
    }
    public function getPublicConnection(): array {
        $connection = $this->read('connection');
        return ['baseUrl' => $connection['baseUrl'] ?? '', 'companyId' => $connection['companyId'] ?? '', 'configured' => isset($connection['apiKey'])];
    }
    public function saveConnection(string $baseUrl, string $companyId, string $apiKey, int $interval): void {
        $baseUrl = rtrim(trim($baseUrl), '/');
        $url = parse_url($baseUrl);
        if (!$url || filter_var($baseUrl, FILTER_VALIDATE_URL) === false || preg_match('/[\x00-\x20\x7f\\\\]/', $baseUrl) || ($url['scheme'] ?? '') !== 'https' || empty($url['host']) || isset($url['user']) || isset($url['pass']) || isset($url['query']) || isset($url['fragment']) || !in_array($url['path'] ?? '', ['', '/'], true)) {
            throw new \InvalidArgumentException('Use an HTTPS server origin without a path, credentials, query, or fragment.');
        }
        $companyId = trim($companyId);
        if ($companyId === '' || strlen($companyId) > 200 || preg_match('/[\x00-\x20\x7f]/', $companyId)) { throw new \InvalidArgumentException('Enter a valid company ID.'); }
        if ($interval < 300 || $interval > 86400) { throw new \InvalidArgumentException('Interval must be between 300 and 86400 seconds.'); }
        if (strlen($apiKey) > 4096 || preg_match('/[\x00-\x20\x7f]/', $apiKey)) { throw new \InvalidArgumentException('Enter a valid API key.'); }
        $this->mutate(function (array $state) use ($baseUrl, $companyId, $apiKey, $interval): array {
            $previous = $state['connection'] ?? [];
            if ($apiKey === '' && !isset($previous['apiKey'])) { throw new \InvalidArgumentException('Enter an API key.'); }
            if ($apiKey === '' && ($previous['baseUrl'] ?? '') !== $baseUrl) { throw new \InvalidArgumentException('Enter a new API key when changing the server origin.'); }
            $encrypted = $apiKey !== '' ? $this->crypto->encrypt($apiKey) : $previous['apiKey'];
            if (($previous['baseUrl'] ?? '') !== $baseUrl || ($previous['companyId'] ?? '') !== $companyId) {
                $state['mappings'] = [];
                $state['status'] = [];
                $state['pendingCreation'] = [];
            }
            $state['connection'] = ['baseUrl' => $baseUrl, 'companyId' => $companyId, 'apiKey' => $encrypted];
            $state['interval'] = $interval;
            $state['revision'] = bin2hex(random_bytes(16));
            return $state;
        });
    }
    public function getMappings(): array { return $this->read('mappings'); }
    public function saveMappings(array $mappings): void {
        $this->mutate(static function (array $state) use ($mappings): array { $state['mappings'] = array_values($mappings); $state['revision'] = bin2hex(random_bytes(16)); return $state; });
    }
    public function getInterval(): int { return (int)$this->read('interval', 3600); }
    public function getRevision(): string { return (string)$this->read('revision', ''); }
    public function getStatus(): array { return $this->read('status'); }
    public function setStatus(array $status): void { $this->write('status', $status); }
    public function setSyncStatus(array $status, string $expectedRevision, string $expectedLock): bool {
        $previous = $this->load();
        if ($expectedRevision === '' || ($previous['state']['revision'] ?? '') !== $expectedRevision) { return false; }
        $state = $previous['state']; $state['status'] = $status;
        return $this->compareAndSwap($previous, $state, $expectedLock);
    }
    public function getPendingCreation(): array { return $this->read('pendingCreation'); }
    public function setPendingCreation(array $pending): void { $this->write('pendingCreation', $pending); }
}

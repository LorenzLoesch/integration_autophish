<?php
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);
namespace OCA\IntegrationAutoPhish\Tests;
use OCA\IntegrationAutoPhish\Service\{ConfigService, SyncLock};
use OCP\IDBConnection;
use OCP\DB\IResult;
use OCP\Security\ICrypto;
use PHPUnit\Framework\TestCase;
class ConfigServiceTest extends TestCase {
    private FakeConfigDatabase $database;
    private function service(array &$stored): ConfigService {
        $database = new FakeConfigDatabase($this->createMock(IDBConnection::class), fn () => $this->createMock(IResult::class));
        $this->database = $database;
        $stored = $database->rows; $database->rows = &$stored;
        $crypto = $this->createMock(ICrypto::class);
        $crypto->method('encrypt')->willReturnCallback(fn ($plain) => 'cipher:' . base64_encode($plain));
        $crypto->method('decrypt')->willReturnCallback(fn ($cipher) => base64_decode(substr($cipher, 7)));
        $lock = $this->createMock(SyncLock::class); $lock->method('isCurrent')->willReturn(true); $lock->method('getToken')->willReturn('{}');
        return new ConfigService($database->db, $crypto, $lock);
    }
    public function testKeyEncryptedAndNeverReturnedByPublicState(): void {
        $stored = []; $service = $this->service($stored);
        $service->saveConnection('https://example.com', 'company', 'secret-key', 300);
        self::assertStringNotContainsString('secret-key', $stored['state']);
        self::assertSame('secret-key', $service->getConnection()['apiKey']);
        self::assertSame(['baseUrl' => 'https://example.com', 'companyId' => 'company', 'configured' => true], $service->getPublicConnection());
    }
    public function testDestinationChangeClearsMappingsButKeyRotationPreservesThem(): void {
        $stored = []; $service = $this->service($stored);
        $service->saveConnection('https://example.com', 'company', 'key-one', 300);
        $service->saveMappings([['groupId' => 'g', 'listId' => 'l', 'enabled' => true]]);
        $service->setStatus(['state' => 'success']);
        $service->setPendingCreation(['key' => 'pending']);
        $revision = $service->getRevision();
        $service->saveConnection('https://example.com', 'company', 'key-two', 600);
        self::assertCount(1, $service->getMappings()); self::assertNotSame($revision, $service->getRevision());
        $service->saveConnection('https://example.com', 'other', '', 600);
        self::assertSame([], $service->getMappings()); self::assertSame([], $service->getStatus()); self::assertSame([], $service->getPendingCreation());
        self::assertSame('key-two', $service->getConnection()['apiKey']);
    }
    public function testChangingOriginRequiresAnExplicitNewKey(): void {
        $stored = []; $service = $this->service($stored);
        $service->saveConnection('https://example.com', 'company', 'key-one', 300);
        $before = $stored;
        try { $service->saveConnection('https://other.example', 'company', '', 300); self::fail('Origin changed without explicit key'); } catch (\InvalidArgumentException) {}
        self::assertSame($before, $stored);
    }
    public function testInvalidKeyAndIntervalDoNotWrite(): void {
        $stored = []; $service = $this->service($stored); $before = $stored;
        foreach ([['key with space', 300], ['key', 299], ['key', 86401], ["key\tvalue", 300]] as [$key, $interval]) {
            try { $service->saveConnection('https://example.com', 'company', $key, $interval); self::fail('Invalid configuration accepted'); } catch (\InvalidArgumentException) {}
            self::assertSame($before, $stored);
        }
    }
    public function testRevisionAndEnabledAreFreshAcrossExternalLifecycleChanges(): void {
        $stored = []; $service = $this->service($stored);
        $revision = $service->getRevision();
        self::assertTrue($service->isCurrent($revision));
        $state = $this->database->state(); $state['revision'] = 'externally-changed';
        $this->database->rows['state'] = json_encode($state, JSON_THROW_ON_ERROR);
        self::assertSame('externally-changed', $service->getRevision());
        self::assertFalse($service->isCurrent($revision));
        $this->database->rows['enabled'] = 'no';
        self::assertFalse($service->isActive());
        self::assertFalse($service->isCurrent('externally-changed'));
    }
    public function testStatusWriteCannotRecreateStateDeletedBetweenReadAndUpdate(): void {
        $stored = []; $service = $this->service($stored);
        $revision = $service->getRevision();
        $this->database->beforeUpdate = function (): void { unset($this->database->rows['state']); };
        self::assertFalse($service->setSyncStatus(['state' => 'success'], $revision, '{}'));
        self::assertArrayNotHasKey('state', $this->database->rows);
    }
    public function testStatusWriteCannotOverwriteSuccessorLeaseOrNewRevision(): void {
        $stored = []; $service = $this->service($stored);
        $oldRevision = $service->getRevision();
        $service->saveMappings([]);
        self::assertFalse($service->setSyncStatus(['state' => 'success'], $oldRevision, '{}'));
        $current = $service->getRevision();
        $this->database->beforeUpdate = function (): void { $this->database->rows['sync_lock'] = '{"owner":"successor"}'; };
        self::assertFalse($service->setSyncStatus(['state' => 'success'], $current, '{}'));
        self::assertSame([], $service->getStatus());
    }
    public function testStaleSettingsOperationCannotWriteAfterDisable(): void {
        $stored = []; $service = $this->service($stored);
        $before = $this->database->rows['state'];
        $this->database->beforeUpdate = function (): void { $this->database->rows['enabled'] = 'no'; };
        try { $service->saveMappings([['groupId' => 'old', 'listId' => 'old', 'enabled' => true]]); self::fail('Disabled integration accepted stale mapping'); } catch (\RuntimeException) {}
        self::assertSame($before, $this->database->rows['state']);
    }
    public function testInvalidConnectionDoesNotWrite(): void {
        $stored = []; $service = $this->service($stored); $before = $stored;
        foreach (['http://example.com', 'https://a:b@example.com', 'https://example.com/path', 'https://example.com?q=1', 'https://example.com:99999', 'https://invalid host'] as $url) {
            try { $service->saveConnection($url, 'company', 'key', 300); self::fail('Invalid URL accepted'); } catch (\InvalidArgumentException) {}
            self::assertSame($before, $stored);
        }
    }
}

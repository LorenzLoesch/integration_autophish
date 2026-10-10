<?php
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);
namespace OCA\IntegrationAutoPhish\Tests;
use OCA\IntegrationAutoPhish\Controller\SettingsController;
use OCA\IntegrationAutoPhish\Service\{ApiClient, ApiException, ConfigService, SyncLock};
use OCP\{IRequest, IGroupManager, IUserSession, IUser, IGroup};
use PHPUnit\Framework\TestCase;
use OCP\Http\Client\{IClient, IClientService, IResponse};

class TimedSettingsController extends SettingsController {
    public float $now = 0;
    protected function monotonicTime(): float { return $this->now; }
}
class SettingsControllerTest extends TestCase {
    private function controller(bool $admin = true, bool $lockAvailable = true): array {
        $config = $this->createMock(ConfigService::class);
        $api = $this->createMock(ApiClient::class);
        $api->method('withRequestGuard')->willReturnCallback(fn ($guard, $operation) => $operation());
        $config->method('getRevision')->willReturn('revision'); $config->method('isCurrent')->willReturn(true);
        $lock = $this->createMock(SyncLock::class); $lock->method('acquire')->willReturn($lockAvailable); $lock->method('isCurrent')->willReturn(true);
        $groups = $this->createMock(IGroupManager::class); $groups->method('isAdmin')->willReturn($admin);
        $user = $this->createMock(IUser::class); $user->method('getUID')->willReturn('admin');
        $session = $this->createMock(IUserSession::class); $session->method('getUser')->willReturn($user);
        return [new SettingsController('integration_autophish', $this->createMock(IRequest::class), $config, $api, $lock, $groups, $session), $config, $api, $lock, $groups];
    }
    private function timedController(): array {
        $config = $this->createMock(ConfigService::class);
        $config->method('getRevision')->willReturn('revision'); $config->method('isCurrent')->willReturn(true);
        $config->method('getConnection')->willReturn(['baseUrl' => 'https://example.com', 'companyId' => 'company', 'apiKey' => 'fixture']);
        $client = $this->createMock(IClient::class); $http = $this->createMock(IClientService::class); $http->method('newClient')->willReturn($client);
        $lock = $this->createMock(SyncLock::class); $lock->method('acquire')->willReturn(true); $lock->method('isCurrent')->willReturn(true);
        $groups = $this->createMock(IGroupManager::class); $groups->method('isAdmin')->willReturn(true);
        $user = $this->createMock(IUser::class); $user->method('getUID')->willReturn('admin');
        $session = $this->createMock(IUserSession::class); $session->method('getUser')->willReturn($user);
        $controller = new TimedSettingsController('integration_autophish', $this->createMock(IRequest::class), $config, new ApiClient($http), $lock, $groups, $session);
        return [$controller, $config, $client, $lock, $groups];
    }
    public function testSlowGroupProviderCannotStartARequestAfterSettingsDeadline(): void {
        [$controller, $config, $client, $lock, $groups] = $this->timedController();
        $groups->method('get')->willReturnCallback(function () use ($controller) { $controller->now = 301; return $this->createMock(IGroup::class); });
        $config->method('getMappings')->willReturn([]);
        $client->expects(self::never())->method('get');
        $config->expects(self::never())->method('saveMappings');
        $lock->expects(self::once())->method('release');
        $response = $controller->addMapping('staff', 'list');
        self::assertSame(502, $response->getStatus()); self::assertSame('cancelled', $response->getData()['category']);
    }
    public function testSlowPaginatedDiscoveryCannotRequestAnotherPageAfterDeadline(): void {
        [$controller, $config, $client, $lock, $groups] = $this->timedController();
        $response = $this->createMock(IResponse::class); $response->method('getStatusCode')->willReturn(200);
        $response->method('getBody')->willReturn('{"items":[{"id":"list","name":"Staff"}],"nextCursor":"next-page"}');
        $client->expects(self::once())->method('get')->willReturnCallback(function () use ($controller, $response) { $controller->now = 301; return $response; });
        $lock->expects(self::once())->method('release');
        $result = $controller->discover();
        self::assertSame(502, $result->getStatus()); self::assertSame('cancelled', $result->getData()['category']);
    }
    public function testNonAdminCannotReadStateOrMutate(): void {
        [$controller, $config, $api, $lock] = $this->controller(false);
        $config->expects(self::never())->method('getPublicConnection'); $config->expects(self::never())->method('saveConnection'); $lock->expects(self::never())->method('acquire');
        self::assertSame(403, $controller->state()->getStatus());
        self::assertSame(403, $controller->save('https://example.com', 'c', 'k')->getStatus());
    }
    public function testBusySynchronizationPreventsConfigurationWrite(): void {
        [$controller, $config, $api, $lock] = $this->controller(true, false);
        $config->expects(self::never())->method('saveConnection'); $lock->expects(self::never())->method('release');
        self::assertSame(409, $controller->save('https://example.com', 'c', 'k')->getStatus());
    }
    public function testUncertainCreateReusesSavedKeyAndPersistsMappingBeforeRelease(): void {
        [$controller, $config, $api, $lock, $groups] = $this->controller();
        $group = $this->createMock(IGroup::class); $group->method('getDisplayName')->willReturn('Staff'); $groups->method('get')->with('staff')->willReturn($group);
        $config->method('getMappings')->willReturn([]); $config->method('getConnection')->willReturn(['companyId' => 'c', 'apiKey' => 'fixture']);
        $pending = ['credentialFingerprint' => hash('sha256', 'fixture'), 'groupId' => 'staff', 'name' => 'Staff', 'key' => 'saved-key', 'createdAt' => time()];
        $config->method('getPendingCreation')->willReturn($pending);
        $api->expects(self::once())->method('createList')->with(['companyId' => 'c', 'apiKey' => 'fixture'], 'Staff', 'saved-key')->willReturn(['id' => 'list', 'name' => 'Staff']);
        $saved = false;
        $config->expects(self::once())->method('saveMappings')->willReturnCallback(function ($mappings) use (&$saved) { self::assertSame('list', $mappings[0]['listId']); $saved = true; });
        $config->expects(self::once())->method('setPendingCreation')->with([]);
        $lock->expects(self::once())->method('release')->willReturnCallback(function () use (&$saved) { self::assertTrue($saved); });
        self::assertSame(200, $controller->addMapping('staff', '', 'Staff')->getStatus());
    }
    public function testUncertainCreateDoesNotRetryAfterIdempotencyExpiry(): void {
        [$controller, $config, $api, $lock, $groups] = $this->controller();
        $groups->method('get')->willReturn($this->createMock(IGroup::class));
        $config->method('getMappings')->willReturn([]);
        $config->method('getPendingCreation')->willReturn(['groupId' => 'staff', 'name' => 'Staff', 'key' => 'saved-key', 'createdAt' => time() - 86401]);
        $api->expects(self::never())->method('createList'); $lock->expects(self::once())->method('release');
        self::assertSame(400, $controller->addMapping('staff', '', 'Staff')->getStatus());
    }
    public function testPendingCreationResetRequiresInspectionAndExpiry(): void {
        [$controller, $config, $api, $lock] = $this->controller();
        $config->method('getPendingCreation')->willReturn(['groupId' => 'staff', 'name' => 'Staff', 'createdAt' => time()]);
        $config->expects(self::never())->method('setPendingCreation');
        self::assertSame(400, $controller->clearPendingCreation(false)->getStatus());
        self::assertSame(400, $controller->clearPendingCreation(true)->getStatus());
    }
    public function testExpiredPendingCreationCanBeClearedAfterInspection(): void {
        [$controller, $config, $api, $lock] = $this->controller();
        $config->method('getPendingCreation')->willReturn(['groupId' => 'staff', 'name' => 'Staff', 'createdAt' => time() - 86401]);
        $config->expects(self::once())->method('setPendingCreation')->with([]);
        self::assertSame(200, $controller->clearPendingCreation(true)->getStatus());
    }
    public function testSeparateCreateRequestsReusePersistedUncertainKey(): void {

        [$controller, $config, $api, $lock, $groups] = $this->controller();
        $groups->method('get')->willReturn($this->createMock(IGroup::class));
        $config->method('getMappings')->willReturn([]);
        $pending = [];
        $config->method('getConnection')->willReturn(['apiKey' => 'fixture']);
        $config->method('getPendingCreation')->willReturnCallback(function () use (&$pending) { return $pending; });
        $config->method('setPendingCreation')->willReturnCallback(function ($value) use (&$pending) { $pending = $value; });
        $firstKey = null;
        $api->expects(self::exactly(2))->method('createList')->willReturnCallback(function ($connection, $name, $key) use (&$firstKey, &$pending) {
            self::assertSame($key, $pending['key']);
            if ($firstKey === null) { $firstKey = $key; } else { self::assertSame($firstKey, $key); }
            throw new ApiException('network', 'Service could not be reached.');
        });
        self::assertSame(502, $controller->addMapping('staff', '', 'Staff')->getStatus());
        self::assertSame(502, $controller->addMapping('staff', '', 'Staff')->getStatus());
    }
    public function testUncertainCreationCannotBeReplayedAfterCredentialRotationButCanAttachExistingList(): void {
        $database = new FakeConfigDatabase($this->createMock(\OCP\IDBConnection::class), fn () => $this->createMock(\OCP\DB\IResult::class));
        $crypto = $this->createMock(\OCP\Security\ICrypto::class);
        $crypto->method('encrypt')->willReturnCallback(fn ($value) => 'encrypted:' . base64_encode($value));
        $crypto->method('decrypt')->willReturnCallback(fn ($value) => base64_decode(substr($value, 10)));
        $time = $this->createMock(\OCP\AppFramework\Utility\ITimeFactory::class); $time->method('getTime')->willReturn(12345);
        $lock = new SyncLock($database->db, $time);
        $config = new ConfigService($database->db, $crypto, $lock);
        $group = $this->createMock(IGroup::class); $group->method('getDisplayName')->willReturn('Staff');
        $groups = $this->createMock(IGroupManager::class); $groups->method('get')->with('staff')->willReturn($group); $groups->method('isAdmin')->willReturn(true);
        $user = $this->createMock(IUser::class); $user->method('getUID')->willReturn('admin');
        $session = $this->createMock(IUserSession::class); $session->method('getUser')->willReturn($user);
        $client = $this->createMock(IClient::class);
        $client->expects(self::once())->method('post')->willReturnCallback(function ($url, $options): never {
            self::assertSame('Bearer old-fixture-key', $options['headers']['Authorization']);
            throw new \RuntimeException('Response lost after remote creation');
        });
        $client->method('getResponseFromThrowable')->willThrowException(new \RuntimeException('No response'));
        $response = $this->createMock(IResponse::class); $response->method('getStatusCode')->willReturn(200); $response->method('getBody')->willReturn('{"id":"existing-list","name":"Staff"}');
        $client->expects(self::once())->method('get')->willReturnCallback(function ($url, $options) use ($response) {
            self::assertSame('Bearer new-fixture-key', $options['headers']['Authorization']); return $response;
        });
        $http = $this->createMock(IClientService::class); $http->method('newClient')->willReturn($client);
        $controller = new SettingsController('integration_autophish', $this->createMock(IRequest::class), $config, new ApiClient($http), $lock, $groups, $session);
        self::assertSame(200, $controller->save('https://example.com', 'company', 'old-fixture-key')->getStatus());
        self::assertSame(502, $controller->addMapping('staff', '', 'Staff')->getStatus());
        $fingerprint = hash('sha256', 'old-fixture-key');
        self::assertSame($fingerprint, $config->getPendingCreation()['credentialFingerprint']);
        self::assertSame(200, $controller->save('https://example.com', 'company', 'new-fixture-key')->getStatus());
        $retry = $controller->addMapping('staff', '', 'Staff');
        self::assertSame(400, $retry->getStatus()); self::assertStringContainsString('API key changed', $retry->getData()['error']);
        $public = json_encode($controller->state()->getData(), JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString($fingerprint, $public); self::assertStringNotContainsString('credentialFingerprint', $public);
        self::assertStringNotContainsString('old-fixture-key', $public); self::assertStringNotContainsString('new-fixture-key', $public);
        self::assertSame(200, $controller->addMapping('staff', 'existing-list')->getStatus());
        self::assertSame([], $config->getPendingCreation()); self::assertSame('existing-list', $config->getMappings()[0]['listId']);
    }
    public function testLegacyPendingCreationWithoutCredentialFingerprintCannotBeRetried(): void {
        [$controller, $config, $api, $lock, $groups] = $this->controller();
        $groups->method('get')->willReturn($this->createMock(IGroup::class));
        $config->method('getMappings')->willReturn([]); $config->method('getConnection')->willReturn(['apiKey' => 'fixture']);
        $config->method('getPendingCreation')->willReturn(['groupId' => 'staff', 'name' => 'Staff', 'key' => 'saved-key', 'createdAt' => time()]);
        $api->expects(self::never())->method('createList');
        self::assertSame(400, $controller->addMapping('staff', '', 'Staff')->getStatus());
    }
    public function testManagedListCannotBeMapped(): void {
        [$controller, $config, $api, $lock, $groups] = $this->controller();
        $groups->method('get')->willReturn($this->createMock(IGroup::class));
        $config->method('getMappings')->willReturn([]);
        $api->method('getList')->willThrowException(new ApiException('managed_list', 'List is managed.'));
        $config->expects(self::never())->method('saveMappings'); $lock->expects(self::once())->method('release');
        self::assertSame(502, $controller->addMapping('staff', 'list')->getStatus());
    }
}

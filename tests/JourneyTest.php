<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
namespace OCA\IntegrationAutoPhish\Tests;

use OCA\IntegrationAutoPhish\Controller\SettingsController;
use OCA\IntegrationAutoPhish\Service\{ApiClient, ConfigService, GroupSource, SyncLock, SyncRunner, SyncService};
use OCP\{ IGroup, IGroupManager, IRequest, IUser, IUserSession};
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Http\Client\{IClient, IClientService, IResponse};
use OCP\Security\ICrypto;
use PHPUnit\Framework\TestCase;

/** An administrator journey through real app services; HTTP cannot leave this fixture. */
class JourneyTest extends TestCase {
    public function testConfigureCreateAddRerunDepartedMemberAndInspectStatus(): void {
        $database = new FakeConfigDatabase($this->createMock(\OCP\IDBConnection::class), fn () => $this->createMock(\OCP\DB\IResult::class));
        $crypto = $this->createMock(ICrypto::class);
        $crypto->method('encrypt')->willReturnCallback(static fn ($key) => 'fixture-encrypted:' . base64_encode($key));
        $crypto->method('decrypt')->willReturnCallback(static fn ($key) => base64_decode(substr($key, strlen('fixture-encrypted:'))));
        $time = $this->createMock(ITimeFactory::class);
        $time->method('getTime')->willReturn(12345);
        $lock = new SyncLock($database->db, $time);
        $config = new ConfigService($database->db, $crypto, $lock);
        $group = $this->createMock(IGroup::class);
        $group->method('getGID')->willReturn('fixture-group');
        $group->method('getDisplayName')->willReturn('Fixture group');
        $alice = $this->createMock(IUser::class);
        $alice->method('isEnabled')->willReturn(true);
        $alice->method('getEMailAddress')->willReturn(' Alice@Example.test ');
        $alice->method('getDisplayName')->willReturn('Alice Fixture');
        $bob = $this->createMock(IUser::class);
        $bob->method('isEnabled')->willReturn(true);
        $bob->method('getEMailAddress')->willReturn('bob@example.test');
        $bob->method('getDisplayName')->willReturn('Bob Fixture');
        $users = [$alice, $bob];
        $group->method('getUsers')->willReturnCallback(static function () use (&$users) { return $users; });
        $groups = $this->createMock(IGroupManager::class);
        $groups->method('get')->with('fixture-group')->willReturn($group);
        $groups->method('search')->willReturn([$group]);
        $groups->method('isAdmin')->willReturn(true);
        $admin = $this->createMock(IUser::class);
        $admin->method('getUID')->willReturn('fixture-admin');
        $session = $this->createMock(IUserSession::class);
        $session->method('getUser')->willReturn($admin);

        $lists = $members = $requests = [];
        $http = $this->createMock(IClient::class);
        $dispatch = function (string $method, string $url, array $options) use (&$lists, &$members, &$requests): IResponse {
            self::assertSame('Bearer mock-api-key', $options['headers']['Authorization']);
            self::assertSame('fixture-company', $options['headers']['X-Company-Id']);
            self::assertFalse($options['allow_redirects']);
            $path = parse_url($url, PHP_URL_PATH);
            $requests[] = [$method, $path];
            $payload = isset($options['body']) ? json_decode($options['body'], true, 512, JSON_THROW_ON_ERROR) : null;
            $status = 200;
            if ($method !== 'get') self::assertNotEmpty($options['headers']['Idempotency-Key']);
            if ($path === '/api/v1/target-lists' && $method === 'post') {
                $lists['fixture-list'] = ['id' => 'fixture-list', 'name' => $payload['name'], 'directorySyncMapping' => null, 'pseudonymous' => false, 'masked' => false];
                $data = $lists['fixture-list']; $status = 201;
            } elseif ($path === '/api/v1/target-lists' && $method === 'get') {
                $data = ['items' => array_values($lists), 'nextCursor' => null];
            } elseif ($path === '/api/v1/target-lists/fixture-list' && $method === 'get') {
                $data = $lists['fixture-list'];
            } elseif ($path === '/api/v1/target-lists/fixture-list/targets' && $method === 'get') {
                $data = ['items' => array_values($members), 'nextCursor' => null];
            } elseif ($path === '/api/v1/target-lists/fixture-list/targets/bulk' && $method === 'post') {
                $created = 0;
                foreach ($payload['targets'] as $member) {
                    if (!isset($members[$member['email']])) {
                        $member['id'] = 'fixture-member-' . (count($members) + 1);
                        $member['targetListId'] = 'fixture-list';
                        $members[$member['email']] = $member; $created++;
                    }
                }
                $data = ['created' => $created]; $status = 201;
            } elseif ($method === 'delete' && str_starts_with($path, '/api/v1/target-lists/fixture-list/targets/')) {
                $id = basename($path);
                foreach ($members as $email => $member) { if ($member['id'] === $id) unset($members[$email]); }
                $data = ['id' => $id, 'deleted' => true];
            } else {
                throw new \LogicException('Fixture rejected an unexpected endpoint or method');
            }
            $response = $this->createMock(IResponse::class);
            $response->method('getStatusCode')->willReturn($status);
            $response->method('getBody')->willReturn(json_encode($data, JSON_THROW_ON_ERROR));
            return $response;
        };
        foreach (['get', 'post', 'delete'] as $method) {
            $http->method($method)->willReturnCallback(fn ($url, $options) => $dispatch($method, $url, $options));
        }
        $transport = $this->createMock(IClientService::class);
        $transport->method('newClient')->willReturn($http);
        $api = new ApiClient($transport);
        $controller = new SettingsController('integration_autophish', $this->createMock(IRequest::class), $config, $api, $lock, $groups, $session);
        $response = $controller->save('https://autophish.example.test', 'fixture-company', 'mock-api-key', 300);
        self::assertSame(200, $response->getStatus());
        self::assertStringNotContainsString('mock-api-key', json_encode($response->getData()));
        self::assertStringNotContainsString('mock-api-key', json_encode($database->rows));
        self::assertSame([], $controller->discover()->getData()['lists']);
        self::assertSame(200, $controller->addMapping('fixture-group', '', 'Dedicated fixture list')->getStatus());
        self::assertCount(1, $lists);
        $runner = new SyncRunner($config, new SyncService(new GroupSource($groups), $api), $lock, $time);
        self::assertSame('success', $runner->run()['state']);
        self::assertCount(2, $members);
        self::assertSame('Alice Fixture', $members['alice@example.test']['firstName']);
        self::assertSame(0, $runner->run()['added']);
        $users = [$bob];
        $status = $runner->run();
        self::assertSame(1, $status['removed']);
        self::assertSame(['bob@example.test'], array_keys($members));
        self::assertSame(12345, $controller->state()->getData()['status']['lastSuccess']);
        self::assertSame(200, $controller->removeMapping('fixture-group')->getStatus());
        self::assertCount(1, $lists);
        self::assertCount(1, $members);
        foreach ($requests as [$method, $path]) {
            self::assertStringNotContainsString('/api/v1/targets/', $path);
            self::assertStringNotContainsString('hardDelete', $path);
        }
    }
}

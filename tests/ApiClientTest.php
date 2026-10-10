<?php
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\IntegrationAutoPhish\Tests;

use OCA\IntegrationAutoPhish\Service\ApiClient;
use OCA\IntegrationAutoPhish\Service\ApiException;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use PHPUnit\Framework\TestCase;

final class ApiClientTest extends TestCase {
    private const CONNECTION = ['baseUrl' => 'https://autophish.example', 'companyId' => 'company-a', 'apiKey' => 'ap_test_key'];
    private IClient $http;
    private ApiClient $api;
    private array $waits = [];

    protected function setUp(): void {
        $this->http = $this->createMock(IClient::class);
        $service = $this->createMock(IClientService::class);
        $service->method('newClient')->willReturn($this->http);
        $this->api = new ApiClient($service, function (int $seconds): void { $this->waits[] = $seconds; });
    }

    private function response(array|string $data, int $status = 200, array $headers = []): IResponse {
        $response = $this->createMock(IResponse::class);
        $response->method('getStatusCode')->willReturn($status);
        $response->method('getBody')->willReturn(is_array($data) ? json_encode($data, JSON_THROW_ON_ERROR) : $data);
        $response->method('getHeader')->willReturnCallback(static fn (string $name): string => $headers[$name] ?? '');
        return $response;
    }

    public function testCancellationStopsBetweenPagesAndGuardIsRestored(): void {
        $calls = 0;
        $this->http->method('get')->willReturnCallback(function () use (&$calls): IResponse {
            $calls++;
            return $calls === 1
                ? $this->response(['items' => [['id' => 'a', 'name' => 'A']], 'nextCursor' => 'next'])
                : $this->response(['items' => [], 'nextCursor' => null]);
        });
        try {
            $this->api->withRequestGuard(static function () use (&$calls): bool { return $calls === 0; }, fn () => $this->api->listLists(self::CONNECTION));
            self::fail('Expected cancellation');
        } catch (ApiException $e) {
            self::assertSame('cancelled', $e->category);
        }
        self::assertSame(1, $calls);
        self::assertSame([], $this->api->listLists(self::CONNECTION));
        self::assertSame(2, $calls);
    }

    public function testCancellationAfterRetrySleepPreventsNextAttempt(): void {
        $current = true;
        $service = $this->createMock(IClientService::class);
        $service->method('newClient')->willReturn($this->http);
        $api = new ApiClient($service, function () use (&$current): void { $current = false; });
        $this->http->expects(self::once())->method('get')->willReturn($this->response([], 429, ['Retry-After' => '1']));
        $this->expectException(ApiException::class);
        $api->withRequestGuard(static function () use (&$current): bool { return $current; }, fn () => $api->listLists(self::CONNECTION));
    }

    public function testNestedGuardCannotOverrideOuterCancellationAndRestoresOnException(): void {
        $this->http->expects(self::never())->method('get');
        $this->api->withRequestGuard(static fn (): bool => false, function (): void {
            try {
                $this->api->withRequestGuard(static fn (): bool => true, fn () => $this->api->listLists(self::CONNECTION));
                self::fail('Expected cancellation');
            } catch (ApiException $e) { self::assertSame('cancelled', $e->category); }
            try {
                $this->api->listLists(self::CONNECTION);
                self::fail('Expected outer cancellation');
            } catch (ApiException $e) { self::assertSame('cancelled', $e->category); }
        });
    }

    public function testCompletePaginationAndSecurityOptions(): void {
        $calls = [];
        $this->http->method('get')->willReturnCallback(function (string $url, array $options) use (&$calls): IResponse {
            $calls[] = [$url, $options];
            return count($calls) === 1
                ? $this->response(['items' => [['id' => 'a', 'name' => 'A', 'futureField' => true]], 'nextCursor' => 'a/+'])
                : $this->response(['items' => [['id' => 'b', 'name' => 'B']], 'nextCursor' => null]);
        });
        self::assertCount(2, $this->api->listLists(self::CONNECTION));
        self::assertSame('https://autophish.example/api/v1/target-lists?limit=100&cursor=a%2F%2B', $calls[1][0]);
        self::assertSame('Bearer ap_test_key', $calls[0][1]['headers']['Authorization']);
        self::assertSame('company-a', $calls[0][1]['headers']['X-Company-Id']);
        self::assertFalse($calls[0][1]['allow_redirects']);
        self::assertTrue($calls[0][1]['verify']);
        self::assertSame(20, $calls[0][1]['timeout']);
    }

    public function testRetryPreservesMutationKeyAndBody(): void {
        $calls = [];
        $this->http->method('post')->willReturnCallback(function (string $url, array $options) use (&$calls): IResponse {
            $calls[] = [$url, $options];
            return count($calls) === 1 ? $this->response([], 425, ['Retry-After' => '2']) : $this->response(['created' => 1], 201);
        });
        self::assertSame(1, $this->api->addMembers(self::CONNECTION, 'list-a', [['email' => 'alice@example.com']], 'stable-key'));
        self::assertSame($calls[0], $calls[1]);
        self::assertSame('stable-key', $calls[0][1]['headers']['Idempotency-Key']);
        self::assertSame(['targets' => [['email' => 'alice@example.com']]], json_decode($calls[0][1]['body'], true));
        self::assertSame([2], $this->waits);
    }

    public function testThrownHttpRateLimitUsesNextcloudResponseExtraction(): void {
        $exception = new \RuntimeException('secret provider payload');
        $attempt = 0;
        $this->http->method('get')->willReturnCallback(function () use (&$attempt, $exception): IResponse {
            if ($attempt++ === 0) { throw $exception; }
            return $this->response(['items' => [], 'nextCursor' => null]);
        });
        $this->http->expects(self::once())->method('getResponseFromThrowable')->with($exception)
            ->willReturn($this->response([], 429, ['X-RateLimit-Reset' => (string) time()]));
        self::assertSame([], $this->api->listLists(self::CONNECTION));
        self::assertSame([0], $this->waits);
    }

    public function testHttpDateRetryAndAttemptLimit(): void {
        $this->http->expects(self::exactly(3))->method('get')->willReturn($this->response([], 429, ['Retry-After' => gmdate('D, d M Y H:i:s \G\M\T', time() + 2)]));
        try { $this->api->listLists(self::CONNECTION); self::fail('Expected rate limit'); }
        catch (ApiException $e) { self::assertSame('rate_limit', $e->category); self::assertSame(429, $e->httpStatus); }
        self::assertCount(2, $this->waits);
        foreach ($this->waits as $wait) { self::assertGreaterThanOrEqual(0, $wait); self::assertLessThanOrEqual(2, $wait); }
    }

    public function testDateTimeResetAndDailyResetAreRespected(): void {
        $this->http->expects(self::once())->method('get')->willReturn($this->response([], 429, [
            'X-RateLimit-Reset' => gmdate('c', time()),
            'X-RateLimit-Daily-Reset' => gmdate('c', time() + 3600),
        ]));
        $this->expectException(ApiException::class);
        try { $this->api->listLists(self::CONNECTION); } finally { self::assertSame([], $this->waits); }
    }

    public function testLongRetryDoesNotRetryPrematurely(): void {
        $this->http->expects(self::once())->method('get')->willReturn($this->response([], 429, ['Retry-After' => '3600']));
        $this->expectException(ApiException::class);
        try { $this->api->listLists(self::CONNECTION); } finally { self::assertSame([], $this->waits); }
    }

    public function testIncompletePaginationFailsClosed(): void {
        foreach ([['items' => []], ['items' => [], 'nextCursor' => 'more'], ['items' => 'bad', 'nextCursor' => null], ['items' => [], 'nextCursor' => 123]] as $page) {
            $this->setUp();
            $this->http->method('get')->willReturn($this->response($page));
            try { $this->api->listLists(self::CONNECTION); self::fail('Expected invalid page'); }
            catch (ApiException $e) { self::assertSame('invalid_response', $e->category); }
        }
    }

    public function testRepeatedCursorAndDuplicatesFailClosed(): void {
        $this->http->expects(self::exactly(2))->method('get')->willReturnOnConsecutiveCalls(
            $this->response(['items' => [['id' => 'a', 'name' => 'A']], 'nextCursor' => 'cursor']),
            $this->response(['items' => [['id' => 'b', 'name' => 'B']], 'nextCursor' => 'cursor'])
        );
        $this->expectException(ApiException::class);
        $this->api->listLists(self::CONNECTION);
    }

    public function testDuplicateMembershipAndWrongListFailClosed(): void {
        foreach ([
            [['id' => 'a', 'targetListId' => 'list-a', 'email' => 'a@example.com'], ['id' => 'a', 'targetListId' => 'list-a', 'email' => 'a@example.com']],
            [['id' => 'a', 'targetListId' => 'other', 'email' => 'a@example.com']],
        ] as $items) {
            $this->setUp();
            $this->http->method('get')->willReturn($this->response(['items' => $items, 'nextCursor' => null]));
            try { $this->api->listMembers(self::CONNECTION, 'list-a'); self::fail('Expected invalid membership'); }
            catch (ApiException $e) { self::assertSame('invalid_response', $e->category); }
        }
    }

    public function testUnsafeOriginsRejectedBeforeSendingCredentials(): void {
        $this->http->expects(self::never())->method('get');
        foreach (['http://example.com', 'https://user@example.com', 'https://example.com/path', 'https://example.com/?query=x', 'https://example.com/#fragment', "https://example.com\n", 'https://example.com\\evil'] as $base) {
            try { $this->api->listLists(array_replace(self::CONNECTION, ['baseUrl' => $base])); self::fail('Expected invalid origin'); }
            catch (ApiException $e) { self::assertSame('configuration', $e->category); }
        }
    }

    public function testManagedMaskedAndPseudonymousListsRejected(): void {
        foreach ([['directorySyncMapping' => ['enabled' => false]], ['masked' => true], ['pseudonymous' => true]] as $flags) {
            $this->setUp();
            $this->http->method('get')->willReturn($this->response(['id' => 'list-a', 'name' => 'A'] + $flags));
            try { $this->api->getList(self::CONNECTION, 'list-a'); self::fail('Expected protected list'); }
            catch (ApiException $e) { self::assertContains($e->category, ['managed_list', 'protected_list']); }
        }
    }

    public function testErrorsDoNotExposeProviderOrNetworkMessages(): void {
        $this->http->method('get')->willReturn($this->response(['error' => ['message' => 'email secret ap_test_key']], 403));
        try { $this->api->listLists(self::CONNECTION); self::fail('Expected API error'); }
        catch (ApiException $e) { self::assertSame('permission', $e->category); self::assertStringNotContainsString('secret', $e->getMessage()); }
        $this->setUp();
        $this->http->method('get')->willThrowException(new \RuntimeException('ap_test_key email secret'));
        $this->http->method('getResponseFromThrowable')->willThrowException(new \RuntimeException('ap_test_key'));
        try { $this->api->listLists(self::CONNECTION); self::fail('Expected network error'); }
        catch (ApiException $e) { self::assertSame('network', $e->category); self::assertStringNotContainsString('ap_test_key', $e->getMessage()); self::assertNull($e->getPrevious()); }
    }

    public function testAllowlistedProviderErrorsExposeOnlySafeActionableMessages(): void {
        foreach ([
            [409, ['code' => 'TARGET_LIST_MANAGED'], 'managed_list'],
            [409, ['code' => 'TARGET_LIST_PSEUDONYMOUS'], 'protected_list'],
            [403, ['code' => 'TARGET_LIST_PSEUDONYMOUS'], 'protected_list'],
            [400, ['invalidDomains' => ['private.example'], 'invalidEmails' => ['person@private.example']], 'domain'],
            [409, ['code' => 'secret-provider-code'], 'conflict'],
            [400, ['code' => 'TARGET_LIST_MANAGED'], 'validation'],
        ] as [$status, $details, $category]) {
            $this->setUp();
            $this->http->method('get')->willReturn($this->response(['error' => ['message' => 'private.example secret ap_test_key', 'details' => $details]], $status));
            try { $this->api->listLists(self::CONNECTION); self::fail('Expected API error'); }
            catch (ApiException $e) {
                self::assertSame($category, $e->category);
                self::assertSame($status, $e->httpStatus);
                self::assertStringNotContainsString('private.example', $e->getMessage());
                self::assertStringNotContainsString('ap_test_key', $e->getMessage());
                self::assertStringNotContainsString('secret-provider-code', $e->getMessage());
            }
        }
    }

    public function testRedirectNotFollowedAndMalformedJsonRejected(): void {
        foreach ([[$this->response([], 302), 'service'], [$this->response('{bad'), 'invalid_response'], [$this->response('[]'), 'invalid_response']] as [$response, $category]) {
            $this->setUp();
            $this->http->method('get')->willReturn($response);
            try { $this->api->listLists(self::CONNECTION); self::fail('Expected invalid response'); }
            catch (ApiException $e) { self::assertSame($category, $e->category); }
        }
    }

    public function testBulkCountAndBytesAreBounded(): void {
        $this->http->expects(self::never())->method('post');
        foreach ([array_fill(0, 201, ['email' => 'a@example.com']), array_fill(0, 200, ['email' => str_repeat('a', 64) . '@example.com', 'firstName' => str_repeat('ü', 100), 'lastName' => str_repeat('ü', 100)])] as $members) {
            try { $this->api->addMembers(self::CONNECTION, 'a', $members, 'key'); self::fail('Expected oversized batch'); }
            catch (ApiException $e) { self::assertSame('validation', $e->category); }
        }
    }

    public function testCreateAndDeleteUseMembershipEndpoint(): void {
        $this->http->expects(self::once())->method('post')->with('https://autophish.example/api/v1/target-lists', self::callback(static fn (array $options): bool => json_decode($options['body'], true) === ['name' => 'Team']))
            ->willReturn($this->response(['id' => 'list-a', 'name' => 'Team'], 201));
        self::assertSame('list-a', $this->api->createList(self::CONNECTION, ' Team ', 'create-key')['id']);
        $this->http->expects(self::once())->method('delete')->with('https://autophish.example/api/v1/target-lists/list-a/targets/member-a', self::callback(static fn (array $options): bool => $options['headers']['Idempotency-Key'] === 'delete-key'))
            ->willReturn($this->response(['id' => 'member-a', 'deleted' => true]));
        $this->api->removeMember(self::CONNECTION, 'list-a', 'member-a', 'delete-key');
    }
}

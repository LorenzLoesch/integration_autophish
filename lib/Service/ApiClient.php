<?php
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\IntegrationAutoPhish\Service;

use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;

class ApiClient {
    private \Closure $sleep;
    private ?\Closure $requestGuard = null;

    public function __construct(private IClientService $http, ?\Closure $sleep = null) {
        $this->sleep = $sleep ?? static fn (int $seconds) => sleep($seconds);
    }

    /** Apply cancellation and time limits to every HTTP attempt, including paginated reads. */
    public function withRequestGuard(callable $guard, callable $operation): mixed {
        $previous = $this->requestGuard;
        $this->requestGuard = $previous === null
            ? \Closure::fromCallable($guard)
            : static fn (): bool => $previous() && $guard();
        try {
            return $operation();
        } finally {
            $this->requestGuard = $previous;
        }
    }

    private function assertRequestCurrent(): void {
        if ($this->requestGuard !== null && !($this->requestGuard)()) {
            throw new ApiException('cancelled', 'Synchronization was cancelled or its time limit was reached.');
        }
    }

    public function listLists(array $connection): array {
        return $this->paginate($connection, '/api/v1/target-lists', false);
    }

    public function getList(array $connection, string $listId): array {
        $list = $this->request($connection, 'get', '/api/v1/target-lists/' . $this->id($listId));
        $this->validateList($list);
        if ($list['id'] !== $listId) {
            throw $this->invalidResponse();
        }
        $this->assertWritableList($list);
        return $list;
    }

    public function listMembers(array $connection, string $listId): array {
        return $this->paginate($connection, '/api/v1/target-lists/' . $this->id($listId) . '/targets', true, $listId);
    }

    public function createList(array $connection, string $name, string $idempotencyKey): array {
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > 200) {
            throw new ApiException('validation', 'The target list name is invalid.');
        }
        $list = $this->request($connection, 'post', '/api/v1/target-lists', ['name' => $name], $idempotencyKey);
        $this->validateList($list);
        $this->assertWritableList($list);
        return $list;
    }

    public function addMembers(array $connection, string $listId, array $members, string $idempotencyKey): int {
        if (!array_is_list($members) || count($members) < 1 || count($members) > 200) {
            throw new ApiException('validation', 'A target batch must contain between 1 and 200 recipients.');
        }
        foreach ($members as $member) {
            if (!is_array($member) || !is_string($member['email'] ?? null) || !filter_var($member['email'], FILTER_VALIDATE_EMAIL)
                || strlen($member['email']) > 320 || array_diff(array_keys($member), ['email', 'firstName', 'lastName']) !== []) {
                throw new ApiException('validation', 'A target batch contains an invalid recipient.');
            }
            foreach (['firstName', 'lastName'] as $field) {
                if (array_key_exists($field, $member) && (!is_string($member[$field]) || mb_strlen($member[$field]) > 100)) {
                    throw new ApiException('validation', 'A recipient name is invalid.');
                }
            }
        }
        $result = $this->request($connection, 'post', '/api/v1/target-lists/' . $this->id($listId) . '/targets/bulk', ['targets' => $members], $idempotencyKey);
        if (!is_int($result['created'] ?? null) || $result['created'] < 0 || $result['created'] > count($members)) {
            throw $this->invalidResponse();
        }
        return $result['created'];
    }

    public function removeMember(array $connection, string $listId, string $membershipId, string $idempotencyKey): void {
        $result = $this->request($connection, 'delete', '/api/v1/target-lists/' . $this->id($listId) . '/targets/' . $this->id($membershipId), null, $idempotencyKey);
        if (($result['deleted'] ?? null) !== true || ($result['id'] ?? null) !== $membershipId) {
            throw $this->invalidResponse();
        }
    }

    public function assertWritableList(array $list): void {
        if (array_key_exists('directorySyncMapping', $list) && $list['directorySyncMapping'] !== null) {
            throw new ApiException('managed_list', 'This target list is managed by another directory integration.');
        }
        foreach (['pseudonymous', 'masked'] as $field) {
            if (array_key_exists($field, $list) && !is_bool($list[$field])) {
                throw $this->invalidResponse();
            }
            if (($list[$field] ?? false) === true) {
                throw new ApiException('protected_list', 'Pseudonymous or masked target lists cannot be synchronized.');
            }
        }
    }

    private function paginate(array $connection, string $path, bool $members, ?string $listId = null): array {
        $result = [];
        $ids = [];
        $cursors = [];
        $cursor = null;
        // Bound pathological providers without ever treating a truncated result as complete.
        for ($page = 0; $page < 1000; $page++) {
            $query = ['limit' => 100];
            if ($cursor !== null) {
                $query['cursor'] = $cursor;
            }
            $data = $this->request($connection, 'get', $path . '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986));
            if (!is_array($data['items'] ?? null) || !array_is_list($data['items']) || !array_key_exists('nextCursor', $data)
                || ($data['nextCursor'] !== null && (!is_string($data['nextCursor']) || $data['nextCursor'] === '' || strlen($data['nextCursor']) > 4096))) {
                throw $this->invalidResponse();
            }
            foreach ($data['items'] as $item) {
                if (!is_array($item) || !is_string($item['id'] ?? null) || $item['id'] === '' || isset($ids[$item['id']])) {
                    throw $this->invalidResponse();
                }
                if ($members) {
                    if (($item['targetListId'] ?? null) !== $listId || !is_string($item['email'] ?? null) || !filter_var($item['email'], FILTER_VALIDATE_EMAIL)) {
                        throw $this->invalidResponse();
                    }
                } else {
                    $this->validateList($item);
                }
                $ids[$item['id']] = true;
                $result[] = $item;
            }
            $cursor = $data['nextCursor'];
            if ($cursor === null) {
                return $result;
            }
            if ($data['items'] === [] || isset($cursors[$cursor])) {
                throw $this->invalidResponse();
            }
            $cursors[$cursor] = true;
        }
        throw $this->invalidResponse();
    }

    private function validateList(array $list): void {
        if (!is_string($list['id'] ?? null) || $list['id'] === '' || !is_string($list['name'] ?? null) || trim($list['name']) === '') {
            throw $this->invalidResponse();
        }
    }

    private function id(string $id): string {
        if ($id === '' || strlen($id) > 200 || preg_match('/^[A-Za-z0-9_-]+$/D', $id) !== 1) {
            throw new ApiException('validation', 'A target identifier is invalid.');
        }
        return rawurlencode($id);
    }

    private function request(array $connection, string $method, string $path, ?array $body = null, ?string $key = null): array {
        $base = $connection['baseUrl'] ?? '';
        $url = is_string($base) ? parse_url($base) : false;
        if (!is_array($url) || ($url['scheme'] ?? '') !== 'https' || !isset($url['host'])
            || filter_var($base, FILTER_VALIDATE_URL) === false || preg_match('/[\x00-\x20\x7f\\\\]/', $base)
            || isset($url['user']) || isset($url['pass'])
            || isset($url['query']) || isset($url['fragment']) || !in_array($url['path'] ?? '', ['', '/'], true)) {
            throw new ApiException('configuration', 'The AutoPhish URL must be an HTTPS origin without credentials or a path.');
        }
        foreach (['companyId', 'apiKey'] as $field) {
            if (!is_string($connection[$field] ?? null) || $connection[$field] === '' || preg_match('/[\x00-\x20\x7f]/', $connection[$field])) {
                throw new ApiException('configuration', 'The AutoPhish connection is incomplete or invalid.');
            }
        }
        $options = ['headers' => ['Authorization' => 'Bearer ' . $connection['apiKey'], 'X-Company-Id' => $connection['companyId'], 'Accept' => 'application/json'],
            'allow_redirects' => false, 'verify' => true, 'timeout' => 20, 'connect_timeout' => 10];
        if ($key !== null) {
            if (!preg_match('/^[A-Za-z0-9._:-]{1,200}$/D', $key)) {
                throw new ApiException('validation', 'The idempotency key is invalid.');
            }
            $options['headers']['Idempotency-Key'] = $key;
        }
        if ($body !== null) {
            try {
                $options['body'] = json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            } catch (\JsonException) {
                throw new ApiException('validation', 'The request contains invalid text.');
            }
            if (strlen($options['body']) >= 64000) {
                throw new ApiException('validation', 'The target batch exceeds the request size limit.');
            }
            $options['headers']['Content-Type'] = 'application/json';
        }
        $client = $this->http->newClient();
        for ($attempt = 0; $attempt < 3; $attempt++) {
            $this->assertRequestCurrent();
            try {
                $response = $client->$method(rtrim($base, '/') . $path, $options);
            } catch (\Throwable $e) {
                try {
                    $response = $client->getResponseFromThrowable($e);
                } catch (\Throwable) {
                    throw new ApiException('network', 'The AutoPhish service could not be reached.');
                }
            }
            $status = $response->getStatusCode();
            if (in_array($status, [425, 429], true) && $attempt < 2) {
                $wait = $this->retryDelay($response, $attempt);
                if ($wait <= 10) {
                    ($this->sleep)($wait);
                    continue;
                }
            }
            if ($status < 200 || $status >= 300) {
                $category = match ($status) {
                    401 => 'authentication', 403 => 'permission', 404 => 'not_found', 409 => 'conflict', 425, 429 => 'rate_limit',
                    400, 413, 415, 422 => 'validation', default => 'service',
                };
                $safeMessage = 'AutoPhish could not complete the request (' . $category . ').';
                // Inspect only documented error structure. Never propagate identities or provider text.
                $rawError = $response->getBody();
                if (is_string($rawError) && strlen($rawError) <= 64000) {
                    try {
                        $error = json_decode($rawError, true, 32, JSON_THROW_ON_ERROR);
                        $details = is_array($error) && is_array($error['error'] ?? null) ? ($error['error']['details'] ?? null) : null;
                        if (is_array($details)) {
                            if ($status === 409 && ($details['code'] ?? null) === 'TARGET_LIST_MANAGED') {
                                $category = 'managed_list';
                                $safeMessage = 'This list is managed by another directory integration. Choose an ordinary dedicated list.';
                            } elseif (in_array($status, [403, 409], true) && ($details['code'] ?? null) === 'TARGET_LIST_PSEUDONYMOUS') {
                                $category = 'protected_list';
                                $safeMessage = 'Pseudonymous or masked target lists cannot be synchronized. Choose an ordinary dedicated list.';
                            } elseif ($status === 400 && is_array($details['invalidDomains'] ?? null) && is_array($details['invalidEmails'] ?? null)) {
                                // The public domain-validation error uses these fields rather than a detail code.
                                $category = 'domain';
                                $safeMessage = 'Verify all recipient email domains in AutoPhish before synchronizing.';
                            }
                        }
                    } catch (\JsonException) {
                        // Non-JSON errors retain the generic status category.
                    }
                }
                if ($category === 'authentication') {
                    $safeMessage = 'Check the AutoPhish API key for expiry or revocation.';
                } elseif ($category === 'permission') {
                    $safeMessage = 'Check the selected company, key assignment, and targets:read and targets:write scopes.';
                }
                throw new ApiException($category, $safeMessage, $status);
            }
            $raw = $response->getBody();
            if (!is_string($raw) || strlen($raw) > 4 * 1024 * 1024) {
                throw $this->invalidResponse();
            }
            try {
                $data = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                throw $this->invalidResponse();
            }
            if (!is_array($data) || array_is_list($data)) {
                throw $this->invalidResponse();
            }
            return $data;
        }
        throw new ApiException('rate_limit', 'AutoPhish requests are temporarily limited.');
    }

    private function retryDelay(IResponse $response, int $attempt): int {
        $retry = trim($response->getHeader('Retry-After'));
        if ($retry !== '') {
            if (ctype_digit($retry)) {
                return (int) min(86400, (float) $retry);
            }
            $date = strtotime($retry);
            if ($date !== false) {
                return max(0, $date - time());
            }
        }
        $delays = [];
        foreach (['X-RateLimit-Reset', 'X-RateLimit-Daily-Reset'] as $header) {
            $reset = trim($response->getHeader($header));
            $date = ctype_digit($reset) ? (int) min(PHP_INT_MAX, (float) $reset) : ($reset !== '' ? strtotime($reset) : false);
            if ($date !== false) {
                $delays[] = max(0, $date - time());
            }
        }
        if ($delays !== []) {
            return max($delays);
        }
        return $attempt + 1;
    }

    private function invalidResponse(): ApiException {
        return new ApiException('invalid_response', 'AutoPhish returned an incomplete or invalid response.');
    }
}

<?php
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);
namespace OCA\IntegrationAutoPhish\Controller;

use OCA\IntegrationAutoPhish\Service\{ApiClient, ApiException, ConfigService, SyncLock};
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\{IRequest, IGroupManager, IUserSession};

/** Default Nextcloud middleware requires an authenticated admin and a CSRF token. */
class SettingsController extends Controller {
    public function __construct(string $appName, IRequest $request, private ConfigService $config, private ApiClient $api, private SyncLock $lock, private IGroupManager $groups, private IUserSession $session) { parent::__construct($appName, $request); }
    protected function monotonicTime(): float { return hrtime(true) / 1e9; }
    private function handle(\Closure $action, bool $mutating = false): JSONResponse {
        $user = $this->session->getUser();
        if ($user === null || !$this->groups->isAdmin($user->getUID())) { return new JSONResponse(['error' => 'Administrator access is required.'], 403); }
        if ($mutating && !$this->lock->acquire()) { return new JSONResponse(['error' => 'A synchronization or configuration change is already running.'], 409); }
        try {
            if (!$mutating) { return new JSONResponse($action()); }
            $deadline = $this->monotonicTime() + 300;
            $revision = $this->config->getRevision();
            $guard = fn (): bool => $this->monotonicTime() < $deadline && $this->lock->isCurrent() && $this->config->isCurrent($revision);
            if (!$guard()) { return new JSONResponse(['error' => 'The integration configuration changed or was disabled.'], 409); }
            return new JSONResponse($this->api->withRequestGuard($guard, $action));
        }
        catch (\InvalidArgumentException $e) { return new JSONResponse(['error' => $e->getMessage()], 400); }
        catch (ApiException $e) { return new JSONResponse(['error' => $e->getMessage(), 'category' => $e->category], 502); }
        catch (\Throwable) { return new JSONResponse(['error' => 'The operation failed. Check the server configuration and logs.'], 500); }
        finally { if ($mutating) { $this->lock->release(); } }
    }
    private function stateData(): array {
        $mappings = $this->config->getMappings();
        foreach ($mappings as &$mapping) { $mapping['groupName'] = $this->groups->get($mapping['groupId'])?->getDisplayName() ?? $mapping['groupId']; } unset($mapping);
        $pending = $this->config->getPendingCreation();
        $pendingDetails = $pending === [] ? null : ['groupId' => $pending['groupId'], 'name' => $pending['name'], 'createdAt' => $pending['createdAt']];
        return ['connection' => $this->config->getPublicConnection(), 'interval' => $this->config->getInterval(), 'mappings' => $mappings, 'status' => $this->config->getStatus(), 'pendingCreation' => $pending !== [], 'pendingCreationDetails' => $pendingDetails]; }
    public function state(): JSONResponse { return $this->handle(fn () => $this->stateData()); }
    public function save(string $baseUrl, string $companyId, string $apiKey = '', int $interval = 3600): JSONResponse {
        return $this->handle(function () use ($baseUrl, $companyId, $apiKey, $interval) { $this->config->saveConnection($baseUrl, $companyId, $apiKey, $interval); return $this->stateData(); }, true);
    }
    public function discover(): JSONResponse {
        return $this->handle(function () {
            $lists = [];
            foreach ($this->api->listLists($this->config->getConnection()) as $list) {
                try { $this->api->assertWritableList($list); }
                catch (ApiException $e) { if (in_array($e->category, ['managed_list', 'protected_list'], true)) { continue; } throw $e; }
                $lists[] = ['id' => $list['id'], 'name' => $list['name']];
            }
            $groups = array_map(fn ($group) => ['id' => $group->getGID(), 'name' => $group->getDisplayName()], $this->groups->search(''));
            return ['groups' => array_values($groups), 'lists' => $lists];
        }, true);
    }
    public function addMapping(string $groupId, string $listId = '', string $name = ''): JSONResponse {
        return $this->handle(function () use ($groupId, $listId, $name) {
            $group = $this->groups->get($groupId);
            if ($group === null) { throw new \InvalidArgumentException('The selected Nextcloud group no longer exists.'); }
            $mappings = $this->config->getMappings();
            foreach ($mappings as $mapping) { if ($mapping['groupId'] === $groupId) { throw new \InvalidArgumentException('This group already has a mapping.'); } }
            $connection = $this->config->getConnection();
            if ($listId !== '') {
                if ($name !== '') { throw new \InvalidArgumentException('Choose an existing list or enter a new list name.'); }
                $list = $this->api->getList($connection, $listId);
            } else {
                $name = trim($name);
                if ($name === '' || mb_strlen($name) > 200) { throw new \InvalidArgumentException('Enter a list name of at most 200 characters.'); }
                $pending = $this->config->getPendingCreation();
                if ($pending !== [] && (($pending['groupId'] ?? '') !== $groupId || ($pending['name'] ?? '') !== $name)) {
                    throw new \InvalidArgumentException('An earlier list creation is unresolved. Retry its original group and name before creating another list.');
                }
                if ($pending !== [] && time() - $pending['createdAt'] >= 86400) {
                    throw new \InvalidArgumentException('The uncertain list creation is older than 24 hours. Check AutoPhish for the created list, then attach that existing list.');
                }
                $fingerprint = hash('sha256', $connection['apiKey']);
                if ($pending !== [] && (!is_string($pending['credentialFingerprint'] ?? null) || !hash_equals($pending['credentialFingerprint'], $fingerprint))) {
                    throw new \InvalidArgumentException('The API key changed or the original creation credential is unknown. Inspect AutoPhish and attach the existing list instead of retrying creation.');
                }
                if ($pending === []) {
                    $pending = ['credentialFingerprint' => $fingerprint, 'groupId' => $groupId, 'name' => $name, 'key' => 'nc-list-' . bin2hex(random_bytes(16)), 'createdAt' => time()];
                    $this->config->setPendingCreation($pending);
                }
                $list = $this->api->createList($connection, $name, $pending['key']);
            }
            foreach ($mappings as $mapping) { if ($mapping['listId'] === $list['id']) { throw new \InvalidArgumentException('Each mapping must use its own dedicated target list.'); } }
            $mappings[] = ['groupId' => $groupId, 'groupName' => $group->getDisplayName(), 'listId' => $list['id'], 'listName' => $list['name'], 'enabled' => true];
            $this->config->saveMappings($mappings);
            if (($this->config->getPendingCreation()['groupId'] ?? '') === $groupId) { $this->config->setPendingCreation([]); }
            return $this->stateData();
        }, true);
    }
    public function clearPendingCreation(bool $verifiedAbsent = false): JSONResponse {
        return $this->handle(function () use ($verifiedAbsent) {
            $pending = $this->config->getPendingCreation();
            if (!$verifiedAbsent) { throw new \InvalidArgumentException('Confirm that you inspected AutoPhish and verified no list was created.'); }
            if ($pending !== [] && time() - $pending['createdAt'] < 86400) { throw new \InvalidArgumentException('Retry the original creation while its 24-hour replay window is active.'); }
            $this->config->setPendingCreation([]);
            return $this->stateData();
        }, true);
    }
    public function updateMapping(string $groupId, bool $enabled): JSONResponse {
        return $this->handle(function () use ($groupId, $enabled) {
            $mappings = $this->config->getMappings(); $found = false;
            foreach ($mappings as &$mapping) { if ($mapping['groupId'] === $groupId) { $mapping['enabled'] = $enabled; $found = true; } } unset($mapping);
            if (!$found) { throw new \InvalidArgumentException('The mapping no longer exists.'); }
            $this->config->saveMappings($mappings); return $this->stateData();
        }, true);
    }
    public function removeMapping(string $groupId): JSONResponse {
        return $this->handle(function () use ($groupId) {
            $this->config->saveMappings(array_filter($this->config->getMappings(), fn ($mapping) => $mapping['groupId'] !== $groupId));
            return $this->stateData();
        }, true);
    }
}

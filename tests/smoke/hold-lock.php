<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
require getcwd() . '/lib/base.php';
$lock = \OCP\Server::get(\OCA\IntegrationAutoPhish\Service\SyncLock::class);
$config = \OCP\Server::get(\OCA\IntegrationAutoPhish\Service\ConfigService::class);
if (!$lock->acquire()) { throw new RuntimeException('Cannot acquire fixture lease'); }
$revision = $config->getRevision();
file_put_contents('/tmp/smoke-lock-ready', 'ready');
try {
    for ($attempt = 0; $attempt < 200 && !is_file('/tmp/smoke-resume'); $attempt++) usleep(100000);
    if (!is_file('/tmp/smoke-resume')) { throw new RuntimeException('Lifecycle probe timed out'); }
    if ($config->isCurrent($revision) || $lock->isCurrent()) { throw new RuntimeException('Removed app still appears current'); }
    if ($config->setSyncStatus(['state' => 'stale'], $revision, $lock->getToken())) { throw new RuntimeException('Stale process recreated status'); }
    $blocked = false;
    try { $config->setPendingCreation(['key' => 'mock-stale-operation']); } catch (RuntimeException) { $blocked = true; }
    if (!$blocked) { throw new RuntimeException('Stale settings request recreated local state'); }
} finally {
    $lock->release();
}
echo "Concurrent disable cancelled owner and blocked stale writes\n";

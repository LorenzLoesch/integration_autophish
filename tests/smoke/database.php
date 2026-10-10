<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
require getcwd() . '/lib/base.php';
use OCA\IntegrationAutoPhish\Service\{ConfigService, SyncLock};
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\{IDBConnection, Server};

$db = Server::get(IDBConnection::class);
$clock = Server::get(ITimeFactory::class);
$first = new SyncLock($db, $clock);
$second = new SyncLock($db, $clock);
$config = new ConfigService($db, Server::get(\OCP\Security\ICrypto::class), $first);
if (!$first->acquire() || $second->acquire()) { throw new RuntimeException('Shared mutex did not exclude overlap'); }
$config->saveConnection('https://autophish.example.test', 'fixture-company', 'mock-smoke-key', 300);
$revision = $config->getRevision();
if ($config->getConnection()['apiKey'] !== 'mock-smoke-key') { throw new RuntimeException('Encrypted connection round trip failed'); }
if (str_contains(json_encode($config->getPublicConnection()), 'mock-smoke-key')) { throw new RuntimeException('Public state disclosed a key'); }
if (!$config->setSyncStatus(['state' => 'success', 'lastSuccess' => 12345], $revision, $first->getToken())) { throw new RuntimeException('Conditional status write failed'); }
// Force expiry by replacing the first lease, then acquire a successor. A stale
// release or status callback must not unlock or overwrite the successor.
$lease = json_decode($first->getToken(), true, 512, JSON_THROW_ON_ERROR);
$lease['expires'] = time() - 1;
$db->executeStatement("UPDATE *PREFIX*appconfig SET configvalue = ? WHERE appid = ? AND configkey = 'sync_lock'", [json_encode($lease), ConfigService::APP_ID]);
if (!$second->acquire()) { throw new RuntimeException('Expired lease did not recover'); }
if ($config->setSyncStatus(['state' => 'stale'], $revision, $first->getToken())) { throw new RuntimeException('Stale owner overwrote status'); }
$first->release();
if (!$second->isCurrent()) { throw new RuntimeException('Stale owner released successor lease'); }
$second->release();
if ($config->getStatus()['state'] !== 'success') { throw new RuntimeException('Expected status lost'); }
echo "Real database encrypted-state/CAS/overlap/expired-owner checks passed\n";

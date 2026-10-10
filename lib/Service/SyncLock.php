<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);
namespace OCA\IntegrationAutoPhish\Service;

use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IDBConnection;

/** Small shared app mutex: an owner can never release a successor's lease. */
class SyncLock {
    private const APP_ID = 'integration_autophish';
    private const TTL = 600;
    private ?string $owned = null;
    private int $expires = 0;

    public function __construct(private IDBConnection $db, private ITimeFactory $time) {}

    private function read(): ?string {
        $result = $this->db->executeQuery(
            "SELECT configvalue FROM *PREFIX*appconfig WHERE appid = :app AND configkey = 'sync_lock'",
            ['app' => self::APP_ID],
        );
        $row = $result->fetch();
        $result->closeCursor();
        return $row === false ? null : $row['configvalue'];
    }

    public function acquire(): bool {
        if ($this->owned !== null) {
            return false;
        }
        $previous = $this->read();
        if ($previous === null) {
            return false;
        }
        $lease = json_decode($previous, true, 512, JSON_THROW_ON_ERROR);
        $now = $this->time->getTime();
        if (isset($lease['owner']) && ($lease['expires'] ?? PHP_INT_MAX) > $now) {
            return false;
        }
        $expires = $now + self::TTL;
        $owned = json_encode(['owner' => bin2hex(random_bytes(32)), 'expires' => $expires], JSON_THROW_ON_ERROR);
        // Derived tables avoid MySQL's restriction on reading the UPDATE target table.
        $changed = $this->db->executeStatement(
            "UPDATE *PREFIX*appconfig SET configvalue = :value WHERE appid = :app AND configkey = 'sync_lock' AND configvalue = :previous " .
            "AND EXISTS (SELECT 1 FROM (SELECT configvalue FROM *PREFIX*appconfig WHERE appid = :enabledApp AND configkey = 'enabled' LIMIT 1) active_app WHERE configvalue = 'yes') " .
            "AND EXISTS (SELECT 1 FROM (SELECT configvalue FROM *PREFIX*appconfig WHERE appid = :stateApp AND configkey = 'state' LIMIT 1) app_state)",
            ['value' => $owned, 'app' => self::APP_ID, 'previous' => $previous, 'enabledApp' => self::APP_ID, 'stateApp' => self::APP_ID],
        );
        if ($changed !== 1) {
            return false;
        }
        $this->owned = $owned;
        $this->expires = $expires;
        return true;
    }

    public function isCurrent(): bool {
        return $this->owned !== null && $this->time->getTime() < $this->expires && $this->read() === $this->owned;
    }

    /** Local token used only for atomic conditional state writes. */
    public function getToken(): string {
        return $this->owned ?? '';
    }

    public function release(): void {
        if ($this->owned === null) {
            return;
        }
        $owned = $this->owned;
        $this->owned = null;
        $this->expires = 0;
        // UPDATE only: an uninstall or a different owner makes release a harmless no-op.
        $this->db->executeStatement(
            "UPDATE *PREFIX*appconfig SET configvalue = '{}' WHERE appid = :app AND configkey = 'sync_lock' AND configvalue = :owned",
            ['app' => self::APP_ID, 'owned' => $owned],
        );
    }
}

<?php
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);
namespace OCA\IntegrationAutoPhish\Tests;

use OCP\DB\IResult;
use OCP\IDBConnection;
use PHPUnit\Framework\MockObject\MockObject;

/** Deterministic app-local CAS fixture; real SQL is exercised by runtime smoke tests. */
class FakeConfigDatabase {
    public array $rows;
    public ?\Closure $beforeUpdate = null;
    public readonly IDBConnection&MockObject $db;
    public function __construct(IDBConnection&MockObject $db, \Closure $resultFactory) {
        $this->rows = ['enabled' => 'yes', 'state' => json_encode(['revision' => 'initial-revision', 'connection' => [], 'interval' => 3600, 'mappings' => [], 'status' => [], 'pendingCreation' => []], JSON_THROW_ON_ERROR), 'sync_lock' => '{}'];
        $this->db = $db;
        $this->db->method('executeQuery')->willReturnCallback(function ($sql, $params) use ($resultFactory) {
            $result = $resultFactory();
            $rows = [];
            foreach (['state', 'enabled'] as $key) { if (isset($this->rows[$key])) { $rows[] = ['configkey' => $key, 'configvalue' => $this->rows[$key]]; } }
            if (str_contains($sql, "configkey = 'sync_lock'")) {
                $result->method('fetch')->willReturn(isset($this->rows['sync_lock']) ? ['configvalue' => $this->rows['sync_lock']] : false);
            }
            $result->method('fetchAll')->willReturn($rows);
            $result->method('closeCursor')->willReturn(true);
            return $result;
        });
        $this->db->method('executeStatement')->willReturnCallback(function ($sql, $params): int {
            if ($this->beforeUpdate !== null) { ($this->beforeUpdate)(); }
            if (str_contains($sql, "configkey = 'sync_lock'") && !isset($params['expectedLock'])) {
                if (isset($params['owned'])) {
                    if (($this->rows['sync_lock'] ?? null) !== $params['owned']) { return 0; }
                    $this->rows['sync_lock'] = '{}'; return 1;
                }
                if (($this->rows['enabled'] ?? '') !== 'yes' || !isset($this->rows['state']) || ($this->rows['sync_lock'] ?? null) !== $params['previous']) { return 0; }
                $this->rows['sync_lock'] = $params['value']; return 1;
            }
            if (($this->rows['enabled'] ?? '') !== 'yes' || ($this->rows['state'] ?? null) !== $params['previous'] || ($this->rows['sync_lock'] ?? '') !== $params['expectedLock']) { return 0; }
            $this->rows['state'] = $params['value']; return 1;
        });
    }
    public function state(): array { return json_decode($this->rows['state'], true, 512, JSON_THROW_ON_ERROR); }
}

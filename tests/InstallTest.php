<?php
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);
namespace OCA\IntegrationAutoPhish\Tests;
use OCA\IntegrationAutoPhish\Migration\Install;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
class InstallTest extends TestCase {
    public function testInstallAndUpgradeSeedOnlyMissingLocalRows(): void {
        $rows = [];
        $db = $this->createMock(IDBConnection::class);
        $db->method('insertIgnoreConflict')->willReturnCallback(function ($table, $values) use (&$rows): int {
            self::assertSame('appconfig', $table);
            self::assertSame('integration_autophish', $values['appid']);
            $key = $values['configkey'];
            if (isset($rows[$key])) { return 0; }
            $rows[$key] = $values['configvalue']; return 1;
        });
        $install = new Install($db);
        $install->run($this->createMock(IOutput::class));
        $state = json_decode($rows['state'], true, 512, JSON_THROW_ON_ERROR);
        self::assertSame([], $state['connection']);
        self::assertSame([], $state['mappings']);
        self::assertNotSame('', $state['revision']);
        self::assertSame('{}', $rows['sync_lock']);
        $rows['state'] = '{"revision":"preserve-existing-settings"}';
        $before = $rows;
        $install->run($this->createMock(IOutput::class));
        self::assertSame($before, $rows);
    }
}

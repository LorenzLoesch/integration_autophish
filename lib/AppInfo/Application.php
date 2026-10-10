<?php
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);
namespace OCA\IntegrationAutoPhish\AppInfo;
use OCP\AppFramework\App;
class Application extends App {
    public const APP_ID = 'integration_autophish';
    public function __construct(array $urlParams = []) { parent::__construct(self::APP_ID, $urlParams); }
}

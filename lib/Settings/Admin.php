<?php
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);
namespace OCA\IntegrationAutoPhish\Settings;
use OCA\IntegrationAutoPhish\Service\ConfigService;
use OCP\Settings\ISettings;
use OCP\AppFramework\Http\TemplateResponse;
class Admin implements ISettings {
    public function __construct(private ConfigService $config) {}
    public function getForm(): TemplateResponse { return new TemplateResponse(ConfigService::APP_ID, 'admin', [], ''); }
    public function getSection(): string { return 'additional'; }
    public function getPriority(): int { return 70; }
}

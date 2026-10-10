<?php
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\IntegrationAutoPhish\Service;

class ApiException extends \RuntimeException {
    public function __construct(public readonly string $category, string $message, public readonly int $httpStatus = 0) {
        parent::__construct($message);
    }
}

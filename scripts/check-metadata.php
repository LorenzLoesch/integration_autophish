<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
$xml = simplexml_load_file(__DIR__ . '/../appinfo/info.xml');
if (!$xml || (string)$xml->id !== 'integration_autophish' || (string)$xml->namespace !== 'IntegrationAutoPhish'
    || !preg_match('/^\d+\.\d+\.\d+$/', (string)$xml->version)
    || (string)$xml->licence !== 'AGPL-3.0-or-later'
    || (string)$xml->dependencies->nextcloud['min-version'] !== '32'
    || (string)$xml->dependencies->nextcloud['max-version'] !== '32'
    || !is_file(__DIR__ . '/../LICENSE') || !is_file(__DIR__ . '/../license.md')) {
    fwrite(STDERR, "Invalid app metadata or missing license\n");
    exit(1);
}
echo "Metadata validated\n";

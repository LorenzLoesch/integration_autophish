<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
$path = $argv[1] ?? '';
if (!is_file($path)) { fwrite(STDERR, "Archive missing\n"); exit(1); }
$path = realpath($path);
$archive = new PharData($path);
$files = [];
foreach (new RecursiveIteratorIterator($archive) as $file) {
    $name = substr($file->getPathname(), strlen('phar://' . $path) + 1);
    if (!preg_match('#^integration_autophish/(appinfo|lib|templates|js|css|img)/[^.].*|^integration_autophish/(LICENSE|license\.md|README\.md)$#', $name)
        || preg_match('#(^|/)(vendor|tests|fixtures|\.git|node_modules|\.env)(/|$)#', $name)
        || str_contains($name, '..') || $file->isLink()) {
        fwrite(STDERR, "Disallowed archive entry: $name\n"); exit(1);
    }
    $files[] = $name;
}
foreach (['appinfo/info.xml', 'appinfo/routes.php', 'lib/AppInfo/Application.php', 'LICENSE', 'license.md', 'README.md'] as $required) {
    if (!in_array('integration_autophish/' . $required, $files, true)) {
        fwrite(STDERR, "Required archive file missing: $required\n"); exit(1);
    }
}
echo 'Artifact validated (' . count($files) . " runtime files)\n";

<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
return ['routes' => [
    ['name' => 'settings#state', 'url' => '/settings', 'verb' => 'GET'],
    ['name' => 'settings#save', 'url' => '/settings', 'verb' => 'PUT'],
    ['name' => 'settings#discover', 'url' => '/settings/discover', 'verb' => 'POST'],
    ['name' => 'settings#clearPendingCreation', 'url' => '/settings/pending-creation', 'verb' => 'DELETE'],
    ['name' => 'settings#addMapping', 'url' => '/settings/mappings', 'verb' => 'POST'],
    ['name' => 'settings#updateMapping', 'url' => '/settings/mappings', 'verb' => 'PUT'],
    ['name' => 'settings#removeMapping', 'url' => '/settings/mappings', 'verb' => 'DELETE'],
]];

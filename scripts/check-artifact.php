<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Retain the original entry point for callers; Python checks directory entries and hardlinks too.
$args = array_map('escapeshellarg', array_slice($argv, 1));
passthru('python3 ' . escapeshellarg(__DIR__ . '/check-artifact.py') . ' ' . implode(' ', $args), $status);
exit($status);

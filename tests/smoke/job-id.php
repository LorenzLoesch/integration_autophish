<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
require getcwd() . '/lib/base.php';
$jobs = \OCP\Server::get(\OCP\BackgroundJob\IJobList::class);
$list = $jobs->getJobs(\OCA\IntegrationAutoPhish\BackgroundJob\SyncJob::class, null, 0);
if (count($list) !== 1) { throw new RuntimeException('Expected one app job'); }
echo $list[0]->getId();

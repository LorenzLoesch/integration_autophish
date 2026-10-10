<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);
namespace OCA\IntegrationAutoPhish\Command;

use OCA\IntegrationAutoPhish\Service\SyncRunner;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class SyncCommand extends Command {
    public function __construct(private SyncRunner $runner) {
        parent::__construct('integration_autophish:sync');
    }

    protected function configure(): void {
        $this->setDescription('Synchronize enabled Nextcloud group mappings with AutoPhish target lists.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int {
        $status = $this->runner->run();
        $output->writeln('AutoPhish synchronization: ' . $status['state']);
        if (!empty($status['error'])) {
            $output->writeln($status['error']);
        }
        if (isset($status['added'])) {
            $output->writeln(sprintf('Added: %d; removed: %d; skipped emails: %d; duplicate emails: %d.',
                $status['added'], $status['removed'], $status['skipped'], $status['duplicates']));
        }
        return $status['state'] === 'success' ? self::SUCCESS : self::FAILURE;
    }
}

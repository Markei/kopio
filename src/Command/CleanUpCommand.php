<?php

declare(strict_types=1);
namespace App\Command;

use Psr\Log\LoggerInterface;

use App\MessageSender;
use App\Backup\AbstractBackup;

use Symfony\Component\Console\Output\OutputInterface;

class CleanUpCommand extends BaseCommand
{
    protected static $defaultName = 'app:cleanup';

    protected function doExecute(AbstractBackup $backupJob, MessageSender $messageSender, OutputInterface $output, LoggerInterface $logger): bool
    {
        $backupJob->setType('Cleanup');

        try {
            $logger->info(
                'Starting cleanup', ['profileName' => $backupJob->getName()]
            );

            $backupJob->cleanUp();

            $logger->info(
                'Finished cleanup', ['profileName' => $backupJob->getName()]
            );

            $messageSender->sendSuccess();

            return true;
        } catch (\Exception $e) {
            $backupJob->setException($e);

            $logger->notice(
                '[ERROR] Cleanup failed', ['profileName' => $backupJob->getName()]
            );

            $output->writeln(
                '[Cleanup]' . ' ' .  $backupJob->getName() . ' failed with exception: ' . $e->getMessage()
            ); 

            try {
                $messageSender->sendFailure();
            } catch (\Exception $e) {
                $logger->notice(
                    '[ERROR] Failed sending notification with exception: ' . get_class($e) . ' and message ' . $e->getMessage(), ['profileName' => $backupJob->getName()]
                );
            }

            return false;
        }
    }
}
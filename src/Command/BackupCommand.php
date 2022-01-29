<?php

declare(strict_types=1);
namespace App\Command;

use Psr\Log\LoggerInterface;

use App\MessageSender;
use App\Backup\AbstractBackup;

use Symfony\Component\Console\Output\OutputInterface;

class BackupCommand extends BaseCommand
{
    protected static $defaultName = 'app:create-backup';

    protected function doExecute(AbstractBackup $backupJob, MessageSender $messageSender, OutputInterface $output, LoggerInterface $logger): bool
    {
        try {
            $logger->info('Creating backup', ['profileName' => $backupJob->getName()]);

            $output->writeln('Checking and creating destination directories for the backup');
            $backupJob->prepareBackup();
            
            $output->writeln('Checking if all information is defined in de yaml profiles file');
            $backupJob->verifyConfig();

            if (!isset($parsedFile['SCP'])) {
                $output->writeln('Testing connecting source');
                $backupJob->checkSource();
            }

            $output->writeln('Creating the backup');
            $backupJob->executeBackup();

            $output->writeln('Finished backup');

            try {
                $messageSender->sendSuccess();
            } catch (\Exception $e) {
                $logger->notice(
                    '[ERROR] Failed sending notification with exception: ' . get_class($e) . ' and message ' . $e->getMessage(), ['profileName' =>  $backupJob->getName()]
                );
            }

            $logger->info(
                '[SUCCESS] Succesfully created backups', ['profileName' => $backupJob->getName()]
            );

            return true;
        } catch (\Exception $e) {
            $output->writeln(
                PHP_EOL . '[ERROR] ' . get_class($e) .': ' . $e->getMessage()
            );

            $backupJob->setException($e);

            $logger->notice(
                '[ERROR] Failed creating backup with exception: ' . get_class($e) . ' and message ' . $e->getMessage(), ['profileName' => $backupJob->getName()]
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
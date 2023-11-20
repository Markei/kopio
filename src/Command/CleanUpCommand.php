<?php

declare(strict_types=1);

namespace App\Command;

use App\Helper\Logger;
use App\Profile\AbstractNotification;
use App\Retention\RetentionInterface;
use App\Target\TargetInterface;
use DateTime;
use ReflectionClass;
use RuntimeException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\String\Slugger\AsciiSlugger;
use Symfony\Component\Yaml\Yaml;

#[AsCommand('app:cleanup', 'Clean up old backups')]
class CleanUpCommand extends BaseCommand
{
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $slugger = new AsciiSlugger('en');
        $commandStart = new DateTime();
        $allSuccessfull = true;

        $paths = $this->getBackupProfilesFromPath($input->getArgument('path'));

        $baseLogger = new Logger($output, $this->logger, []);

        foreach ($paths as $path) {
            $baseLogger->info('Found profile file', ['path' => $path]);
            try {
                $runStart = new \DateTime();

                $metaData = [
                    'profilePath' => realpath($path),
                    'commandStart' => $commandStart->format('c'),
                    'runStart' => $runStart->format('c')
                ];

                $profile = $this->readProfile($baseLogger, $path, $metaData);
                $runId = $this->getRunId($baseLogger, $profile, $commandStart, $runStart, $metaData);
                $profileLogger = $baseLogger->withData(['profile' => $profile->name, 'runId' => $runId]);

                if ($profile->logFile) {
                    if (file_exists(dirname($profile->logFile)) === false) {
                        mkdir(dirname($profile->logFile), 0777, true);
                    }
                    if ($profile->logFile) {
                        $profileLogger = $profileLogger->withStream(fopen($profile->logFile, 'a'));
                    }
                }

                try {
                    $targetExecutor = $this->createExecutor($profileLogger, $profile->target, 'target', TargetInterface::class, [
                        $profile->target,
                        $profile->tmp->path,
                        $runId
                    ]);
                    assert($targetExecutor instanceof TargetInterface);
                    $profileLogger->debug('Target component created successfully', []);

                    $profileLogger->info('Target component execution', []);
                    $metaData['targetExecutorStart'] = date('c');
                    $listOfBackups = $targetExecutor->list($profileLogger);
                    $metaData['targetExecutorEnd'] = date('c');
                    $metaData['targetExecutorDuration'] = strtotime($metaData['targetExecutorEnd']) - strtotime($metaData['targetExecutorStart']);
                    $profileLogger->info('Target component finished', ['backups' => array_keys($listOfBackups)]);

                    $retentionExecutor = $this->createExecutor($profileLogger, $profile->retention, 'retention', RetentionInterface::class, [
                        $profile->retention,
                        $runId
                    ]);

                    $profileLogger->info('Retention component execution', []);
                    $backupsToRemove = $retentionExecutor->nominate($listOfBackups);
                    $profileLogger->info('Retention component finished', ['toRemove' => $backupsToRemove]);

                    $profileLogger->info('Target component remove execution', []);
                    $targetExecutor->remove($profileLogger, $backupsToRemove);
                    $profileLogger->info('Target component remove finisihed', []);

                    $profileLogger->info('Profile succeed, start sending notifications', []);
                    $this->sendNotifications($profileLogger, $profile, $runId, AbstractNotification::ON_SUCCESS, $metaData);
                    $profileLogger->info('Notifications send', []);
                } catch (\Exception $e) {
                    $profileLogger->info('Profile failed, start sending notifications', []);
                    $this->sendNotifications($profileLogger, $profile, $runId, AbstractNotification::ON_FAILURE, [
                        'e' => get_class($e),
                        'msg' => $e->getMessage(),
                        'file' => $e->getFile() . ':' . $e->getLine(),
                        'trace' => $e->getTraceAsString()
                    ]);
                    $profileLogger->info('Notifications send', []);
                    throw $e;
                }
            } catch (\Exception $e) {
                $allSuccessfull = false;
                $baseLogger->error('Error while executing profile', ['e' => get_class($e), 'msg' => $e->getMessage()]);
            }
        }

        return $allSuccessfull ? self::SUCCESS : self::FAILURE;
    }
}
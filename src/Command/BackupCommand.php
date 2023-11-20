<?php

declare(strict_types=1);

namespace App\Command;

use App\Helper\Logger;
use App\Profile\AbstractNotification;
use App\Source\SourceInterface;
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

#[AsCommand('app:backup', 'Create a backup')]
class BackupCommand extends BaseCommand
{
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $commandStart = new DateTime();
        $allSuccessfull = true;

        $paths = $this->getBackupProfilesFromPath($input->getArgument('path'));

        $baseLogger = new Logger($output, $this->logger, []);

        $fs = new Filesystem();

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
                    $profileLogger->debug('Prepare tmp location', ['tmp' => $profile->tmp->path]);
                    $fs->mkdir($profile->tmp->path, $profile->tmp->mode);
                    $profileLogger->debug('Tmp location prepared', []);

                    $executor = $this->createExecutor($profileLogger, $profile->source, 'source', SourceInterface::class, [
                        $profile->source,
                        $profile->tmp->path,
                        $runId
                    ]);

                    $profileLogger->info('Source component execution', []);
                    $metaData['sourceExecutorStart'] = date('c');
                    $tmpFiles = $executor->execute($profileLogger);
                    $metaData['files'] = array_values($tmpFiles);
                    $metaData['sourceExecutorEnd'] = date('c');
                    $metaData['sourceExecutorDuration'] = strtotime($metaData['sourceExecutorEnd']) - strtotime($metaData['sourceExecutorStart']);
                    $profileLogger->info('Source component finished', []);
                    $profileLogger->debug('Files writen in tmp', ['tmpFiles' => $tmpFiles]);

                    // calculate the size of the backup
                    $size = 0.0;
                    foreach (array_keys($tmpFiles) as $tmpFileName) {
                        $size += (float) filesize($profile->tmp->path . DIRECTORY_SEPARATOR . $tmpFileName);
                    }
                    $metaData['size'] = $size;

                    $executor = $this->createExecutor($profileLogger, $profile->target, 'target', TargetInterface::class, [
                        $profile->target,
                        $runId
                    ]);

                    $profileLogger->info('Target component execution', []);
                    $metaData['targetExecutorStart'] = date('c');
                    $executor->copy($profileLogger, $profile->tmp->path, $tmpFiles);
                    $metaData['targetExecutorEnd'] = date('c');
                    $metaData['targetExecutorDuration'] = strtotime($metaData['targetExecutorEnd']) - strtotime($metaData['targetExecutorStart']);
                    $executor->writeMetaData($profileLogger, Yaml::dump($metaData));
                    $profileLogger->info('Target component finished', []);

                    $profileLogger->info('Profile succeed, start sending notifications', []);
                    $this->sendNotifications($profileLogger, $profile, $runId, AbstractNotification::ON_SUCCESS, $metaData);
                    $profileLogger->info('Notifications send', []);
                } catch (\Exception $e) {
                    $profileLogger->info('Profile failed, start sending notifications', [
                        'e' => get_class($e),
                        'msg' => $e->getMessage(),
                        'file' => $e->getFile() . ':' . $e->getLine(),
                        'trace' => $e->getTraceAsString()
                    ]);
                    $this->sendNotifications($baseLogger, $profile, $runId, AbstractNotification::ON_FAILURE, [
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
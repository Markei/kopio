<?php

declare(strict_types=1);

namespace App\Target;

use App\Helper\Logger;
use App\Profile\AbstractTarget;
use App\Profile\Target\FilesystemTarget;
use InvalidArgumentException;
use Symfony\Component\Filesystem\Filesystem as SymfonyFilesystem;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Yaml\Yaml;

class Filesystem implements TargetInterface
{
    private FilesystemTarget $config;

    public function __construct(
        AbstractTarget $config,
        private string $runId
    )
    {
        if (($config instanceof FilesystemTarget) === false) {
            throw new InvalidArgumentException('Config should be instance of FilesystemTarget');
        }
        $this->config = $config;
    }

    public function copy(Logger $logger, string $tmpPath, array $files): void
    {
        $fs = new SymfonyFilesystem();

        // create target directory
        $fs->mkdir($this->config->path);

        // create runId directory in target
        $fs->mkdir($this->config->path . DIRECTORY_SEPARATOR . $this->runId);

        foreach ($files as $tmpFile => $fileName) {
            if ($this->config->useCopy) {
                $fs->copy(
                    $tmpPath . DIRECTORY_SEPARATOR . $tmpFile,
                    $this->config->path . DIRECTORY_SEPARATOR . $this->runId . DIRECTORY_SEPARATOR . $fileName
                );
                $fs->remove($tmpPath . DIRECTORY_SEPARATOR . $tmpFile);
            } else {
                $fs->rename(
                    $tmpPath . DIRECTORY_SEPARATOR . $tmpFile,
                    $this->config->path . DIRECTORY_SEPARATOR . $this->runId . DIRECTORY_SEPARATOR . $fileName
                );
            }
        }
    }

    public function writeMetaData(Logger $logger, string $metaData): void
    {
        file_put_contents(
            $this->config->path . DIRECTORY_SEPARATOR . $this->runId . DIRECTORY_SEPARATOR . '_meta.yaml',
            $metaData
        );
    }

    public function list(Logger $logger): array
    {
        $yaml = new Yaml();

        $finder = new Finder();
        $backups = [];
        foreach ($finder->in($this->config->path)->depth(1)->name('_meta.yaml') as $metaDataFile) {
            $backups[$metaDataFile->getPath()] = $yaml->parseFile($metaDataFile->getPathname());
        }

        return $backups;
    }

    public function remove(Logger $logger, array $backupsToRemove): void
    {
        $fs = new SymfonyFilesystem();
        foreach ($backupsToRemove as $backupLocation) {
            $fs->remove($backupLocation);
        }
    }
}
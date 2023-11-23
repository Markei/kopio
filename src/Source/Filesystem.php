<?php

declare(strict_types=1);

namespace App\Source;

use App\Helper\Logger;
use App\Profile\AbstractSource;
use App\Profile\Source\FilesystemSource;
use App\Profile\Source\FilesystemSource\Location;
use InvalidArgumentException;
use RuntimeException;
use Symfony\Component\Process\Process;

class Filesystem implements SourceInterface
{
    private FilesystemSource $config;

    public function __construct(
        AbstractSource $config,
        private string $tmpPath,
        private string $runId
    )
    {
        if (($config instanceof FilesystemSource) === false) {
            throw new InvalidArgumentException('Config should be instance of FilesystemSource');
        }
        $this->config = $config;
    }

    public function execute(Logger $logger): array
    {
        $filenames = [];

        foreach ($this->config->locations as $location) {
            assert($location instanceof Location);

            $filename = uniqid() . '.tar.gz';

            $command = ['tar'];
            $command[] = '--create';
            $command[] = '--gzip';
            //$command[] = '--verbose';
            $command[] = '--file=' . $filename;
            $command[] = '--directory=' . dirname($location->path);
            $command[] = basename($location->path);

            $process = new Process($command, $this->tmpPath, timeout: 300);
            $logger->debug('Internal executable command', ['line' => $process->getCommandLine()]);
            $process->run(function ($type, $buffer) use ($logger) {
                $logger->debug('Internal executable output', ['type' => $type, 'buffer' => $buffer]);
            });
            if ($process->isSuccessful() === false) {
                if ($process->getExitCode() === 1) {
                    $logger->error('Warnings during backup, continue');
                } else {
                    $logger->error('Internal executable exit code', ['code' => $process->getExitCode()]);
                    throw new RuntimeException('Source failed during internal executable run');
                }
            }

            $filenames[$filename] = $location->name . '.tar.gz';
        }

        // return list of writed filenames in tmp directory
        return $filenames;
    }
}
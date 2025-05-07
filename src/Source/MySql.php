<?php

declare(strict_types=1);

namespace App\Source;

use App\Helper\Logger;
use App\Profile\AbstractSource;
use App\Profile\Source\MySqlSource;
use InvalidArgumentException;
use RuntimeException;
use Symfony\Component\Process\Process;

class MySql implements SourceInterface
{
    private MySqlSource $config;

    public function __construct(
        AbstractSource $config,
        private string $tmpPath,
        private string $runId
    )
    {
        if (($config instanceof MySqlSource) === false) {
            throw new InvalidArgumentException('Config should be instance of MySqlSource');
        }
        $this->config = $config;
    }

    public function execute(Logger $logger): array
    {
        $filename = $this->runId . '.' . 'dump.sql';

        $command = [$this->config->executable];
        $command[] = '--host=' . $this->config->host;
        $command[] = '--port=' . $this->config->port;
        if ($this->config->username) {
            $command[] = '--user=' . $this->config->username;
        }
        if ($this->config->password) {
            $command[] = '--password=' . $this->config->password;
        }
        if ($this->config->enableSsl) {
            $command[] = '--ssl=1';
        } else {
            $command[] = '--ssl=0';
        }
        $command[] = '--result-file=' . $filename;
        $command[] = $this->config->database;

        $process = new Process($command, $this->tmpPath, timeout: 300);
        $logger->debug('Command', ['command' => str_replace($this->config->password, '***', $process->getCommandLine())]);
        $process->run(function ($type, $buffer) use ($logger) {
            $logger->debug('Internal executable output', ['type' => $type, 'buffer' => $buffer]);
        });
        if ($process->isSuccessful() === false) {
            $logger->error('Internal executable exit code', ['code' => $process->getExitCode()]);
            throw new RuntimeException('Source failed during internal executable run');
        }

        // return list of writed filenames in tmp directory
        return [
            $filename => 'dump.sql'
        ];
    }
}
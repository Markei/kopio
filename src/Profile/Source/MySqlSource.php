<?php

declare(strict_types=1);

namespace App\Profile\Source;

use App\Profile\AbstractSource;

class MySqlSource extends AbstractSource
{
    public function __construct(
        public readonly string $host,
        public readonly int $port,
        public readonly string $username,
        public readonly string $password,
        public readonly string $database,
        public readonly string $executable,
        public readonly bool $enableSsl
    )
    {
    }

    public static function fromArray(array $data): self
    {
        // defaults
        if (empty($data['executable'])) {
            $data['executable'] = 'mariadb-dump';
        }
        if (empty($data['port'])) {
            $data['port'] = 3306;
        }

        return new self(
            $data['host'],
            $data['port'],
            $data['username'],
            $data['password'],
            $data['database'],
            $data['executable'],
            array_key_exists('enableSsl', $data) ? $data['enableSsl'] : false
        );
    }

    public function getExecutorClass(): string
    {
        return \App\Source\MySql::class;
    }
}
<?php

declare(strict_types=1);

namespace App\Profile\Target;

use App\Profile\AbstractTarget;

class FilesystemTarget extends AbstractTarget
{
    public function __construct(
        public readonly string $path,
        public readonly bool $useCopy
    )
    {
    }

    public static function fromArray(array $data): self
    {
        if (isset($data['useCopy']) === false) {
            $data['useCopy'] = false;
        }

        return new self($data['path'], $data['useCopy']);
    }

    public function getExecutorClass(): string
    {
        return \App\Target\Filesystem::class;
    }
}
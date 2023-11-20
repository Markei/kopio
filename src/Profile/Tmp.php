<?php

declare(strict_types=1);

namespace App\Profile;

class Tmp
{
    public function __construct(
        public readonly string $path,
        public readonly int $mode
    )
    {

    }

    public static function fromArray(array $data): self
    {
        $data['mode'] = $data['mode'] ?? '0777';

        return new self($data['path'], intval($data['mode']));
    }
}
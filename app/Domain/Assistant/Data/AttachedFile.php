<?php

declare(strict_types=1);

namespace App\Domain\Assistant\Data;

final readonly class AttachedFile
{
    public function __construct(
        public string $path,
        public string $content,
    ) {}
}

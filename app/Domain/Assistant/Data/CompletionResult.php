<?php

declare(strict_types=1);

namespace App\Domain\Assistant\Data;

final readonly class CompletionResult
{
    public function __construct(
        public string $content,
    ) {}
}

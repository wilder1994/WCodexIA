<?php

declare(strict_types=1);

namespace App\Domain\Assistant\Data;

final readonly class CompletionRequest
{
    /**
     * @param  list<array{role: string, content: string}>  $messages
     */
    public function __construct(
        public array $messages,
    ) {}
}

<?php

declare(strict_types=1);

namespace App\Domain\Assistant\Data;

final readonly class ConversationSummary
{
    public function __construct(
        public string $id,
        public string $title,
    ) {}
}

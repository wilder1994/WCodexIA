<?php

declare(strict_types=1);

namespace App\Domain\Assistant\Data;

use App\Domain\Assistant\Enums\MessageRole;

final readonly class StoredMessage
{
    public function __construct(
        public MessageRole $role,
        public string $content,
        public ?string $id = null,
        public bool $hasImage = false,
    ) {}
}

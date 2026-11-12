<?php

declare(strict_types=1);

namespace App\Domain\Assistant\Data;

use App\Domain\Assistant\Enums\DevelopmentTask;

final readonly class DevelopmentRequest
{
    /**
     * @param  list<string>  $paths
     * @param  list<string>  $contextIds
     */
    public function __construct(
        public ?string $conversationId,
        public DevelopmentTask $task,
        public string $prompt,
        public array $paths,
        public ?string $language,
        public array $contextIds = [],
    ) {}
}

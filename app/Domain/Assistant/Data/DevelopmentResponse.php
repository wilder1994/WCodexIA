<?php

declare(strict_types=1);

namespace App\Domain\Assistant\Data;

use App\Domain\Assistant\Enums\DevelopmentTask;
use App\Domain\Security\Enums\PolicyAction;

final readonly class DevelopmentResponse
{
    /**
     * @param  list<string>  $appliedRules
     */
    public function __construct(
        public string $conversationId,
        public DevelopmentTask $task,
        public PolicyAction $action,
        public string $message,
        public array $appliedRules,
    ) {}
}

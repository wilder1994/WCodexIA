<?php

declare(strict_types=1);

namespace App\Domain\Security\Data;

use App\Domain\Security\Enums\PolicyAction;

final readonly class PolicyVerdict
{
    public function __construct(
        public string $rule,
        public PolicyAction $action,
        public string $content,
        public string $summary,
    ) {}
}

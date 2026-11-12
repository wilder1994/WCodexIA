<?php

declare(strict_types=1);

namespace App\Domain\Security\Data;

use App\Domain\Assistant\Enums\DevelopmentTask;

final readonly class PolicySubject
{
    public function __construct(
        public string $content,
        public bool $providerIsLocal,
        public DevelopmentTask $task,
    ) {}
}

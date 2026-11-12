<?php

declare(strict_types=1);

namespace App\Domain\Agent\Data;

final readonly class CommandResult
{
    public function __construct(
        public string $output,
    ) {}
}

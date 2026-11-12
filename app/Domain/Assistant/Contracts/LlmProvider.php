<?php

declare(strict_types=1);

namespace App\Domain\Assistant\Contracts;

use App\Domain\Assistant\Data\CompletionRequest;
use App\Domain\Assistant\Data\CompletionResult;

interface LlmProvider
{
    public function name(): string;

    public function isLocal(): bool;

    public function complete(CompletionRequest $request): CompletionResult;
}

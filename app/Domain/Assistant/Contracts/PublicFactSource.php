<?php

declare(strict_types=1);

namespace App\Domain\Assistant\Contracts;

interface PublicFactSource
{
    public function lookup(string $question): string;
}

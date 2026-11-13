<?php

declare(strict_types=1);

namespace App\Domain\Assistant\Contracts;

interface ImageTextReader
{
    public function read(string $absolutePath): string;
}

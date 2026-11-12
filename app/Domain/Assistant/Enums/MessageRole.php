<?php

declare(strict_types=1);

namespace App\Domain\Assistant\Enums;

enum MessageRole: string
{
    case User = 'user';
    case Assistant = 'assistant';
}

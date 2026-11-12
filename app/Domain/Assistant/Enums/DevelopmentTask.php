<?php

declare(strict_types=1);

namespace App\Domain\Assistant\Enums;

enum DevelopmentTask: string
{
    case Explain = 'explain';
    case Review = 'review';
    case Generate = 'generate';
    case Refactor = 'refactor';
    case Test = 'test';
}

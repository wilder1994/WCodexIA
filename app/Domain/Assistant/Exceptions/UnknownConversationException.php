<?php

declare(strict_types=1);

namespace App\Domain\Assistant\Exceptions;

use RuntimeException;

final class UnknownConversationException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('La conversación no existe.');
    }
}

<?php

declare(strict_types=1);

namespace App\Domain\Security\Exceptions;

use RuntimeException;

final class PolicyDeniedException extends RuntimeException
{
    public function __construct(
        public readonly string $ruleCode,
        string $message,
    ) {
        parent::__construct($message);
    }
}

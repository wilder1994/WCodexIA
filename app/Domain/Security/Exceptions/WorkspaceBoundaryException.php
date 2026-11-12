<?php

declare(strict_types=1);

namespace App\Domain\Security\Exceptions;

use RuntimeException;

final class WorkspaceBoundaryException extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
    ) {
        parent::__construct($message);
    }
}

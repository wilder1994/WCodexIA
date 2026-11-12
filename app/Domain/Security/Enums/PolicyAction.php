<?php

declare(strict_types=1);

namespace App\Domain\Security\Enums;

enum PolicyAction: string
{
    case Allow = 'allow';
    case Redact = 'redact';
    case Deny = 'deny';
}

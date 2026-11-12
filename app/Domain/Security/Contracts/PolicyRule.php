<?php

declare(strict_types=1);

namespace App\Domain\Security\Contracts;

use App\Domain\Security\Data\PolicySubject;
use App\Domain\Security\Data\PolicyVerdict;

interface PolicyRule
{
    public function code(): string;

    public function description(): string;

    public function evaluate(PolicySubject $subject): PolicyVerdict;
}

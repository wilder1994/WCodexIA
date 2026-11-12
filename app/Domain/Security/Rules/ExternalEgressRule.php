<?php

declare(strict_types=1);

namespace App\Domain\Security\Rules;

use App\Domain\Security\Contracts\PolicyRule;
use App\Domain\Security\Data\PolicySubject;
use App\Domain\Security\Data\PolicyVerdict;
use App\Domain\Security\Enums\PolicyAction;

final readonly class ExternalEgressRule implements PolicyRule
{
    public function __construct(
        private bool $allowExternal = false,
    ) {}

    public function code(): string
    {
        return 'external_egress';
    }

    public function description(): string
    {
        return 'El código no sale de la máquina. Un proveedor externo solo se usa si se habilita de forma explícita.';
    }

    public function evaluate(PolicySubject $subject): PolicyVerdict
    {
        if ($subject->providerIsLocal || $this->allowExternal) {
            return new PolicyVerdict(
                $this->code(),
                PolicyAction::Allow,
                $subject->content,
                'El proveedor permitido está en uso.',
            );
        }

        return new PolicyVerdict(
            $this->code(),
            PolicyAction::Deny,
            $subject->content,
            'Los proveedores externos están deshabilitados.',
        );
    }
}

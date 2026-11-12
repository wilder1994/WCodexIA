<?php

declare(strict_types=1);

namespace App\Domain\Security\Services;

use App\Domain\Security\Contracts\PolicyRule;

final readonly class PolicyCatalog
{
    /**
     * @param  list<PolicyRule>  $rules
     * @param  array<string, bool>  $enabled
     */
    public function __construct(
        private array $rules,
        private array $enabled,
    ) {}

    /**
     * @return list<array{code: string, description: string, enabled: bool}>
     */
    public function all(): array
    {
        return array_map(
            fn (PolicyRule $rule): array => [
                'code' => $rule->code(),
                'description' => $rule->description(),
                'enabled' => $this->enabled[$rule->code()] ?? true,
            ],
            $this->rules,
        );
    }
}

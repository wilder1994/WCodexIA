<?php

declare(strict_types=1);

namespace App\Domain\Security\Data;

use App\Domain\Security\Enums\PolicyAction;

final readonly class PolicyDecision
{
    /**
     * @param  list<PolicyVerdict>  $verdicts
     */
    public function __construct(
        public PolicyAction $action,
        public array $verdicts,
        public string $content,
        public ?string $deniedBy = null,
    ) {}

    public function isDenied(): bool
    {
        return $this->action === PolicyAction::Deny;
    }

    /**
     * @return list<string>
     */
    public function ruleCodes(): array
    {
        return array_map(
            static fn (PolicyVerdict $verdict): string => $verdict->rule,
            $this->verdicts,
        );
    }
}

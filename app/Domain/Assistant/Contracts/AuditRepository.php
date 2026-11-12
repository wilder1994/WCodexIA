<?php

declare(strict_types=1);

namespace App\Domain\Assistant\Contracts;

use App\Domain\Security\Enums\PolicyAction;

interface AuditRepository
{
    /**
     * @param  list<string>  $ruleCodes
     */
    public function record(
        ?string $conversationId,
        PolicyAction $action,
        string $provider,
        array $ruleCodes,
        string $summary,
    ): void;
}

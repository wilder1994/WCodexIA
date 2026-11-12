<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Domain\Assistant\Contracts\AuditRepository;
use App\Domain\Security\Enums\PolicyAction;
use App\Models\AuditLog;

final class EloquentAuditRepository implements AuditRepository
{
    public function record(
        ?string $conversationId,
        PolicyAction $action,
        string $provider,
        array $ruleCodes,
        string $summary,
    ): void {
        AuditLog::query()->create([
            'conversation_id' => $conversationId,
            'action' => $action->value,
            'provider' => $provider,
            'rules' => $ruleCodes,
            'summary' => $summary,
        ]);
    }
}

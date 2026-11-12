<?php

declare(strict_types=1);

namespace App\Domain\Assistant\Contracts;

use App\Domain\Assistant\Data\ConversationSummary;
use App\Domain\Assistant\Data\StoredMessage;
use App\Domain\Assistant\Enums\DevelopmentTask;
use App\Domain\Assistant\Enums\MessageRole;

interface ConversationRepository
{
    public function start(): string;

    public function exists(string $id): bool;

    /**
     * @return list<ConversationSummary>
     */
    public function listRecent(int $limit = 40): array;

    /**
     * @return list<StoredMessage>
     */
    public function transcript(string $id): array;

    /**
     * @return list<StoredMessage>
     */
    public function recent(string $id, int $limit): array;

    public function append(string $id, MessageRole $role, string $content, ?DevelopmentTask $task): void;
}

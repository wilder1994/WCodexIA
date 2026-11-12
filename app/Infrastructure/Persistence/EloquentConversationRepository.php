<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Domain\Assistant\Contracts\ConversationRepository;
use App\Domain\Assistant\Data\ConversationSummary;
use App\Domain\Assistant\Data\StoredMessage;
use App\Domain\Assistant\Enums\DevelopmentTask;
use App\Domain\Assistant\Enums\MessageRole;
use App\Models\Conversation;
use App\Models\Message;

final class EloquentConversationRepository implements ConversationRepository
{
    public function start(): string
    {
        $conversation = new Conversation;
        $conversation->save();

        return (string) $conversation->getKey();
    }

    public function exists(string $id): bool
    {
        return Conversation::query()->whereKey($id)->exists();
    }

    public function listRecent(int $limit = 40): array
    {
        $conversations = Conversation::query()
            ->latest('updated_at')
            ->limit($limit)
            ->get();

        if ($conversations->isEmpty()) {
            return [];
        }

        $previews = Message::query()
            ->whereIn('conversation_id', $conversations->modelKeys())
            ->where('role', MessageRole::User->value)
            ->orderBy('created_at')
            ->get()
            ->groupBy(fn (Message $message): string => (string) $message->getAttribute('conversation_id'));

        return $conversations
            ->map(function (Conversation $conversation) use ($previews): ConversationSummary {
                /** @var Message|null $first */
                $first = $previews->get((string) $conversation->getKey())?->first();
                $raw = $first instanceof Message ? (string) $first->getAttribute('content') : '';

                return new ConversationSummary(
                    (string) $conversation->getKey(),
                    $this->titleFrom($raw),
                );
            })
            ->all();
    }

    public function transcript(string $id): array
    {
        return Message::query()
            ->where('conversation_id', $id)
            ->orderBy('created_at')
            ->limit(200)
            ->get()
            ->map(fn (Message $message): StoredMessage => $this->stored($message))
            ->all();
    }

    public function recent(string $id, int $limit): array
    {
        return Message::query()
            ->where('conversation_id', $id)
            ->latest()
            ->limit($limit)
            ->get()
            ->reverse()
            ->values()
            ->map(fn (Message $message): StoredMessage => $this->stored($message))
            ->all();
    }

    public function append(string $id, MessageRole $role, string $content, ?DevelopmentTask $task): void
    {
        Message::query()->create([
            'conversation_id' => $id,
            'role' => $role->value,
            'task' => $task?->value,
            'content' => $content,
        ]);

        Conversation::query()->whereKey($id)->update([
            'updated_at' => now(),
        ]);
    }

    private function stored(Message $message): StoredMessage
    {
        return new StoredMessage(
            MessageRole::from((string) $message->getAttribute('role')),
            (string) $message->getAttribute('content'),
        );
    }

    private function titleFrom(string $content): string
    {
        $single = trim((string) preg_replace('/\s+/', ' ', $content));

        if ($single === '') {
            return 'Conversación';
        }

        if (mb_strlen($single) <= 48) {
            return $single;
        }

        return mb_substr($single, 0, 48).'…';
    }
}

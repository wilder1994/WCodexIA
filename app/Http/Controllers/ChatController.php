<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Assistant\Contracts\ConversationRepository;
use App\Domain\Assistant\Data\DevelopmentRequest;
use App\Domain\Assistant\Data\StoredMessage;
use App\Domain\Assistant\Enums\DevelopmentTask;
use App\Domain\Assistant\Exceptions\LlmUnavailableException;
use App\Domain\Assistant\Services\DevelopmentAssistant;
use App\Domain\Security\Exceptions\PolicyDeniedException;
use App\Http\Requests\ChatMessageRequest;
use App\Infrastructure\Llm\LocalModelProbe;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;

final class ChatController extends Controller
{
    public function index(ConversationRepository $conversations, LocalModelProbe $probe): View
    {
        return $this->screen($conversations, $probe, null, []);
    }

    public function show(string $conversation, ConversationRepository $conversations, LocalModelProbe $probe): View
    {
        if (! $conversations->exists($conversation)) {
            abort(404);
        }

        return $this->screen(
            $conversations,
            $probe,
            $conversation,
            $conversations->transcript($conversation),
        );
    }

    public function store(ChatMessageRequest $request, DevelopmentAssistant $assistant): RedirectResponse
    {
        if ($request->hasFile('image')) {
            return back()->withInput()->withErrors([
                'image' => 'La visión todavía no está disponible.',
            ]);
        }

        set_time_limit((int) config('wcodex.ollama.timeout') + 15);

        try {
            $conversationId = $request->validated('conversation_id');
            /** @var list<string> $contextIds */
            $contextIds = $request->validated('context_ids', []);
            $result = $assistant->handle(new DevelopmentRequest(
                conversationId: is_string($conversationId) ? $conversationId : null,
                task: DevelopmentTask::Explain,
                prompt: (string) $request->validated('prompt'),
                paths: [],
                language: null,
                contextIds: array_values($contextIds),
            ));
        } catch (PolicyDeniedException|LlmUnavailableException $exception) {
            return back()->withInput()->withErrors([
                'prompt' => $exception->getMessage(),
            ]);
        }

        return redirect()->route('chat.show', ['conversation' => $result->conversationId]);
    }

    /**
     * @param  list<StoredMessage>  $messages
     */
    private function screen(
        ConversationRepository $conversations,
        LocalModelProbe $probe,
        ?string $activeId,
        array $messages,
    ): View {
        return view('chat.index', [
            'conversations' => $conversations->listRecent(),
            'activeId' => $activeId,
            'messages' => $messages,
            'model' => (string) config('wcodex.ollama.model'),
            'modelOnline' => $probe->isReachable(),
        ]);
    }
}

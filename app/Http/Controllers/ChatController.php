<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Assistant\Contracts\ConversationRepository;
use App\Domain\Assistant\Contracts\ImageTextReader;
use App\Domain\Assistant\Data\DevelopmentRequest;
use App\Domain\Assistant\Data\StoredMessage;
use App\Domain\Assistant\Enums\DevelopmentTask;
use App\Domain\Assistant\Exceptions\LlmUnavailableException;
use App\Domain\Assistant\Services\DevelopmentAssistant;
use App\Domain\Security\Exceptions\PolicyDeniedException;
use App\Http\Requests\ChatMessageRequest;
use App\Infrastructure\Chat\LocalChatImageStore;
use App\Infrastructure\Llm\LocalModelProbe;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

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

    public function store(ChatMessageRequest $request, DevelopmentAssistant $assistant, ConversationRepository $conversations, LocalChatImageStore $images, ImageTextReader $reader): RedirectResponse
    {
        $imagePath = null;

        if ($request->hasFile('image')) {
            $uploaded = $request->file('image');

            try {
                $imagePath = $uploaded instanceof UploadedFile ? $images->store($uploaded) : null;
            } catch (RuntimeException) {
                return back()->withInput()->withErrors([
                    'image' => 'La imagen no es válida.',
                ]);
            }

            if ($imagePath === null) {
                return back()->withInput()->withErrors([
                    'image' => 'La imagen no es válida.',
                ]);
            }
        }

        $imageText = $imagePath === null
            ? null
            : $reader->read(Storage::disk('local')->path($imagePath));

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
                imagePath: $imagePath,
                imageText: $imageText,
            ));
        } catch (PolicyDeniedException|LlmUnavailableException $exception) {
            if ($imagePath !== null && ! $conversations->holdsImage($imagePath)) {
                $images->delete($imagePath);
            }

            return back()->withInput()->withErrors([
                'prompt' => $exception->getMessage(),
            ]);
        }

        return redirect()->route('chat.show', ['conversation' => $result->conversationId]);
    }

    public function image(string $conversation, string $message, ConversationRepository $conversations, LocalChatImageStore $images): Response
    {
        $path = $conversations->imagePath($conversation, $message);
        $bytes = $path === null ? null : $images->read($path);

        if ($bytes === null || $path === null) {
            abort(404);
        }

        return response($bytes, 200, [
            'Content-Type' => $images->mime($path),
            'Content-Disposition' => 'inline',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ]);
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

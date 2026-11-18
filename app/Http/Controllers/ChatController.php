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
use Symfony\Component\HttpFoundation\StreamedResponse;
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

    public function store(ChatMessageRequest $request, DevelopmentAssistant $assistant, ConversationRepository $conversations, LocalChatImageStore $images, ImageTextReader $reader): RedirectResponse|StreamedResponse
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

        set_time_limit((int) config('wcodex.ollama.timeout') + 30);

        $conversationId = $request->validated('conversation_id');
        /** @var list<string> $contextIds */
        $contextIds = $request->validated('context_ids', []);
        $developmentRequest = new DevelopmentRequest(
                conversationId: is_string($conversationId) ? $conversationId : null,
                task: DevelopmentTask::Explain,
                prompt: (string) $request->validated('prompt'),
                paths: [],
                language: null,
                contextIds: array_values($contextIds),
                imagePath: $imagePath,
                imageText: $imageText,
        );

        if ($request->headers->get('Accept') === 'application/x-ndjson') {
            return $this->streamReply($assistant, $conversations, $images, $imagePath, $developmentRequest);
        }

        try {
            $result = $assistant->handle($developmentRequest);
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

    public function destroy(string $conversation, ConversationRepository $conversations, LocalChatImageStore $images): RedirectResponse
    {
        $paths = $conversations->delete($conversation);

        if ($paths === null) {
            abort(404);
        }

        foreach ($paths as $path) {
            if (! $conversations->holdsImage($path)) {
                $images->delete($path);
            }
        }

        if (str_contains(url()->previous(), '/c/'.$conversation)) {
            return redirect()->route('chat.index');
        }

        return back();
    }

    private function streamReply(
        DevelopmentAssistant $assistant,
        ConversationRepository $conversations,
        LocalChatImageStore $images,
        ?string $imagePath,
        DevelopmentRequest $developmentRequest,
    ): StreamedResponse {
        return response()->stream(function () use ($assistant, $conversations, $images, $imagePath, $developmentRequest): void {
            ignore_user_abort(true);

            $emit = static function (array $payload): void {
                echo json_encode($payload, JSON_UNESCAPED_UNICODE)."\n";

                if (ob_get_level() > 0) {
                    ob_flush();
                }

                flush();
            };

            try {
                $result = $assistant->stream($developmentRequest, static function (string $delta) use ($emit): void {
                    $emit(['delta' => $delta]);
                });
                $emit(['conversation' => $result->conversationId]);
            } catch (PolicyDeniedException|LlmUnavailableException $exception) {
                if ($imagePath !== null && ! $conversations->holdsImage($imagePath)) {
                    $images->delete($imagePath);
                }

                $emit(['error' => $exception->getMessage()]);
            }
        }, 200, [
            'Content-Type' => 'application/x-ndjson; charset=UTF-8',
            'Cache-Control' => 'no-cache, no-store',
            'X-Accel-Buffering' => 'no',
        ]);
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

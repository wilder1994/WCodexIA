<?php

declare(strict_types=1);

namespace App\Infrastructure\Llm;

use App\Domain\Assistant\Contracts\LlmProvider;
use App\Domain\Assistant\Data\CompletionRequest;
use App\Domain\Assistant\Data\CompletionResult;
use App\Domain\Assistant\Exceptions\LlmUnavailableException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

final readonly class OllamaProvider implements LlmProvider
{
    public function __construct(
        private string $baseUrl,
        private string $model,
        private int $timeout,
    ) {}

    public function name(): string
    {
        return 'ollama';
    }

    public function isLocal(): bool
    {
        return true;
    }

    public function complete(CompletionRequest $request): CompletionResult
    {
        try {
            $response = Http::baseUrl(rtrim($this->baseUrl, '/'))
                ->timeout($this->timeout)
                ->acceptJson()
                ->post('/api/chat', [
                    'model' => $this->model,
                    'stream' => false,
                    'messages' => $request->messages,
                ]);
        } catch (ConnectionException) {
            throw new LlmUnavailableException;
        }

        if (! $response->successful()) {
            throw new LlmUnavailableException;
        }

        $content = $response->json('message.content');

        if (! is_string($content) || trim($content) === '') {
            throw new LlmUnavailableException;
        }

        return new CompletionResult($content);
    }
}

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
                ->post('/api/chat', $this->payload($request, false));
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

    public function stream(CompletionRequest $request, callable $onDelta): string
    {
        $full = app()->runningUnitTests()
            ? $this->streamFromHttpClient($request, $onDelta)
            : $this->streamAsTokensArrive($request, $onDelta);

        if (trim($full) === '') {
            throw new LlmUnavailableException;
        }

        return $full;
    }

    private function streamAsTokensArrive(CompletionRequest $request, callable $onDelta): string
    {
        $handle = curl_init(rtrim($this->baseUrl, '/').'/api/chat');

        if ($handle === false) {
            throw new LlmUnavailableException;
        }

        $pending = '';
        $full = '';
        $status = 0;

        curl_setopt_array($handle, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($this->payload($request, true), JSON_UNESCAPED_UNICODE),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/x-ndjson'],
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_BUFFERSIZE => 128,
            CURLOPT_TCP_NODELAY => true,
            CURLOPT_HEADERFUNCTION => static function ($handle, string $header) use (&$status): int {
                if (preg_match('#^HTTP/\S+\s+(\d+)#', $header, $match) === 1) {
                    $status = (int) $match[1];
                }

                return strlen($header);
            },
            CURLOPT_WRITEFUNCTION => function ($handle, string $chunk) use (&$pending, &$full, $onDelta): int {
                $pending .= $chunk;
                $full = $this->emitCompleteLines($pending, $full, $onDelta);

                return strlen($chunk);
            },
        ]);

        $completed = curl_exec($handle);
        $status = $status !== 0 ? $status : (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        curl_close($handle);

        if ($completed === false || $status < 200 || $status >= 300) {
            throw new LlmUnavailableException;
        }

        if (trim($pending) !== '') {
            $pending .= "\n";
            $full = $this->emitCompleteLines($pending, $full, $onDelta);
        }

        return $full;
    }

    private function streamFromHttpClient(CompletionRequest $request, callable $onDelta): string
    {
        try {
            $response = Http::baseUrl(rtrim($this->baseUrl, '/'))
                ->withOptions(['stream' => true])
                ->timeout($this->timeout)
                ->acceptJson()
                ->post('/api/chat', $this->payload($request, true));
        } catch (ConnectionException) {
            throw new LlmUnavailableException;
        }

        if (! $response->successful()) {
            throw new LlmUnavailableException;
        }

        $resource = $response->toPsrResponse()->getBody()->detach();

        if (! is_resource($resource)) {
            throw new LlmUnavailableException;
        }

        rewind($resource);
        $pending = stream_get_contents($resource) ?: '';
        $full = '';

        return $this->emitCompleteLines($pending, $full, $onDelta);
    }

    private function emitCompleteLines(string &$pending, string $full, callable $onDelta): string
    {
        while (($break = strpos($pending, "\n")) !== false) {
            $line = trim(substr($pending, 0, $break));
            $pending = substr($pending, $break + 1);

            if ($line === '') {
                continue;
            }

            $payload = json_decode($line, true);
            $piece = is_array($payload) ? ($payload['message']['content'] ?? '') : '';

            if (! is_string($piece) || $piece === '') {
                continue;
            }

            $full .= $piece;
            $onDelta($piece);
        }

        return $full;
    }

    /**
     * @return array{model: string, stream: bool, keep_alive: int, messages: list<array{role: string, content: string}>}
     */
    private function payload(CompletionRequest $request, bool $stream): array
    {
        return [
            'model' => $this->model,
            'stream' => $stream,
            'keep_alive' => -1,
            'messages' => $request->messages,
        ];
    }
}

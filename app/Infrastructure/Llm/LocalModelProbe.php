<?php

declare(strict_types=1);

namespace App\Infrastructure\Llm;

use Illuminate\Support\Facades\Http;
use Throwable;

final readonly class LocalModelProbe
{
    public function isReachable(): bool
    {
        try {
            $response = Http::timeout(2)
                ->acceptJson()
                ->get(rtrim((string) config('wcodex.ollama.base_url'), '/').'/api/tags');
        } catch (Throwable) {
            return false;
        }

        if (! $response->successful()) {
            return false;
        }

        $models = $response->json('models');

        if (! is_array($models)) {
            return false;
        }

        $wanted = (string) config('wcodex.ollama.model');

        foreach ($models as $model) {
            if (! is_array($model)) {
                continue;
            }

            $name = (string) ($model['name'] ?? '');

            if ($name === $wanted || str_starts_with($name, $wanted)) {
                return true;
            }
        }

        return false;
    }
}

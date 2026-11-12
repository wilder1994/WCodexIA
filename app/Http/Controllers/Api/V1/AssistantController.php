<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Assistant\Data\DevelopmentRequest;
use App\Domain\Assistant\Enums\DevelopmentTask;
use App\Domain\Assistant\Services\DevelopmentAssistant;
use App\Http\Controllers\Controller;
use App\Http\Requests\DevelopmentCompletionRequest;
use Illuminate\Http\JsonResponse;

final class AssistantController extends Controller
{
    public function __invoke(
        DevelopmentCompletionRequest $request,
        DevelopmentAssistant $assistant,
    ): JsonResponse {
        /** @var list<string> $paths */
        $paths = $request->validated('paths', []);
        $language = $request->validated('language');

        $result = $assistant->handle(new DevelopmentRequest(
            conversationId: $request->validated('conversation_id'),
            task: DevelopmentTask::from((string) $request->validated('task')),
            prompt: (string) $request->validated('prompt'),
            paths: array_values($paths),
            language: is_string($language) ? $language : null,
        ));

        return response()->json([
            'conversation_id' => $result->conversationId,
            'task' => $result->task->value,
            'action' => $result->action->value,
            'message' => $result->message,
            'applied_rules' => $result->appliedRules,
        ]);
    }
}

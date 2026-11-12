<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class ChatMessageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'conversation_id' => ['nullable', 'uuid', 'exists:conversations,id'],
            'context_ids' => ['sometimes', 'array', 'max:3'],
            'context_ids.*' => ['uuid', 'distinct', 'exists:conversations,id'],
            'prompt' => ['required', 'string', 'min:1', 'max:8000'],
        ];
    }
}

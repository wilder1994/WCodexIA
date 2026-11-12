<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Domain\Assistant\Enums\DevelopmentTask;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class DevelopmentCompletionRequest extends FormRequest
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
            'task' => ['required', 'string', Rule::enum(DevelopmentTask::class)],
            'prompt' => ['required', 'string', 'min:1', 'max:8000'],
            'language' => ['nullable', 'string', 'max:32'],
            'paths' => ['sometimes', 'array', 'max:8'],
            'paths.*' => ['required', 'string', 'max:255'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if (! $this->exists('paths')) {
            $this->merge(['paths' => []]);
        }
    }
}

<?php

declare(strict_types=1);

namespace App\Domain\Security\Rules;

use App\Domain\Security\Contracts\PolicyRule;
use App\Domain\Security\Data\PolicySubject;
use App\Domain\Security\Data\PolicyVerdict;
use App\Domain\Security\Enums\PolicyAction;

final readonly class PromptInjectionRule implements PolicyRule
{
    /**
     * @var list<string>
     */
    private const PATTERNS = [
        '/ignore\s+(all\s+|any\s+|the\s+)?(previous|prior|above)\s+(instructions|rules|prompts)/i',
        '/olvida\s+(todas\s+)?(tus\s+)?instrucciones/i',
        '/ignora\s+(las\s+|todas\s+las\s+)?(instrucciones|reglas)\s+anteriores/i',
        '/\bjailbreak\b/i',
        '/\byou\s+are\s+now\b/i',
        '/\bahora\s+eres\b/i',
    ];

    public function code(): string
    {
        return 'prompt_injection';
    }

    public function description(): string
    {
        return 'El contenido del usuario y de los archivos no puede reemplazar la política del sistema.';
    }

    public function evaluate(PolicySubject $subject): PolicyVerdict
    {
        foreach (self::PATTERNS as $pattern) {
            if (preg_match($pattern, $subject->content) === 1) {
                return new PolicyVerdict(
                    $this->code(),
                    PolicyAction::Deny,
                    $subject->content,
                    'La solicitud intenta anular la política del sistema.',
                );
            }
        }

        return new PolicyVerdict(
            $this->code(),
            PolicyAction::Allow,
            $subject->content,
            'No hay intento de anular la política.',
        );
    }
}

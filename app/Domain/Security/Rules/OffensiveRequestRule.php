<?php

declare(strict_types=1);

namespace App\Domain\Security\Rules;

use App\Domain\Security\Contracts\PolicyRule;
use App\Domain\Security\Data\PolicySubject;
use App\Domain\Security\Data\PolicyVerdict;
use App\Domain\Security\Enums\PolicyAction;

final readonly class OffensiveRequestRule implements PolicyRule
{
    /**
     * @var list<string>
     */
    private const PATTERNS = [
        '/\b(ransomware|keylogger|rootkit)\b/i',
        '/\b(credential|password)\s+stealer\b/i',
        '/phishing\s*kit/i',
        '/kit\s+de\s+phishing/i',
        '/robo\s+de\s+credenciales/i',
        '/\b(write|create|build|genera|crear|crea|desarrolla)\b.{0,48}\bmalware\b/i',
    ];

    public function code(): string
    {
        return 'offensive_request';
    }

    public function description(): string
    {
        return 'No se atienden pedidos de malware, ransomware, keyloggers ni robo de credenciales.';
    }

    public function evaluate(PolicySubject $subject): PolicyVerdict
    {
        foreach (self::PATTERNS as $pattern) {
            if (preg_match($pattern, $subject->content) === 1) {
                return new PolicyVerdict(
                    $this->code(),
                    PolicyAction::Deny,
                    $subject->content,
                    'La solicitud queda fuera de la política de seguridad.',
                );
            }
        }

        return new PolicyVerdict(
            $this->code(),
            PolicyAction::Allow,
            $subject->content,
            'La solicitud está dentro del alcance permitido.',
        );
    }
}

<?php

declare(strict_types=1);

namespace App\Domain\Security\Rules;

use App\Domain\Security\Contracts\PolicyRule;
use App\Domain\Security\Data\PolicySubject;
use App\Domain\Security\Data\PolicyVerdict;
use App\Domain\Security\Enums\PolicyAction;

final readonly class SecretRedactionRule implements PolicyRule
{
    private const REPLACEMENT = '[REDACTED]';

    /**
     * @var list<string>
     */
    private const PATTERNS = [
        '/-----BEGIN [A-Z ]*PRIVATE KEY-----.*?-----END [A-Z ]*PRIVATE KEY-----/s',
        '/\bsk-[A-Za-z0-9_\-]{20,}\b/',
        '/\bsk_(live|test)_[A-Za-z0-9]{16,}\b/',
        '/\bgh[pousr]_[A-Za-z0-9]{20,}\b/',
        '/\bgithub_pat_[A-Za-z0-9_]{20,}\b/',
        '/\bAKIA[0-9A-Z]{16}\b/',
        '/\bxox[baprs]-[A-Za-z0-9-]{10,}/',
        '/\bBearer\s+[A-Za-z0-9\-\._~\+\/=]{20,}/i',
        '/(?i)\b(api[_-]?key|secret|password|passwd|token|db_password)\b\s*[:=]\s*([\'"]?)([^\s\'"]{8,})\2/',
    ];

    public function code(): string
    {
        return 'secret_redaction';
    }

    public function description(): string
    {
        return 'Claves, tokens y contraseñas se redactan antes de llegar al modelo y antes de persistirse.';
    }

    public function evaluate(PolicySubject $subject): PolicyVerdict
    {
        $redacted = $this->redact($subject->content);

        if ($redacted === $subject->content) {
            return new PolicyVerdict(
                $this->code(),
                PolicyAction::Allow,
                $subject->content,
                'No se detectaron secretos.',
            );
        }

        return new PolicyVerdict(
            $this->code(),
            PolicyAction::Redact,
            $redacted,
            'Se redactaron secretos.',
        );
    }

    public function redact(string $content): string
    {
        $redacted = preg_replace(self::PATTERNS, self::REPLACEMENT, $content);

        return is_string($redacted) ? $redacted : $content;
    }
}

<?php

declare(strict_types=1);

namespace App\Domain\Assistant\Services;

use App\Domain\Assistant\Contracts\AuditRepository;
use App\Domain\Assistant\Contracts\ConversationRepository;
use App\Domain\Assistant\Contracts\LlmProvider;
use App\Domain\Assistant\Contracts\PublicFactSource;
use App\Domain\Assistant\Data\AttachedFile;
use App\Domain\Assistant\Data\CompletionRequest;
use App\Domain\Assistant\Data\DevelopmentRequest;
use App\Domain\Assistant\Data\DevelopmentResponse;
use App\Domain\Assistant\Enums\MessageRole;
use App\Domain\Assistant\Exceptions\UnknownConversationException;
use App\Domain\Security\Contracts\WorkspaceGuard;
use App\Domain\Security\Data\PolicyDecision;
use App\Domain\Security\Data\PolicySubject;
use App\Domain\Security\Enums\PolicyAction;
use App\Domain\Security\Exceptions\PolicyDeniedException;
use App\Domain\Security\Rules\SecretRedactionRule;
use App\Domain\Security\Services\PolicyEngine;

final readonly class DevelopmentAssistant
{
    public function __construct(
        private PolicyEngine $policies,
        private SecretRedactionRule $secrets,
        private WorkspaceGuard $workspace,
        private LlmProvider $llm,
        private ConversationRepository $conversations,
        private AuditRepository $audits,
        private SystemPromptFactory $prompts,
        private SessionMemory $memory,
        private PublicFactSource $facts,
        private int $historyLimit = 10,
    ) {}

    public function handle(DevelopmentRequest $request): DevelopmentResponse
    {
        [$conversationId, $messages, $inbound] = $this->prepare($request);
        $ready = $this->sourcedLines($inbound->content);

        if ($ready !== '') {
            return $this->finish($request, $conversationId, $inbound, $ready);
        }

        $completion = $this->llm->complete(new CompletionRequest($messages));

        return $this->finish($request, $conversationId, $inbound, $this->cited($inbound->content, $completion->content));
    }

    public function stream(DevelopmentRequest $request, callable $onDelta): DevelopmentResponse
    {
        [$conversationId, $messages, $inbound] = $this->prepare($request);
        $ready = $this->sourcedLines($inbound->content);

        if ($ready !== '') {
            $onDelta($ready);

            return $this->finish($request, $conversationId, $inbound, $ready);
        }

        $content = $this->llm->stream(new CompletionRequest($messages), $onDelta);

        return $this->finish($request, $conversationId, $inbound, $this->cited($inbound->content, $content));
    }

    /**
     * @return array{0: string, 1: list<array{role: string, content: string}>, 2: PolicyDecision}
     */
    private function prepare(DevelopmentRequest $request): array
    {
        if ($request->conversationId !== null && ! $this->conversations->exists($request->conversationId)) {
            throw new UnknownConversationException;
        }

        $files = $this->workspace->read($request->paths);
        $composed = $this->compose($request, $files);
        $inbound = $this->policies->inspectInbound(new PolicySubject(
            $composed,
            $this->llm->isLocal(),
            $request->task,
        ));

        if ($inbound->isDenied()) {
            $this->audit(null, $inbound, $request->task->value);

            throw new PolicyDeniedException(
                (string) $inbound->deniedBy,
                $this->denialMessage($inbound),
            );
        }

        $conversationId = $request->conversationId ?? $this->conversations->start();
        $history = $this->conversations->recent($conversationId, $this->historyLimit);
        $messages = [
            ['role' => 'system', 'content' => $this->prompts->make()],
        ];

        if (! str_contains($inbound->content, 'Dato público con fuente')) {
            foreach ($this->boundedHistory($history) as $message) {
                $messages[] = $message;
            }
        }

        $messages[] = [
            'role' => MessageRole::User->value,
            'content' => $inbound->content,
        ];

        return [$conversationId, $messages, $inbound];
    }

    private function sourcedLines(string $inbound): string
    {
        if (substr_count($inbound, 'Fuente:') < 2) {
            return '';
        }

        $lines = [];

        foreach (preg_split("/\r?\n/", $inbound) ?: [] as $line) {
            $line = trim($line);

            if (str_starts_with($line, 'Fuente:')) {
                $lines[] = $line;
            }
        }

        return count($lines) > 1 ? implode("\n", $lines) : '';
    }

    private function cited(string $inbound, string $content): string
    {
        if (! str_contains($inbound, 'Dato público con fuente')) {
            return $content;
        }

        $mentionsMorning = str_contains($content, 'de la mañana') || preg_match('/\ba\.?\s*m\.?\b/iu', $content) === 1;

        if (preg_match('/\d{1,2}:\d{2} de la mañana hasta las \d{1,2}:\d{2} de la noche/u', $inbound, $hour) === 1
            && ! $mentionsMorning) {
            $content = preg_replace(
                '/entre las \d{1,2}:\d{2} y las \d{1,2}:\d{2} de la noche/u',
                $hour[0],
                $content,
            ) ?? $content;

            if (! str_contains($content, 'de la mañana') && preg_match('/\ba\.?\s*m\.?\b/iu', $content) !== 1) {
                $content = rtrim($content).' Horario: '.$hour[0].'.';
            }
        }

        if (preg_match('/\d{1,2} de [a-záéíóú]+ de \d{4}/u', $inbound, $day) === 1 && ! str_contains($content, $day[0])) {
            $content = rtrim($content).' Día: '.$day[0].'.';
        }

        if (preg_match('/Fuente:\s*([A-Za-z0-9.-]+)/', $inbound, $source) !== 1
            || str_contains(mb_strtolower($content), mb_strtolower($source[1]))) {
            return $content;
        }

        return rtrim($content).' Fuente: '.$source[1].'.';
    }

    private function finish(
        DevelopmentRequest $request,
        string $conversationId,
        PolicyDecision $inbound,
        string $content,
    ): DevelopmentResponse {
        $outbound = $this->policies->inspectOutbound(new PolicySubject(
            $content,
            $this->llm->isLocal(),
            $request->task,
        ));

        $storedPrompt = $this->secrets->redact($request->prompt);
        $this->conversations->append($conversationId, MessageRole::User, $storedPrompt, $request->task, $request->imagePath);

        if ($outbound->isDenied()) {
            $this->audit($conversationId, $outbound, $request->task->value);

            throw new PolicyDeniedException(
                (string) $outbound->deniedBy,
                $this->denialMessage($outbound),
            );
        }

        $this->conversations->append(
            $conversationId,
            MessageRole::Assistant,
            $outbound->content,
            null,
        );

        $action = $this->combine($inbound->action, $outbound->action);
        $applied = array_values(array_unique([
            ...$inbound->ruleCodes(),
            ...$outbound->ruleCodes(),
        ]));

        $this->audits->record(
            $conversationId,
            $action,
            $this->llm->name(),
            $applied,
            'task='.$request->task->value.' action='.$action->value,
        );

        return new DevelopmentResponse(
            $conversationId,
            $request->task,
            $action,
            $outbound->content,
            $applied,
        );
    }

    /**
     * @param  list<AttachedFile>  $files
     */
    /**
     * @param  list<StoredMessage>  $history
     * @return list<array{role: string, content: string}>
     */
    private function boundedHistory(array $history): array
    {
        $kept = [];
        $used = 0;

        foreach (array_reverse($history) as $stored) {
            $text = trim($stored->content);

            if ($text === '') {
                continue;
            }

            if (mb_strlen($text) > 900) {
                $text = mb_substr($text, 0, 900).'…';
            }

            if ($kept !== [] && $used + mb_strlen($text) > 1800) {
                break;
            }

            $used += mb_strlen($text);
            $kept[] = [
                'role' => $stored->role->value,
                'content' => $text,
            ];
        }

        return array_reverse($kept);
    }

    private function compose(DevelopmentRequest $request, array $files): string
    {
        $sections = [];

        if ($request->language !== null && $request->language !== '') {
            $sections[] = 'Lenguaje: '.$request->language;
        }

        if ($files !== []) {
            $blocks = [];

            foreach ($files as $file) {
                $blocks[] = "--- {$file->path}\n{$file->content}";
            }

            $sections[] = "Archivos:\n".implode("\n", $blocks);
        }

        $memory = $this->memory->render($request->conversationId, $request->contextIds);

        if ($memory !== '') {
            $sections[] = $memory;
        }

        $public = $this->facts->lookup($request->prompt);

        if ($public !== '') {
            $lead = substr_count($public, 'Fuente:') > 1
                ? 'Dato público con fuente. Responde en español, usando solo este texto. Di cada cifra con el lugar, el periodo y la fuente que estén escritos. No agregues una cifra, un porcentaje, un año ni un lugar que no esté escrito:'
                : 'Dato público con fuente. Responde en español, en una o dos frases, usando solo este texto. Menciona el lugar, el día, la hora y la cifra que estén escritos, y cierra con la fuente. No agregues un año, una hora, una fecha, un día ni una excepción que no esté escrita:';
            $sections[] = $lead."\n".$public;
        } elseif ($this->facts->applies($request->prompt)) {
            $sections[] = 'No hay dato público. Di que no lo tienes. No des cifras, fechas, lugares, empresas ni ganadores.';
        }

        if ($request->imagePath !== null) {
            $text = trim((string) $request->imageText);

            if (mb_strlen($text) > 2500) {
                $text = mb_substr($text, 0, 2500).'…';
            }

            $sections[] = $text === ''
                ? 'La imagen sí llegó. No se leyó texto. Di solo eso. No pidas otra copia ni un correo.'
                : "La imagen sí llegó. Este es el texto leído, úsalo para contestar lo que pidió el usuario. No inventes lo que no esté aquí:\n".$text;
        }

        $sections[] = $request->prompt;

        return implode("\n\n", $sections);
    }

    private function audit(?string $conversationId, PolicyDecision $decision, string $task): void
    {
        $this->audits->record(
            $conversationId,
            PolicyAction::Deny,
            $this->llm->name(),
            $decision->ruleCodes(),
            'task='.$task.' action=deny rule='.(string) $decision->deniedBy,
        );
    }

    private function denialMessage(PolicyDecision $decision): string
    {
        foreach ($decision->verdicts as $verdict) {
            if ($verdict->action === PolicyAction::Deny) {
                return $verdict->summary;
            }
        }

        return 'La solicitud fue rechazada por la política de seguridad.';
    }

    private function combine(PolicyAction $inbound, PolicyAction $outbound): PolicyAction
    {
        if ($inbound === PolicyAction::Redact || $outbound === PolicyAction::Redact) {
            return PolicyAction::Redact;
        }

        return PolicyAction::Allow;
    }
}

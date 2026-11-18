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
        $completion = $this->llm->complete(new CompletionRequest($messages));

        return $this->finish($request, $conversationId, $inbound, $completion->content);
    }

    public function stream(DevelopmentRequest $request, callable $onDelta): DevelopmentResponse
    {
        [$conversationId, $messages, $inbound] = $this->prepare($request);
        $content = $this->llm->stream(new CompletionRequest($messages), $onDelta);

        return $this->finish($request, $conversationId, $inbound, $content);
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

        foreach ($this->boundedHistory($history) as $message) {
            $messages[] = $message;
        }

        $messages[] = [
            'role' => MessageRole::User->value,
            'content' => $inbound->content,
        ];

        return [$conversationId, $messages, $inbound];
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
            $sections[] = "Dato público leído de internet. La línea Fuente es la página. Úsalo para responder. No inventes lo que no esté aquí:\n".$public;
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

<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Assistant\Contracts\AuditRepository;
use App\Domain\Assistant\Contracts\ConversationRepository;
use App\Domain\Assistant\Contracts\ImageTextReader;
use App\Domain\Assistant\Contracts\LlmProvider;
use App\Domain\Assistant\Services\DevelopmentAssistant;
use App\Domain\Assistant\Services\SessionMemory;
use App\Domain\Assistant\Services\SystemPromptFactory;
use App\Domain\Security\Contracts\PolicyRule;
use App\Domain\Security\Contracts\WorkspaceGuard;
use App\Domain\Security\Rules\ExternalEgressRule;
use App\Domain\Security\Rules\OffensiveRequestRule;
use App\Domain\Security\Rules\PromptInjectionRule;
use App\Domain\Security\Rules\SecretRedactionRule;
use App\Domain\Security\Services\PolicyCatalog;
use App\Domain\Security\Services\PolicyEngine;
use App\Infrastructure\Chat\TesseractImageTextReader;
use App\Infrastructure\Llm\OllamaProvider;
use App\Infrastructure\Persistence\EloquentAuditRepository;
use App\Infrastructure\Persistence\EloquentConversationRepository;
use App\Infrastructure\Process\HostEnvironment;
use App\Infrastructure\Security\LocalWorkspaceGuard;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Foundation\Console\ServeCommand;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(WorkspaceGuard::class, function (): WorkspaceGuard {
            return new LocalWorkspaceGuard((string) config('wcodex.workspace'));
        });

        $this->app->singleton(ImageTextReader::class, function (): ImageTextReader {
            $binary = $this->tesseractBinary();
            $tessdata = $this->tessdataDirectory($binary);
            $languages = (string) config('wcodex.tesseract.languages', 'eng');

            if (! is_file($tessdata.DIRECTORY_SEPARATOR.'spa.traineddata')) {
                $languages = 'eng';
            }

            return new TesseractImageTextReader($binary, $tessdata, $languages);
        });

        $this->app->singleton(LlmProvider::class, function (): LlmProvider {
            return new OllamaProvider(
                baseUrl: (string) config('wcodex.ollama.base_url'),
                model: (string) config('wcodex.ollama.model'),
                timeout: (int) config('wcodex.ollama.timeout'),
            );
        });

        $this->app->singleton(ExternalEgressRule::class, function (): ExternalEgressRule {
            return new ExternalEgressRule((bool) config('wcodex.allow_external'));
        });

        $this->app->singleton(SecretRedactionRule::class);
        $this->app->singleton(PromptInjectionRule::class);
        $this->app->singleton(OffensiveRequestRule::class);

        $this->app->singleton(PolicyEngine::class, function (Application $app): PolicyEngine {
            return new PolicyEngine(
                inbound: $this->rules($app, [
                    'external_egress' => ExternalEgressRule::class,
                    'prompt_injection' => PromptInjectionRule::class,
                    'offensive_request' => OffensiveRequestRule::class,
                    'secret_redaction' => SecretRedactionRule::class,
                ]),
                outbound: $this->rules($app, [
                    'offensive_request' => OffensiveRequestRule::class,
                    'secret_redaction' => SecretRedactionRule::class,
                ]),
            );
        });

        $this->app->singleton(PolicyCatalog::class, function (Application $app): PolicyCatalog {
            $classes = [
                ExternalEgressRule::class,
                PromptInjectionRule::class,
                OffensiveRequestRule::class,
                SecretRedactionRule::class,
            ];

            $rules = array_map(
                static fn (string $class): PolicyRule => $app->make($class),
                $classes,
            );

            $enabled = [];

            foreach ($rules as $rule) {
                $enabled[$rule->code()] = (bool) config('wcodex.policies.'.$rule->code(), true);
            }

            return new PolicyCatalog($rules, $enabled);
        });

        $this->app->bind(ConversationRepository::class, EloquentConversationRepository::class);
        $this->app->bind(AuditRepository::class, EloquentAuditRepository::class);

        $this->app->singleton(DevelopmentAssistant::class, function (Application $app): DevelopmentAssistant {
            return new DevelopmentAssistant(
                policies: $app->make(PolicyEngine::class),
                secrets: $app->make(SecretRedactionRule::class),
                workspace: $app->make(WorkspaceGuard::class),
                llm: $app->make(LlmProvider::class),
                conversations: $app->make(ConversationRepository::class),
                audits: $app->make(AuditRepository::class),
                prompts: $app->make(SystemPromptFactory::class),
                memory: $app->make(SessionMemory::class),
                historyLimit: (int) config('wcodex.history_limit'),
            );
        });
    }

    public function boot(): void
    {
        ServeCommand::$passthroughVariables = array_values(array_unique([
            ...ServeCommand::$passthroughVariables,
            'Path',
            'PATHEXT',
            'SystemRoot',
        ]));

        HostEnvironment::publishForServer();
    }

    /**
     * @param  array<string, class-string<PolicyRule>>  $map
     * @return list<PolicyRule>
     */
    private function rules(Application $app, array $map): array
    {
        $rules = [];

        foreach ($map as $code => $class) {
            if ((bool) config('wcodex.policies.'.$code, true)) {
                $rules[] = $app->make($class);
            }
        }

        return $rules;
    }

    private function tesseractBinary(): string
    {
        $configured = (string) config('wcodex.tesseract.binary', '');

        if ($configured !== '' && is_file($configured)) {
            return $configured;
        }

        $installed = 'C:\\Program Files\\Tesseract-OCR\\tesseract.exe';

        return is_file($installed) ? $installed : 'tesseract';
    }

    private function tessdataDirectory(string $binary): string
    {
        $configured = (string) config('wcodex.tesseract.tessdata', '');

        if ($configured !== '' && is_dir($configured)) {
            return $configured;
        }

        $local = getenv('LOCALAPPDATA');

        if (is_string($local) && $local !== '') {
            $user = $local.DIRECTORY_SEPARATOR.'Tesseract'.DIRECTORY_SEPARATOR.'tessdata';

            if (is_file($user.DIRECTORY_SEPARATOR.'eng.traineddata')) {
                return $user;
            }
        }

        $beside = dirname($binary).DIRECTORY_SEPARATOR.'tessdata';

        return $beside;
    }
}

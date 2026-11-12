<?php

declare(strict_types=1);

namespace App\Domain\Agent\Services;

use App\Domain\Assistant\Contracts\LlmProvider;
use App\Domain\Assistant\Data\AttachedFile;
use App\Domain\Assistant\Data\CompletionRequest;
use App\Domain\Security\Exceptions\WorkspaceBoundaryException;
use App\Domain\Security\Rules\SecretRedactionRule;
use App\Infrastructure\Security\LocalWorkspaceGuard;
use Illuminate\Support\Str;

final readonly class ProjectAgent
{
    public function __construct(
        private LlmProvider $llm,
        private SecretRedactionRule $secrets,
        private ProposalParser $parser,
        private UnifiedDiff $diff,
    ) {}

    /**
     * @param  list<string>  $paths
     * @return array<string, mixed>
     */
    public function propose(string $project, string $instruction, array $paths): array
    {
        $guard = new LocalWorkspaceGuard($project);
        $outline = $guard->outline();
        $files = [];
        $withheld = [];

        foreach ($paths as $path) {
            $file = $guard->readForEdit($path);
            $redacted = $this->secrets->redact($file->content);

            if ($redacted !== $file->content) {
                $withheld[] = $file->path;

                continue;
            }

            $files[] = $file;
        }

        $completion = $this->llm->complete(new CompletionRequest([
            ['role' => 'system', 'content' => $this->instructions()],
            ['role' => 'user', 'content' => $this->prompt($outline, $files, $instruction)],
        ]));

        $parsed = $this->parser->parse($completion->content);
        $allowed = array_map(
            static fn (AttachedFile $file): string => $file->path,
            $files,
        );
        $diffs = [];

        foreach ($parsed['edits'] as $edit) {
            if (! in_array($edit['path'], $allowed, true)) {
                continue;
            }

            $current = '';

            foreach ($files as $file) {
                if ($file->path === $edit['path']) {
                    $current = $file->content;
                }
            }

            if ($this->secrets->redact($edit['content']) !== $edit['content']) {
                $withheld[] = $edit['path'];

                continue;
            }

            $patch = $this->diff->make($edit['path'], $current, $edit['content']);

            if ($patch === '') {
                continue;
            }

            $diffs[] = [
                'path' => $edit['path'],
                'diff' => $patch,
                'content' => $edit['content'],
            ];
        }

        $root = realpath($project);

        if ($root === false) {
            throw new WorkspaceBoundaryException(
                'workspace_unavailable',
                'El proyecto configurado no está disponible.',
            );
        }

        return [
            'id' => (string) Str::uuid(),
            'root' => $root,
            'summary' => $parsed['summary'],
            'diffs' => $diffs,
            'command' => $parsed['command'],
            'withheld' => array_values(array_unique($withheld)),
            'applied' => false,
            'output' => null,
        ];
    }

    /**
     * @param  array<string, mixed>  $proposal
     * @return array<string, mixed>
     */
    public function apply(array $proposal): array
    {
        $root = $proposal['root'] ?? null;
        $diffs = $proposal['diffs'] ?? null;

        if (! is_string($root) || ! is_array($diffs)) {
            throw new WorkspaceBoundaryException(
                'workspace_unavailable',
                'La propuesta ya no está disponible.',
            );
        }

        $guard = new LocalWorkspaceGuard($root);
        $written = [];

        foreach ($diffs as $diff) {
            if (! is_array($diff) || ! is_string($diff['path'] ?? null) || ! is_string($diff['content'] ?? null)) {
                continue;
            }

            $guard->write($diff['path'], $diff['content']);
            $written[] = $diff['path'];
        }

        $proposal['applied'] = true;
        $proposal['written'] = $written;

        return $proposal;
    }

    /**
     * @param  list<AttachedFile>  $files
     */
    private function prompt(string $outline, array $files, string $instruction): string
    {
        $sections = ["Árbol:\n".$outline];

        foreach ($files as $file) {
            $sections[] = "--- {$file->path}\n{$file->content}";
        }

        $sections[] = "Instrucción:\n".$instruction;

        return implode("\n\n", $sections);
    }

    private function instructions(): string
    {
        return <<<'PROMPT'
Eres el agente de código de WCodex. Trabajas solo con los archivos que te entregaron.
Responde únicamente con JSON válido, sin markdown:
{"summary":"qué cambiarías","edits":[{"path":"ruta/relativa","content":"contenido completo del archivo"}],"command":null}
Puedes proponer un comando corto en "command", o null. No ejecutes nada.
No incluyas rutas que no estén en los archivos entregados.
No escribas secretos, llaves ni variables de entorno.
PROMPT;
    }
}

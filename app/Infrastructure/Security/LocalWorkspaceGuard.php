<?php

declare(strict_types=1);

namespace App\Infrastructure\Security;

use App\Domain\Assistant\Data\AttachedFile;
use App\Domain\Security\Contracts\WorkspaceGuard;
use App\Domain\Security\Exceptions\WorkspaceBoundaryException;

final readonly class LocalWorkspaceGuard implements WorkspaceGuard
{
    private const MAX_FILES = 8;

    private const MAX_BYTES = 65536;

    /**
     * @var list<string>
     */
    private const DENIED_EXTENSIONS = ['pem', 'key', 'p12', 'pfx'];

    public function __construct(
        private string $root,
    ) {}

    public function read(array $relativePaths): array
    {
        if (count($relativePaths) > self::MAX_FILES) {
            throw new WorkspaceBoundaryException(
                'too_many_files',
                'La solicitud adjunta demasiados archivos.',
            );
        }

        $root = $this->resolvedRoot();

        $files = [];

        foreach ($relativePaths as $relativePath) {
            $files[] = $this->readOne($root, $relativePath);
        }

        return $files;
    }

    public function readForEdit(string $relativePath): AttachedFile
    {
        $relative = $this->normalizeRelative($relativePath);
        $root = $this->resolvedRoot();
        $candidate = $root.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relative);

        if (! is_file($candidate)) {
            return new AttachedFile($relative, '');
        }

        return $this->readOne($root, $relative);
    }

    public function write(string $relativePath, string $content): void
    {
        $relative = $this->normalizeRelative($relativePath);
        $root = $this->resolvedRoot();

        if (strlen($content) > self::MAX_BYTES || ! mb_check_encoding($content, 'UTF-8')) {
            throw new WorkspaceBoundaryException(
                'file_too_large',
                'El archivo supera el tamaño permitido.',
            );
        }

        $target = $root.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relative);
        $parent = dirname($target);

        if (! is_dir($parent) && ! mkdir($parent, 0777, true) && ! is_dir($parent)) {
            throw new WorkspaceBoundaryException(
                'path_not_found',
                'El archivo no está disponible en el workspace.',
            );
        }

        $parentReal = realpath($parent);
        $prefix = rtrim($root, '\\/').DIRECTORY_SEPARATOR;

        if (
            $parentReal === false
            || (
                strtolower($parentReal) !== strtolower($root)
                && ! str_starts_with(strtolower($parentReal.DIRECTORY_SEPARATOR), strtolower($prefix))
            )
        ) {
            throw new WorkspaceBoundaryException(
                'path_outside_workspace',
                'La ruta está fuera del workspace permitido.',
            );
        }

        if (file_put_contents($target, $content) === false) {
            throw new WorkspaceBoundaryException(
                'path_not_found',
                'El archivo no está disponible en el workspace.',
            );
        }
    }

    public function outline(): string
    {
        $root = $this->resolvedRoot();
        $lines = [];
        $this->walk($root, $root, 0, $lines);

        return $lines === [] ? '(vacío)' : implode("\n", $lines);
    }

    private function readOne(string $root, string $relativePath): AttachedFile
    {
        $relativePath = str_replace('\\', '/', trim($relativePath));

        if ($relativePath === '' || $this->isAbsolute($relativePath) || str_contains($relativePath, '..')) {
            throw new WorkspaceBoundaryException(
                'path_outside_workspace',
                'La ruta está fuera del workspace permitido.',
            );
        }

        $basename = basename($relativePath);

        if ($this->isSensitive($basename)) {
            throw new WorkspaceBoundaryException(
                'sensitive_file',
                'Ese archivo no se puede enviar al asistente.',
            );
        }

        $candidate = $root.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
        $resolved = realpath($candidate);
        $prefix = rtrim($root, '\\/').DIRECTORY_SEPARATOR;

        if (
            $resolved === false
            || ! is_file($resolved)
            || ! str_starts_with(strtolower($resolved), strtolower($prefix))
        ) {
            throw new WorkspaceBoundaryException(
                'path_not_found',
                'El archivo no está disponible en el workspace.',
            );
        }

        if ($this->isSensitive(basename($resolved))) {
            throw new WorkspaceBoundaryException(
                'sensitive_file',
                'Ese archivo no se puede enviar al asistente.',
            );
        }

        $size = filesize($resolved);

        if ($size === false || $size > self::MAX_BYTES) {
            throw new WorkspaceBoundaryException(
                'file_too_large',
                'El archivo supera el tamaño permitido.',
            );
        }

        $contents = file_get_contents($resolved);

        if (! is_string($contents) || ! mb_check_encoding($contents, 'UTF-8')) {
            throw new WorkspaceBoundaryException(
                'file_not_text',
                'Solo se pueden adjuntar archivos de texto.',
            );
        }

        $normalized = ltrim(str_replace('\\', '/', substr($resolved, strlen($prefix))), '/');

        return new AttachedFile($normalized, $contents);
    }

    private function resolvedRoot(): string
    {
        $root = realpath($this->root);

        if ($root === false || ! is_dir($root)) {
            throw new WorkspaceBoundaryException(
                'workspace_unavailable',
                'El workspace configurado no está disponible.',
            );
        }

        return $root;
    }

    private function normalizeRelative(string $relativePath): string
    {
        $relativePath = str_replace('\\', '/', trim($relativePath));

        if ($relativePath === '' || $this->isAbsolute($relativePath) || str_contains($relativePath, '..')) {
            throw new WorkspaceBoundaryException(
                'path_outside_workspace',
                'La ruta está fuera del workspace permitido.',
            );
        }

        if ($this->isSensitive(basename($relativePath))) {
            throw new WorkspaceBoundaryException(
                'sensitive_file',
                'Ese archivo no se puede enviar al asistente.',
            );
        }

        return $relativePath;
    }

    /**
     * @param  list<string>  $lines
     */
    private function walk(string $root, string $directory, int $depth, array &$lines): void
    {
        if ($depth > 3 || count($lines) >= 80) {
            return;
        }

        $entries = scandir($directory);

        if ($entries === false) {
            return;
        }

        $prefix = rtrim($root, '\\/').DIRECTORY_SEPARATOR;

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..' || in_array($entry, ['vendor', 'node_modules', '.git'], true)) {
                continue;
            }

            if (count($lines) >= 80) {
                return;
            }

            $full = $directory.DIRECTORY_SEPARATOR.$entry;
            $relative = ltrim(str_replace('\\', '/', substr($full, strlen($prefix))), '/');
            $lines[] = is_dir($full) ? $relative.'/' : $relative;

            if (is_dir($full)) {
                $this->walk($root, $full, $depth + 1, $lines);
            }
        }
    }

    private function isAbsolute(string $path): bool
    {
        return str_starts_with($path, '/')
            || str_starts_with($path, '\\')
            || preg_match('/^[A-Za-z]:[\\\\\\/]/', $path) === 1;
    }

    private function isSensitive(string $basename): bool
    {
        $lower = strtolower($basename);

        if ($lower === '.env' || str_starts_with($lower, '.env.')) {
            return true;
        }

        if (in_array($lower, ['id_rsa', 'id_dsa', 'credentials.json', 'secrets.json', 'auth.json'], true)) {
            return true;
        }

        $extension = pathinfo($lower, PATHINFO_EXTENSION);

        return in_array($extension, self::DENIED_EXTENSIONS, true);
    }
}

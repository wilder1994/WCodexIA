<?php

declare(strict_types=1);

namespace App\Infrastructure\Chat;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

final class LocalChatImageStore
{
    private const MAX_BYTES = 2_097_152;

    public function store(UploadedFile $file): string
    {
        $size = $file->getSize();
        $source = $file->getRealPath();

        if (! is_int($size) || $size < 1 || $size > self::MAX_BYTES || ! is_string($source)) {
            throw new RuntimeException('La imagen no es válida.');
        }

        $info = @getimagesize($source);

        if ($info === false) {
            throw new RuntimeException('La imagen no es válida.');
        }

        $extension = match ($info['mime'] ?? '') {
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            'image/gif' => 'gif',
            default => null,
        };

        if ($extension === null) {
            throw new RuntimeException('La imagen no es válida.');
        }

        $relative = 'chat-images/'.Str::uuid()->toString().'.'.$extension;
        $contents = file_get_contents($source);

        if (! is_string($contents) || ! Storage::disk('local')->put($relative, $contents)) {
            throw new RuntimeException('La imagen no es válida.');
        }

        return $relative;
    }

    public function delete(string $relative): void
    {
        if ($this->allowed($relative)) {
            Storage::disk('local')->delete($relative);
        }
    }

    public function read(string $relative): ?string
    {
        if (! $this->allowed($relative) || ! Storage::disk('local')->exists($relative)) {
            return null;
        }

        $contents = Storage::disk('local')->get($relative);

        return is_string($contents) ? $contents : null;
    }

    public function mime(string $relative): string
    {
        return match (pathinfo($relative, PATHINFO_EXTENSION)) {
            'jpg' => 'image/jpeg',
            'png' => 'image/png',
            'webp' => 'image/webp',
            'gif' => 'image/gif',
            default => 'application/octet-stream',
        };
    }

    private function allowed(string $relative): bool
    {
        return preg_match('/^chat-images\/[0-9a-f-]{36}\.(jpg|png|webp|gif)$/', $relative) === 1;
    }
}

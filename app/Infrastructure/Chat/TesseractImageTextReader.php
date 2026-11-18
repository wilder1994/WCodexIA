<?php

declare(strict_types=1);

namespace App\Infrastructure\Chat;

use App\Domain\Assistant\Contracts\ImageTextReader;
use App\Infrastructure\Process\HostEnvironment;
use Symfony\Component\Process\Process;

final readonly class TesseractImageTextReader implements ImageTextReader
{
    public function __construct(
        private string $binary,
        private string $tessdata,
        private string $languages,
        private int $maxChars = 8000,
    ) {}

    public function read(string $absolutePath): string
    {
        $root = realpath(storage_path('app/private/chat-images'));
        $target = realpath($absolutePath);

        if ($root === false || $target === false || ! $this->inside($root, $target) || ! is_file($this->binary)) {
            return '';
        }

        $environment = (new HostEnvironment)->forCommand();

        if ($this->tessdata !== '' && is_dir($this->tessdata)) {
            $environment['TESSDATA_PREFIX'] = $this->tessdata;
        }

        $text = $this->recognize($target, $environment, $this->languages, '6');

        if ($text === '') {
            $sparse = $this->recognize($target, $environment, $this->languages, '11');

            if (mb_strlen($sparse) > mb_strlen($text)) {
                $text = $sparse;
            }
        }

        if ($text === '' && str_contains($this->languages, '+')) {
            $text = $this->recognize($target, $environment, 'eng', '6');
        }

        if ($text === '') {
            return '';
        }

        if (mb_strlen($text) > $this->maxChars) {
            $text = mb_substr($text, 0, $this->maxChars);
        }

        return $text;
    }

    /**
     * @param  array<string, string>  $environment
     */
    private function recognize(string $target, array $environment, string $languages, string $pageMode): string
    {
        $process = new Process(
            [$this->binary, $target, 'stdout', '-l', $languages, '--psm', $pageMode],
            dirname($target),
            $environment,
            null,
            12,
        );

        try {
            $process->run();
        } catch (\Throwable) {
            return '';
        }

        if (! $process->isSuccessful()) {
            return '';
        }

        return trim(str_replace(["\r\n", "\r"], "\n", $process->getOutput()));
    }

    private function inside(string $root, string $target): bool
    {
        $root = rtrim(str_replace('\\', '/', $root), '/').'/';
        $target = str_replace('\\', '/', $target);

        return str_starts_with(strtolower($target), strtolower($root));
    }
}

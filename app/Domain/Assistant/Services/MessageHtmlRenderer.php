<?php

declare(strict_types=1);

namespace App\Domain\Assistant\Services;

final class MessageHtmlRenderer
{
    public function render(string $source): string
    {
        $source = str_replace(["\r\n", "\r"], "\n", $source);
        $blocks = [];
        $source = preg_replace_callback(
            '/```[^\n]*\n(.*?)```/s',
            static function (array $match) use (&$blocks): string {
                $index = count($blocks);
                $blocks[$index] = '<pre class="code"><code>'.self::escape(rtrim($match[1], "\n")).'</code></pre>';

                return "\n@@B{$index}@@\n";
            },
            $source,
        ) ?? $source;

        $html = [];
        $paragraph = [];
        $list = null;

        $flushParagraph = static function () use (&$paragraph, &$html): void {
            if ($paragraph === []) {
                return;
            }

            $html[] = '<p>'.implode('<br>', $paragraph).'</p>';
            $paragraph = [];
        };

        $flushList = static function () use (&$list, &$html): void {
            if ($list === null) {
                return;
            }

            $html[] = '</'.$list.'>';
            $list = null;
        };

        foreach (explode("\n", $source) as $line) {
            $trimmed = trim($line);

            if (preg_match('/^@@B(\d+)@@$/', $trimmed, $match) === 1) {
                $flushParagraph();
                $flushList();
                $html[] = $blocks[(int) $match[1]];

                continue;
            }

            if ($trimmed === '') {
                $flushParagraph();
                $flushList();

                continue;
            }

            $formatted = $this->inline(self::escape($line));

            if (preg_match('/^(#{1,3})\s+(.+)$/', $formatted, $match) === 1) {
                $flushParagraph();
                $flushList();
                $level = strlen($match[1]);
                $html[] = '<h'.$level.'>'.$match[2].'</h'.$level.'>';

                continue;
            }

            if (preg_match('/^\s*\d+\.\s+(.+)$/', $formatted, $match) === 1) {
                $flushParagraph();

                if ($list !== 'ol') {
                    $flushList();
                    $html[] = '<ol>';
                    $list = 'ol';
                }

                $html[] = '<li>'.$match[1].'</li>';

                continue;
            }

            if (preg_match('/^\s*[-*]\s+(.+)$/', $formatted, $match) === 1) {
                $flushParagraph();

                if ($list !== 'ul') {
                    $flushList();
                    $html[] = '<ul>';
                    $list = 'ul';
                }

                $html[] = '<li>'.$match[1].'</li>';

                continue;
            }

            $flushList();
            $paragraph[] = $formatted;
        }

        $flushParagraph();
        $flushList();

        return implode('', $html);
    }

    private function inline(string $escaped): string
    {
        $escaped = preg_replace('/`([^`\n]+)`/', '<code>$1</code>', $escaped) ?? $escaped;

        return preg_replace('/\*\*(.+?)\*\*|__(.+?)__/', '<strong>$1$2</strong>', $escaped) ?? $escaped;
    }

    private static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

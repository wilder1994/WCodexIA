<?php

declare(strict_types=1);

namespace App\Domain\Agent\Services;

final readonly class ProposalParser
{
    /**
     * @return array{summary: string, edits: list<array{path: string, content: string}>, command: ?string}
     */
    public function parse(string $raw): array
    {
        $decoded = $this->decode($raw);

        if (! is_array($decoded)) {
            return [
                'summary' => trim($raw),
                'edits' => [],
                'command' => null,
            ];
        }

        $summary = $decoded['summary'] ?? '';
        $edits = [];

        foreach (is_array($decoded['edits'] ?? null) ? $decoded['edits'] : [] as $edit) {
            if (! is_array($edit) || ! is_string($edit['path'] ?? null) || ! is_string($edit['content'] ?? null)) {
                continue;
            }

            if (count($edits) === 4) {
                break;
            }

            $edits[] = [
                'path' => str_replace('\\', '/', trim($edit['path'])),
                'content' => $edit['content'],
            ];
        }

        $command = $decoded['command'] ?? null;

        if (! is_string($command)) {
            $command = null;
        } else {
            $command = trim($command);

            if ($command === '' || strlen($command) > 200 || str_contains($command, "\n") || str_contains($command, "\r")) {
                $command = null;
            }
        }

        return [
            'summary' => is_string($summary) && trim($summary) !== '' ? trim($summary) : 'Propuesta del agente.',
            'edits' => $edits,
            'command' => $command,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function decode(string $raw): ?array
    {
        $candidate = $this->sliceObject($this->stripFences($raw));

        if ($candidate === null) {
            return null;
        }

        $decoded = json_decode($candidate, true);

        if (! is_array($decoded)) {
            $decoded = json_decode($this->repairJson($candidate), true);
        }

        return is_array($decoded) ? $decoded : null;
    }

    private function stripFences(string $raw): string
    {
        $raw = trim($raw);

        if (preg_match('/^```(?:json)?\s*(.*?)\s*```$/s', $raw, $matches) === 1) {
            return trim($matches[1]);
        }

        return $raw;
    }

    private function sliceObject(string $raw): ?string
    {
        $start = strpos($raw, '{');
        $end = $start === false ? false : strrpos($raw, '}');

        if ($start === false || $end === false || $end < $start) {
            return null;
        }

        return substr($raw, $start, $end - $start + 1);
    }

    private function repairJson(string $json): string
    {
        $repaired = '';
        $inString = false;
        $length = strlen($json);

        for ($index = 0; $index < $length; $index++) {
            $character = $json[$index];

            if (! $inString) {
                $repaired .= $character;

                if ($character === '"') {
                    $inString = true;
                }

                continue;
            }

            if ($character === '\\') {
                $next = $json[$index + 1] ?? '';

                if ($next !== '' && in_array($next, ['"', '\\', '/', 'b', 'f', 'n', 'r', 't', 'u'], true)) {
                    $repaired .= $character.$next;
                    $index++;

                    continue;
                }

                $repaired .= '\\\\';

                continue;
            }

            if ($character === '"') {
                $inString = false;
            }

            if ($character === "\n") {
                $repaired .= '\\n';

                continue;
            }

            if ($character === "\r") {
                continue;
            }

            $repaired .= $character;
        }

        return $repaired;
    }
}

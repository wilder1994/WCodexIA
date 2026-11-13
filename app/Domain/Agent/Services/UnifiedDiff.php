<?php

declare(strict_types=1);

namespace App\Domain\Agent\Services;

final readonly class UnifiedDiff
{
    private const CONTEXT = 3;

    private const MAX_CHANGES = 200;

    public function make(string $path, string $before, string $after): string
    {
        $before = str_replace("\r\n", "\n", $before);
        $after = str_replace("\r\n", "\n", $after);

        if ($before === $after) {
            return '';
        }

        $operations = $this->operations($this->lines($before), $this->lines($after));
        $hunks = $this->hunks($operations);

        if ($hunks === []) {
            return '';
        }

        $lines = ['--- a/'.$path, '+++ b/'.$path];
        $changes = 0;

        foreach ($hunks as $hunk) {
            foreach ($hunk['body'] as $line) {
                if ($line !== '' && ($line[0] === '+' || $line[0] === '-')) {
                    $changes++;
                }
            }

            $lines[] = $hunk['header'];
            array_push($lines, ...$hunk['body']);

            if ($changes >= self::MAX_CHANGES) {
                $lines[] = '...';
                break;
            }
        }

        return implode("\n", $lines);
    }

    /**
     * @return list<string>
     */
    private function lines(string $text): array
    {
        if ($text === '') {
            return [];
        }

        if (str_ends_with($text, "\n")) {
            $text = substr($text, 0, -1);
        }

        return explode("\n", $text);
    }

    /**
     * @param  list<string>  $left
     * @param  list<string>  $right
     * @return list<array{0: string, 1: string}>
     */
    private function operations(array $left, array $right): array
    {
        $n = count($left);
        $m = count($right);
        $max = $n + $m;
        $v = [1 => 0];
        $trace = [];

        for ($d = 0; $d <= $max; $d++) {
            $trace[] = $v;

            for ($k = -$d; $k <= $d; $k += 2) {
                if ($k === -$d || ($k !== $d && ($v[$k - 1] ?? -1) < ($v[$k + 1] ?? -1))) {
                    $x = $v[$k + 1] ?? 0;
                } else {
                    $x = ($v[$k - 1] ?? 0) + 1;
                }

                $y = $x - $k;

                while ($x < $n && $y < $m && $left[$x] === $right[$y]) {
                    $x++;
                    $y++;
                }

                $v[$k] = $x;

                if ($x >= $n && $y >= $m) {
                    return $this->backtrack($trace, $left, $right);
                }
            }
        }

        return [];
    }

    /**
     * @param  list<array<int, int>>  $trace
     * @param  list<string>  $left
     * @param  list<string>  $right
     * @return list<array{0: string, 1: string}>
     */
    private function backtrack(array $trace, array $left, array $right): array
    {
        $x = count($left);
        $y = count($right);
        $operations = [];

        for ($d = count($trace) - 1; $d >= 0; $d--) {
            $v = $trace[$d];
            $k = $x - $y;

            if ($k === -$d || ($k !== $d && ($v[$k - 1] ?? -1) < ($v[$k + 1] ?? -1))) {
                $previousK = $k + 1;
            } else {
                $previousK = $k - 1;
            }

            $previousX = $v[$previousK] ?? 0;
            $previousY = $previousX - $previousK;

            while ($x > $previousX && $y > $previousY) {
                array_unshift($operations, [' ', $left[$x - 1]]);
                $x--;
                $y--;
            }

            if ($d === 0) {
                break;
            }

            if ($x === $previousX) {
                array_unshift($operations, ['+', $right[$y - 1]]);
                $y--;
            } else {
                array_unshift($operations, ['-', $left[$x - 1]]);
                $x--;
            }
        }

        return $operations;
    }

    /**
     * @param  list<array{0: string, 1: string}>  $operations
     * @return list<array{header: string, body: list<string>}>
     */
    private function hunks(array $operations): array
    {
        $count = count($operations);
        $ranges = [];

        foreach ($operations as $index => $operation) {
            if ($operation[0] === ' ') {
                continue;
            }

            $start = max(0, $index - self::CONTEXT);
            $end = min($count - 1, $index + self::CONTEXT);
            $last = array_key_last($ranges);

            if ($last !== null && $start <= $ranges[$last][1] + 1) {
                $ranges[$last][1] = max($ranges[$last][1], $end);

                continue;
            }

            $ranges[] = [$start, $end];
        }

        $hunks = [];

        foreach ($ranges as [$start, $end]) {
            $oldLine = 1;
            $newLine = 1;

            for ($index = 0; $index < $start; $index++) {
                if ($operations[$index][0] !== '+') {
                    $oldLine++;
                }

                if ($operations[$index][0] !== '-') {
                    $newLine++;
                }
            }

            $oldLength = 0;
            $newLength = 0;
            $body = [];

            for ($index = $start; $index <= $end; $index++) {
                [$type, $text] = $operations[$index];
                $body[] = $type.$text;

                if ($type !== '+') {
                    $oldLength++;
                }

                if ($type !== '-') {
                    $newLength++;
                }
            }

            $oldStart = $oldLength === 0 ? max(0, $oldLine - 1) : $oldLine;
            $newStart = $newLength === 0 ? max(0, $newLine - 1) : $newLine;
            $hunks[] = [
                'header' => "@@ -{$oldStart},{$oldLength} +{$newStart},{$newLength} @@",
                'body' => $body,
            ];
        }

        return $hunks;
    }
}

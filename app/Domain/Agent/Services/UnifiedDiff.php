<?php

declare(strict_types=1);

namespace App\Domain\Agent\Services;

final readonly class UnifiedDiff
{
    public function make(string $path, string $before, string $after): string
    {
        $before = str_replace("\r\n", "\n", $before);
        $after = str_replace("\r\n", "\n", $after);

        if ($before === $after) {
            return '';
        }

        $left = $before === '' ? [] : explode("\n", $before);
        $right = $after === '' ? [] : explode("\n", $after);
        $lines = ['--- a/'.$path, '+++ b/'.$path];
        $changes = 0;
        $limit = max(count($left), count($right));

        for ($index = 0; $index < $limit; $index++) {
            $old = $left[$index] ?? null;
            $new = $right[$index] ?? null;

            if ($old === $new) {
                continue;
            }

            if ($old !== null) {
                $lines[] = '-'.$old;
                $changes++;
            }

            if ($new !== null) {
                $lines[] = '+'.$new;
                $changes++;
            }

            if ($changes >= 200) {
                $lines[] = '...';
                break;
            }
        }

        return implode("\n", $lines);
    }
}

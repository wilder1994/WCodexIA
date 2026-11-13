<?php

declare(strict_types=1);

namespace App\Domain\Agent\Services;

use App\Domain\Agent\Data\CommandResult;
use App\Domain\Security\Exceptions\WorkspaceBoundaryException;
use App\Infrastructure\Process\HostEnvironment;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

final readonly class ApprovedCommandRunner
{
    public function run(string $root, string $command): CommandResult
    {
        $rootReal = realpath($root);
        $command = trim($command);

        if ($rootReal === false || ! is_dir($rootReal)) {
            throw new WorkspaceBoundaryException(
                'workspace_unavailable',
                'El proyecto configurado no está disponible.',
            );
        }

        if ($command === '' || strlen($command) > 200 || str_contains($command, "\n") || str_contains($command, "\r")) {
            throw new WorkspaceBoundaryException(
                'command_rejected',
                'El comando no está permitido.',
            );
        }

        $process = Process::fromShellCommandline($command, $rootReal, (new HostEnvironment)->forCommand(), null, 20);

        try {
            $process->run();
        } catch (ProcessTimedOutException) {
            return new CommandResult('El comando superó el tiempo permitido.');
        }

        $output = trim($process->getOutput()."\n".$process->getErrorOutput());

        return new CommandResult($output."\nexit ".$process->getExitCode());
    }
}

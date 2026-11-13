<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure;

use App\Infrastructure\Process\HostEnvironment;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class HostEnvironmentTest extends TestCase
{
    public function test_php_fails_when_the_command_path_does_not_include_it(): void
    {
        $process = $this->phpEcho('fail-ok', [
            'Path' => 'C:\\Windows\\System32',
            'SystemRoot' => (string) getenv('SystemRoot'),
            'PATHEXT' => (string) getenv('PATHEXT'),
        ]);

        $this->assertNotSame(0, $process->getExitCode());
        $this->assertStringNotContainsString('fail-ok', $process->getOutput());
    }

    public function test_the_command_environment_restores_the_application_php(): void
    {
        $original = $this->pathValue();
        $this->replacePath('C:\\Windows\\System32');

        try {
            $env = (new HostEnvironment)->forCommand();
            $process = $this->phpEcho('path-ok', $env);
        } finally {
            $this->replacePath($original);
        }

        $this->assertSame(0, $process->getExitCode());
        $this->assertStringContainsString('path-ok', $process->getOutput());
    }

    public function test_publishing_the_host_path_makes_php_runnable(): void
    {
        HostEnvironment::publishForServer();

        $process = $this->phpEcho('path-ok', [
            'PATH' => (string) $_ENV['PATH'],
            'SystemRoot' => (string) getenv('SystemRoot'),
            'PATHEXT' => (string) getenv('PATHEXT'),
        ]);

        $this->assertSame(0, $process->getExitCode());
        $this->assertStringContainsString('path-ok', $process->getOutput());
        $this->assertStringContainsString(dirname(PHP_BINARY), (string) $_ENV['PATH']);
    }

    /**
     * @param  array<string, string>  $env
     */
    private function phpEcho(string $token, array $env): Process
    {
        $process = Process::fromShellCommandline('php -r "echo \''.$token.'\';"', sys_get_temp_dir(), $env, null, 15);
        $process->run();

        return $process;
    }

    private function pathValue(): string
    {
        $value = getenv('Path');

        return is_string($value) ? $value : '';
    }

    private function replacePath(string $value): void
    {
        putenv('Path='.$value);
        putenv('PATH='.$value);
    }
}

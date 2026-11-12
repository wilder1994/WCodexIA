<?php

declare(strict_types=1);

namespace Tests\Unit\Security;

use App\Domain\Security\Exceptions\WorkspaceBoundaryException;
use App\Infrastructure\Security\LocalWorkspaceGuard;
use PHPUnit\Framework\TestCase;

final class WorkspaceGuardTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = sys_get_temp_dir().DIRECTORY_SEPARATOR.'wcodex-guard-'.uniqid();
        mkdir($this->root.DIRECTORY_SEPARATOR.'src', 0777, true);
        file_put_contents($this->root.DIRECTORY_SEPARATOR.'src'.DIRECTORY_SEPARATOR.'Service.php', "<?php\n\ndeclare(strict_types=1);\n");
        file_put_contents($this->root.DIRECTORY_SEPARATOR.'.env', "APP_KEY=secret\n");
    }

    protected function tearDown(): void
    {
        $this->deleteTree($this->root);

        parent::tearDown();
    }

    public function test_it_reads_a_file_inside_the_workspace(): void
    {
        $files = (new LocalWorkspaceGuard($this->root))->read(['src/Service.php']);

        $this->assertCount(1, $files);
        $this->assertSame('src/Service.php', $files[0]->path);
        $this->assertStringContainsString('strict_types', $files[0]->content);
    }

    public function test_it_rejects_paths_outside_the_workspace(): void
    {
        $this->expectException(WorkspaceBoundaryException::class);
        $this->expectExceptionMessage('fuera del workspace');

        (new LocalWorkspaceGuard($this->root))->read(['../composer.json']);
    }

    public function test_it_rejects_sensitive_files(): void
    {
        try {
            (new LocalWorkspaceGuard($this->root))->read(['.env']);
            $this->fail('A sensitive file was readable.');
        } catch (WorkspaceBoundaryException $exception) {
            $this->assertSame('sensitive_file', $exception->errorCode);
        }
    }

    private function deleteTree(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }

        $entries = scandir($path);

        if ($entries === false) {
            return;
        }

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $full = $path.DIRECTORY_SEPARATOR.$entry;

            if (is_dir($full)) {
                $this->deleteTree($full);

                continue;
            }

            unlink($full);
        }

        rmdir($path);
    }
}

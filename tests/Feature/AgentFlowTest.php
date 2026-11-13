<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class AgentFlowTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = sys_get_temp_dir().DIRECTORY_SEPARATOR.'wcodex-agent-'.uniqid();
        mkdir($this->root);
        file_put_contents($this->root.DIRECTORY_SEPARATOR.'Note.php', "<?php\n\necho \"old\";\n");
    }

    protected function tearDown(): void
    {
        $file = $this->root.DIRECTORY_SEPARATOR.'Note.php';

        if (is_file($file)) {
            unlink($file);
        }

        if (is_dir($this->root)) {
            rmdir($this->root);
        }

        $outside = dirname($this->root).DIRECTORY_SEPARATOR.'evil.txt';

        if (is_file($outside)) {
            unlink($outside);
        }

        parent::tearDown();
    }

    public function test_changes_are_shown_before_they_are_written_and_commands_wait_for_approval(): void
    {
        $command = '"'.PHP_BINARY.'" -r "echo \'agent-ok\';"';
        $proposal = json_encode([
            'summary' => 'Cambia el eco.',
            'edits' => [
                ['path' => '../evil.txt', 'content' => 'no'],
                ['path' => 'Note.php', 'content' => "<?php\n\necho \"agent-ok\";\n"],
            ],
            'command' => $command,
        ], JSON_UNESCAPED_SLASHES);

        Http::preventStrayRequests();
        Http::fake([
            'http://127.0.0.1:11434/*' => Http::response([
                'message' => ['content' => $proposal],
            ]),
        ]);

        $this->post('/agent', [
            'project' => $this->root,
            'paths' => 'Note.php',
            'instruction' => 'Cambia el texto del eco.',
        ])->assertRedirect('/agent');

        $this->assertStringContainsString('echo "old"', (string) file_get_contents($this->root.DIRECTORY_SEPARATOR.'Note.php'));
        $this->assertFileDoesNotExist(dirname($this->root).DIRECTORY_SEPARATOR.'evil.txt');

        $page = $this->get('/agent');
        $page->assertOk();
        $page->assertSee('Cambia el eco.');
        $page->assertSee('agent-ok');
        $page->assertSee('Aprobar escritura');
        $page->assertSee('Ejecutar comando');

        $proposalId = (string) session('agent.proposal.id');

        $this->post('/agent/apply', [
            'proposal_id' => $proposalId,
        ])->assertRedirect('/agent');

        $this->assertStringContainsString('echo "agent-ok"', (string) file_get_contents($this->root.DIRECTORY_SEPARATOR.'Note.php'));
        $this->assertFileDoesNotExist(dirname($this->root).DIRECTORY_SEPARATOR.'evil.txt');

        $this->post('/agent/run', [
            'proposal_id' => $proposalId,
        ])->assertRedirect('/agent');

        $this->get('/agent')
            ->assertOk()
            ->assertSee('exit 0');
    }

    public function test_a_fenced_model_reply_renders_the_diff_and_enables_approval(): void
    {
        $proposal = <<<'TXT'
```json
{ "summary": "Agrega el comentario.", "edits": [ { "path": "Note.php", "content": "<?php\n\nnamespace App\Http;\n\necho \"agent-ok\";\n" } ], "command": "php -l Note.php" }
```
TXT;

        Http::preventStrayRequests();
        Http::fake([
            'http://127.0.0.1:11434/*' => Http::response([
                'message' => ['content' => $proposal],
            ]),
        ]);

        $this->post('/agent', [
            'project' => $this->root,
            'paths' => 'Note.php',
            'instruction' => 'Agrega el comentario.',
        ])->assertRedirect('/agent');

        $this->assertStringContainsString('echo "old"', (string) file_get_contents($this->root.DIRECTORY_SEPARATOR.'Note.php'));

        $page = $this->get('/agent');
        $page->assertOk();
        $page->assertSee('Agrega el comentario.');
        $page->assertSee('agent-ok');
        $page->assertSee('Aprobar escritura');
        $page->assertSee('Ejecutar comando');
        $page->assertDontSee('type="submit" disabled', false);
    }

    public function test_an_inserted_comment_renders_only_that_line_as_added(): void
    {
        $before = <<<'PHP'
<?php

namespace App\Http\Controllers;

abstract class Controller
{
    //
}

PHP;
        $after = <<<'PHP'
<?php

// Agente en revisión.

namespace App\Http\Controllers;

abstract class Controller
{
    //
}

PHP;
        file_put_contents($this->root.DIRECTORY_SEPARATOR.'Note.php', $before);

        Http::preventStrayRequests();
        Http::fake([
            'http://127.0.0.1:11434/*' => Http::response([
                'message' => [
                    'content' => json_encode([
                        'summary' => 'Agrega el comentario.',
                        'edits' => [
                            ['path' => 'Note.php', 'content' => $after],
                        ],
                        'command' => null,
                    ], JSON_UNESCAPED_SLASHES),
                ],
            ]),
        ]);

        $this->post('/agent', [
            'project' => $this->root,
            'paths' => 'Note.php',
            'instruction' => 'Agrega el comentario.',
        ])->assertRedirect('/agent');

        $page = $this->get('/agent');
        $page->assertOk();
        $page->assertSee('+// Agente en revisión.', false);
        $page->assertDontSee('-namespace App\\Http\\Controllers;', false);
        $page->assertDontSee('-abstract class Controller', false);
        $page->assertSee('Aprobar escritura');
    }

    public function test_a_bare_php_command_uses_the_application_binary(): void
    {
        $proposal = json_encode([
            'summary' => 'Solo el comando.',
            'edits' => [],
            'command' => 'php -r "echo \'path-ok\';"',
        ], JSON_UNESCAPED_SLASHES);

        Http::preventStrayRequests();
        Http::fake([
            'http://127.0.0.1:11434/*' => Http::response([
                'message' => ['content' => $proposal],
            ]),
        ]);

        $this->post('/agent', [
            'project' => $this->root,
            'paths' => 'Note.php',
            'instruction' => 'No cambies el archivo.',
        ])->assertRedirect('/agent');

        $pathKey = 'PATH';
        $original = false;

        foreach (getenv() as $key => $value) {
            if (is_string($key) && strcasecmp($key, 'PATH') === 0) {
                $pathKey = $key;
                $original = $value;
            }
        }

        putenv($pathKey.'=C:\\Windows\\System32');

        try {
            $this->post('/agent/run', [
                'proposal_id' => (string) session('agent.proposal.id'),
            ])->assertRedirect('/agent');
        } finally {
            if (is_string($original)) {
                putenv($pathKey.'='.$original);
            }
        }

        $this->get('/agent')
            ->assertOk()
            ->assertSee('path-ok')
            ->assertSee('exit 0');
    }
}

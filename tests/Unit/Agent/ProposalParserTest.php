<?php

declare(strict_types=1);

namespace Tests\Unit\Agent;

use App\Domain\Agent\Services\ProposalParser;
use PHPUnit\Framework\TestCase;

final class ProposalParserTest extends TestCase
{
    public function test_it_extracts_json_wrapped_in_markdown_fences(): void
    {
        $raw = <<<'TXT'
```json
{ "summary": "agregar comentario de una sola línea encima de la clase", "edits": [ { "path": "app/Http/Controllers/Controller.php", "content": "<?php\n\nnamespace App\Http\Controllers;\n\n// Agente en revisión.\n\nabstract class Controller\n{\n    //\n}" } ], "command": "php -l app/Http/Controllers/Controller.php" }
```
TXT;

        $parsed = (new ProposalParser)->parse($raw);

        $this->assertSame('agregar comentario de una sola línea encima de la clase', $parsed['summary']);
        $this->assertCount(1, $parsed['edits']);
        $this->assertSame('app/Http/Controllers/Controller.php', $parsed['edits'][0]['path']);
        $this->assertStringContainsString('// Agente en revisión.', $parsed['edits'][0]['content']);
        $this->assertSame('php -l app/Http/Controllers/Controller.php', $parsed['command']);
    }
}

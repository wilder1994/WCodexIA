<?php

declare(strict_types=1);

namespace Tests\Unit\Agent;

use App\Domain\Agent\Services\UnifiedDiff;
use PHPUnit\Framework\TestCase;

final class UnifiedDiffTest extends TestCase
{
    public function test_an_inserted_comment_is_the_only_change(): void
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

        $diff = (new UnifiedDiff)->make('app/Http/Controllers/Controller.php', $before, $after);

        $this->assertStringContainsString('+// Agente en revisión.', $diff);
        $this->assertStringContainsString(' namespace App\\Http\\Controllers;', $diff);
        $this->assertStringNotContainsString('-namespace App\\Http\\Controllers;', $diff);
        $this->assertStringNotContainsString('-abstract class Controller', $diff);
        $this->assertStringNotContainsString('-{', $diff);
    }

    public function test_identical_files_produce_no_diff(): void
    {
        $this->assertSame('', (new UnifiedDiff)->make('Note.php', "<?php\n", "<?php\n"));
    }
}

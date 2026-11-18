<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Assistant\Services\MessageHtmlRenderer;
use PHPUnit\Framework\TestCase;

final class MessageHtmlRendererTest extends TestCase
{
    public function test_markdown_is_formatted_and_raw_html_stays_text(): void
    {
        $html = (new MessageHtmlRenderer)->render(<<<'MD'
## Título

1. **Crear un servicio**:
   - abre `artisan`

<script>alert(1)</script>

```php
<?php echo 1;
```
MD);

        $this->assertStringContainsString('<h2>Título</h2>', $html);
        $this->assertStringContainsString('<strong>Crear un servicio</strong>', $html);
        $this->assertStringContainsString('<ol>', $html);
        $this->assertStringContainsString('<li>', $html);
        $this->assertStringContainsString('<code>artisan</code>', $html);
        $this->assertStringContainsString('<pre class="code"><code>&lt;?php echo 1;</code></pre>', $html);
        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
        $this->assertStringNotContainsString('**', $html);
        $this->assertStringNotContainsString('## ', $html);
    }
}

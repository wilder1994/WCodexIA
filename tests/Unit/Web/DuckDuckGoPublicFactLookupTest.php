<?php

declare(strict_types=1);

namespace Tests\Unit\Web;

use App\Domain\Security\Rules\SecretRedactionRule;
use App\Infrastructure\Web\DuckDuckGoPublicFactLookup;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class DuckDuckGoPublicFactLookupTest extends TestCase
{
    public function test_a_coding_request_stays_on_the_machine(): void
    {
        Http::preventStrayRequests();
        Http::fake();

        $text = (new DuckDuckGoPublicFactLookup(new SecretRedactionRule))
            ->lookup('Diseña un repositorio para pedidos.');

        $this->assertSame('', $text);
        Http::assertNothingSent();
    }

    public function test_a_fact_question_uses_the_public_page_and_hides_secrets(): void
    {
        Http::preventStrayRequests();
        Http::fake(function ($request) {
            $url = $request->url();
            $this->assertStringNotContainsString('ClaveSuperSecreta', $url);

            if (str_contains($url, 'duckduckgo.com')) {
                $link = rawurlencode('https://example.com/sj');

                return Http::response('<a href="/l/?uddg='.$link.'&rut=1">SJ</a>', 200);
            }

            if (str_contains($url, 'example.com')) {
                return Http::response(
                    '<html><body><p>SJ Seguridad Privada ofrece seguridad fisica y electronica en Cali, con vigilancia y estudios de confiabilidad para empresas.</p></body></html>',
                    200,
                );
            }

            return Http::response('', 404);
        });

        $text = (new DuckDuckGoPublicFactLookup(new SecretRedactionRule))
            ->lookup('investiga qué es SJ Seguridad Privada password=ClaveSuperSecreta');

        $this->assertStringContainsString('seguridad fisica', $text);
        $this->assertStringNotContainsString('ClaveSuperSecreta', $text);
    }

    public function test_a_company_name_is_looked_up_without_a_cue(): void
    {
        Http::preventStrayRequests();
        Http::fake(function ($request) {
            $url = $request->url();

            if (str_contains($url, 'duckduckgo.com')) {
                $this->assertStringContainsString('SJ', rawurldecode($url));
                $link = rawurlencode('https://example.com/sj');

                return Http::response('<a href="/l/?uddg='.$link.'&rut=1">SJ</a>', 200);
            }

            if (str_contains($url, 'example.com')) {
                return Http::response(
                    '<html><body><p>SJ Seguridad Privada ofrece seguridad fisica y electronica en Cali, con vigilancia y estudios de confiabilidad para empresas.</p></body></html>',
                    200,
                );
            }

            return Http::response('', 404);
        });

        $text = (new DuckDuckGoPublicFactLookup(new SecretRedactionRule))
            ->lookup('SJ Seguridad Privada');

        $this->assertStringContainsString('Cali', $text);
    }

    public function test_a_social_page_is_skipped_for_the_next_result(): void
    {
        Http::preventStrayRequests();
        Http::fake(function ($request) {
            $url = $request->url();

            if (str_contains($url, 'duckduckgo.com')) {
                $social = rawurlencode('https://www.youtube.com/watch?v=1');
                $site = rawurlencode('https://example.com/sj');

                return Http::response('<a href="/l/?uddg='.$social.'"></a><a href="/l/?uddg='.$site.'"></a>', 200);
            }

            if (str_contains($url, 'example.com')) {
                return Http::response(
                    '<html><body><p>SJ Seguridad Privada ofrece seguridad fisica y electronica en Cali, con vigilancia y estudios de confiabilidad para empresas.</p></body></html>',
                    200,
                );
            }

            return Http::response('<p>'.str_repeat('Video sin el dato de la empresa. ', 6).'</p>', 200);
        });

        $text = (new DuckDuckGoPublicFactLookup(new SecretRedactionRule))
            ->lookup('SJ Seguridad Privada');

        $this->assertStringContainsString('Fuente: example.com', $text);
        $this->assertStringContainsString('Cali', $text);
        Http::assertNotSent(fn ($request): bool => str_contains($request->url(), 'youtube.com'));
    }

    public function test_the_page_keeps_the_paragraph_about_the_subject(): void
    {
        Http::preventStrayRequests();
        Http::fake(function ($request) {
            $url = $request->url();

            if (str_contains($url, 'duckduckgo.com')) {
                $link = rawurlencode('https://example.com/sj');

                return Http::response('<a href="/l/?uddg='.$link.'"></a>', 200);
            }

            $menu = str_repeat('menu item ', 180);
            $later = str_repeat('Tambien ofrece estudios de confiabilidad para empresas. ', 8);

            return Http::response(
                '<html><body><nav>'.$menu.'</nav><p>SJ Seguridad Privada presta vigilancia en Cali durante todo el ano.</p><p>'.$later.'</p></body></html>',
                200,
            );
        });

        $text = (new DuckDuckGoPublicFactLookup(new SecretRedactionRule))
            ->lookup('SJ Seguridad Privada');

        $this->assertStringContainsString('vigilancia en Cali', $text);
        $this->assertStringContainsString('estudios de confiabilidad', $text);
        $this->assertStringNotContainsString('menu item', $text);
    }

    public function test_the_address_in_the_footer_is_kept(): void
    {
        Http::preventStrayRequests();
        Http::fake(function ($request) {
            $url = $request->url();

            if (str_contains($url, 'duckduckgo.com')) {
                $link = rawurlencode('https://example.com/sj');

                return Http::response('<a href="/l/?uddg='.$link.'"></a>', 200);
            }

            return Http::response(
                '<html><body><p>SJ Seguridad Privada ofrece vigilancia electronica y estudios de confiabilidad para empresas.</p><footer><p>Sede en Cali, Valle del Cauca.</p></footer></body></html>',
                200,
            );
        });

        $text = (new DuckDuckGoPublicFactLookup(new SecretRedactionRule))
            ->lookup('SJ Seguridad Privada');

        $this->assertStringContainsString('Cali', $text);
        $this->assertStringContainsString('vigilancia electronica', $text);
    }

    public function test_an_encyclopedia_page_is_read_before_a_generic_directory(): void
    {
        Http::preventStrayRequests();
        Http::fake(function ($request) {
            $url = $request->url();

            if (str_contains($url, 'duckduckgo.com')) {
                $directory = rawurlencode('https://example.com/directorio');
                $wiki = rawurlencode('https://es.wikipedia.org/wiki/Seguridad');

                return Http::response('<a href="/l/?uddg='.$directory.'"></a><a href="/l/?uddg='.$wiki.'"></a>', 200);
            }

            if (str_contains($url, 'wikipedia.org')) {
                return Http::response(
                    '<html><body><p>La seguridad privada es la proteccion de bienes y personas por una empresa, con vigilancia y controles de acceso.</p></body></html>',
                    200,
                );
            }

            return Http::response('<p>'.str_repeat('Directorio comercial con anuncios y listados de todo tipo. ', 4).'</p>', 200);
        });

        $text = (new DuckDuckGoPublicFactLookup(new SecretRedactionRule))
            ->lookup('qué es la seguridad privada');

        $this->assertStringContainsString('Fuente: es.wikipedia.org', $text);
        $this->assertStringContainsString('vigilancia', $text);
        Http::assertNotSent(fn ($request): bool => str_contains($request->url(), 'example.com'));
    }

    public function test_a_greeting_stays_on_the_machine(): void
    {
        Http::preventStrayRequests();
        Http::fake();

        $text = (new DuckDuckGoPublicFactLookup(new SecretRedactionRule))
            ->lookup('hola cómo estás');

        $this->assertSame('', $text);
        Http::assertNothingSent();
    }

    public function test_a_private_address_is_not_read(): void
    {
        Http::preventStrayRequests();
        Http::fake(function ($request) {
            $link = rawurlencode('http://127.0.0.1/secreto');

            return Http::response('<a href="/l/?uddg='.$link.'">local</a>', 200);
        });

        $text = (new DuckDuckGoPublicFactLookup(new SecretRedactionRule))
            ->lookup('busca información sobre el panel interno');

        $this->assertSame('', $text);
    }
}

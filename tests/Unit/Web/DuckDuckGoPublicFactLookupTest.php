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

    public function test_a_blocked_search_does_not_use_a_different_article(): void
    {
        Http::preventStrayRequests();
        Http::fake(function ($request) {
            $url = $request->url();

            if (str_contains($url, 'list=search')) {
                return Http::response(json_encode([
                    'query' => ['search' => [['title' => 'Coacción']]],
                ], JSON_THROW_ON_ERROR), 200);
            }

            if (str_contains($url, 'html.duckduckgo.com')) {
                return Http::response('bloqueo', 202);
            }

            return Http::response('', 404);
        });

        $text = (new DuckDuckGoPublicFactLookup(new SecretRedactionRule))
            ->lookup('SJ Seguridad Privada');

        $this->assertSame('', $text);
        Http::assertNotSent(fn ($request): bool => str_contains($request->url(), 'prop=extracts'));
    }

    public function test_a_matching_article_is_quoted_with_its_source(): void
    {
        Http::preventStrayRequests();
        Http::fake(function ($request) {
            $url = $request->url();

            if (str_contains($url, 'list=search')) {
                return Http::response(json_encode([
                    'query' => ['search' => [['title' => 'Cali']]],
                ], JSON_THROW_ON_ERROR), 200);
            }

            if (str_contains($url, 'prop=extracts')) {
                return Http::response(json_encode([
                    'query' => ['pages' => ['1' => [
                        'title' => 'Cali',
                        'extract' => 'Cali es la capital del Valle del Cauca. La población proyectada para 2025 es de 2.297.000 habitantes según la fuente oficial citada en el artículo.',
                    ]]],
                ], JSON_THROW_ON_ERROR), 200);
            }

            return Http::response('', 404);
        });

        $text = (new DuckDuckGoPublicFactLookup(new SecretRedactionRule))
            ->lookup('¿Cuántos habitantes tiene Cali?');

        $this->assertStringContainsString('Fuente: es.wikipedia.org/wiki/Cali', $text);
        $this->assertStringContainsString('2.297.000 habitantes', $text);
    }

    public function test_the_population_comes_from_the_infobox_not_from_an_unrelated_headline(): void
    {
        Http::preventStrayRequests();
        Http::fake(function ($request) {
            $url = $request->url();

            if (str_contains($url, 'news.google.com')) {
                return Http::response(
                    '<rss><channel><item><title>¿Cuál es la realidad de los habitantes de calle en Cali? Se habla de 4.000 a 8.000 en la ciudad - El Tiempo</title><pubDate>Wed, 29 Oct 2025 07:00:00 GMT</pubDate></item></channel></rss>',
                    200,
                );
            }

            if (str_contains($url, 'list=search')) {
                return Http::response(json_encode([
                    'query' => ['search' => [['title' => 'Cali']]],
                ], JSON_THROW_ON_ERROR), 200);
            }

            if (str_contains($url, 'action=parse')) {
                return Http::response(json_encode([
                    'parse' => ['wikitext' => ['*' => "| población = 2269983\n| población_año = 2026\n| población_urb = 2217353\n| población_met = 2753584\n{{cita web |url=https://www.dane.gov.co/files/censo2018/proyecciones.xlsx |título=Resultados y proyecciones (2018-2042) del censo 2018 |fechaacceso=13 de septiembre de 2025}}"]],
                ], JSON_THROW_ON_ERROR), 200);
            }

            if (str_contains($url, 'prop=extracts')) {
                return Http::response(json_encode([
                    'query' => ['pages' => ['1' => [
                        'title' => 'Cali',
                        'extract' => 'Texto largo sin la cifra pedida, solo para comprobar que no se usa esta población de 9 habitantes.',
                    ]]],
                ], JSON_THROW_ON_ERROR), 200);
            }

            return Http::response('', 404);
        });

        $text = (new DuckDuckGoPublicFactLookup(new SecretRedactionRule))
            ->lookup('¿Cuántos habitantes tiene Cali?');

        $this->assertStringContainsString('Fuente: es.wikipedia.org/wiki/Cali', $text);
        $this->assertStringContainsString('Población 2026: 2269983.', $text);
        $this->assertStringContainsString('dane.gov.co', $text);
        $this->assertStringContainsString('13 de septiembre de 2025', $text);
        $this->assertStringNotContainsString('habitantes de calle', $text);
        $this->assertStringNotContainsString('9 habitantes', $text);
        Http::assertNotSent(fn ($request): bool => str_contains($request->url(), 'prop=extracts'));
    }

    public function test_the_champion_sentence_is_quoted(): void
    {
        Http::preventStrayRequests();
        Http::fake(function ($request) {
            $url = $request->url();

            if (str_contains($url, 'list=search')) {
                return Http::response(json_encode([
                    'query' => ['search' => [['title' => 'Copa Mundial de Fútbol de 2022']]],
                ], JSON_THROW_ON_ERROR), 200);
            }

            if (str_contains($url, 'action=parse')) {
                return Http::response(json_encode([
                    'parse' => ['wikitext' => ['*' => 'El campeón fue [[Selección de fútbol de Argentina|Argentina]], liderado por [[Lionel Messi]], que derrotó en la final por 4-2 en los penales a Francia.']],
                ], JSON_THROW_ON_ERROR), 200);
            }

            return Http::response('', 404);
        });

        $text = (new DuckDuckGoPublicFactLookup(new SecretRedactionRule))
            ->lookup('¿Quién ganó el Mundial de fútbol de 2022?');

        $this->assertStringContainsString('Fuente: es.wikipedia.org/wiki/Copa_Mundial_de_F%C3%BAtbol_de_2022', $text);
        $this->assertStringContainsString('El campeón fue Argentina', $text);
        $this->assertStringContainsString('Francia', $text);
    }

    public function test_the_latest_headline_with_a_figure_is_kept(): void
    {
        Http::preventStrayRequests();
        Http::fake(function ($request) {
            $url = $request->url();

            if (str_contains($url, 'news.google.com')) {
                return Http::response(
                    '<rss><channel>'
                    .'<item><title>El desempleo en Colombia sigue en debate - EL PAÍS</title><pubDate>Sun, 04 Oct 2026 07:00:00 GMT</pubDate></item>'
                    .'<item><title>Vuelve a subir el desempleo en Colombia: en agosto del 2026 la tasa de desocupación nacional llegó al 9,4% - Portafolio.co</title><pubDate>Wed, 30 Sep 2026 07:00:00 GMT</pubDate></item>'
                    .'</channel></rss>',
                    200,
                );
            }

            return Http::response('', 404);
        });

        $text = (new DuckDuckGoPublicFactLookup(new SecretRedactionRule))
            ->lookup('¿Cuál es la tasa de desempleo en Colombia?');

        $this->assertStringContainsString('news.google.com', $text);
        $this->assertStringContainsString('9,4%', $text);
        $this->assertStringContainsString('30 de septiembre de 2026', $text);
        $this->assertStringContainsString('Portafolio.co', $text);
        $this->assertStringNotContainsString('EL PAÍS', $text);
        Http::assertNotSent(fn ($request): bool => str_contains($request->url(), 'wikipedia.org'));
    }

    public function test_today_pico_y_placa_uses_the_headline_that_states_the_digits(): void
    {
        Http::preventStrayRequests();
        Http::fake(function ($request) {
            $url = $request->url();

            if (str_contains($url, 'news.google.com')) {
                return Http::response(
                    '<rss><channel>'
                    .'<item><title>Pico y placa: Secretaría de Movilidad recordó cuáles son los dígitos que rigen para este miércoles 18 de noviembre en Cali - El País</title><pubDate>Wed, 18 Nov 2026 13:17:19 GMT</pubDate></item>'
                    .'<item><title>Pico y placa en Cali este 18 de noviembre: vehículos con placas terminadas en 3 y 4 tienen restricción de circulación - Pulzo</title><pubDate>Tue, 17 Nov 2026 19:01:46 GMT</pubDate></item>'
                    .'</channel></rss>',
                    200,
                );
            }

            return Http::response('', 404);
        });

        $text = (new DuckDuckGoPublicFactLookup(new SecretRedactionRule))
            ->lookup('cual es el pico y placa de cali hoy');

        $this->assertStringContainsString('news.google.com', $text);
        $this->assertStringContainsString('3 y 4', $text);
        $this->assertStringContainsString('17 de noviembre de 2026', $text);
        $this->assertStringContainsString('Pulzo', $text);
        $this->assertStringContainsString('hora de Colombia', $text);
        $this->assertStringNotContainsString('Tue,', $text);
        $this->assertStringNotContainsString('El País', $text);
    }

    public function test_a_web_page_paragraph_is_used_with_its_source(): void
    {
        Http::preventStrayRequests();
        Http::fake(function ($request) {
            $url = $request->url();

            if (str_contains($url, 'duckduckgo.com')) {
                $link = rawurlencode('https://example.com/pico');

                return Http::response('<a href="/l/?uddg='.$link.'"></a>', 200);
            }

            if (str_contains($url, 'example.com')) {
                return Http::response(
                    '<html><body><p>'.str_repeat('Aceptando esta cookie de navegacion. ', 6).'</p>'
                    .'<script type="application/ld+json">{"@type":"Answer","text":"Hoy 18 de noviembre de 2026 en Cali hay pico y placa para vehiculos con placas terminadas en 3 y 4, en horario de 06:00 a 19:00."}</script>'
                    .'</body></html>',
                    200,
                );
            }

            return Http::response('', 404);
        });

        $text = (new DuckDuckGoPublicFactLookup(new SecretRedactionRule))
            ->lookup('cual es el pico y placa de cali hoy');

        $this->assertStringContainsString('Fuente: example.com', $text);
        $this->assertStringContainsString('3 y 4', $text);
        $this->assertStringContainsString('06:00 a 19:00', $text);
        $this->assertStringNotContainsString('cookie', $text);
        Http::assertNotSent(fn ($request): bool => str_contains($request->url(), 'news.google.com'));
    }

    public function test_a_plate_sentence_inside_a_script_beats_a_paragraph_without_digits(): void
    {
        Http::preventStrayRequests();
        Http::fake(function ($request) {
            $url = $request->url();

            if (str_contains($url, 'duckduckgo.com')) {
                $link = rawurlencode('https://www.eltiempo.com/pico');

                return Http::response('<a href="/l/?uddg='.$link.'"></a>', 200);
            }

            if (str_contains($url, 'eltiempo.com')) {
                return Http::response(
                    '<html><body>'
                    .'<p>La Secretaria de Movilidad de Cali publico el calendario 2026 para los conductores.</p>'
                    .'<p>Consulta el horario vigente en la ciudad durante esta semana.</p>'
                    .'<script>window.__DATA__={"body":"Para este miercoles 18 de noviembre de 2026, los vehiculos particulares cuyas placas terminen en 3 y 4 tendran prohibido circular desde las 6:00 de la manana hasta las 7:00 de la noche."}</script>'
                    .'</body></html>',
                    200,
                );
            }

            return Http::response('', 404);
        });

        $text = (new DuckDuckGoPublicFactLookup(new SecretRedactionRule))
            ->lookup('cual es el pico y placa de cali hoy');

        $this->assertStringContainsString('Fuente: eltiempo.com', $text);
        $this->assertStringContainsString('3 y 4', $text);
        $this->assertStringContainsString('6:00', $text);
        $this->assertStringNotContainsString('Secretaria de Movilidad', $text);
        Http::assertNotSent(fn ($request): bool => str_contains($request->url(), 'news.google.com'));
    }

    public function test_a_plate_question_reads_the_publisher_when_search_returns_no_links(): void
    {
        Http::preventStrayRequests();
        Http::fake(function ($request) {
            $url = $request->url();

            if (str_contains($url, 'duckduckgo.com')) {
                return Http::response('bloqueo', 202);
            }

            if (str_contains($url, 'news.google.com/rss/search')) {
                return Http::response(
                    '<rss><channel><item>'
                    .'<title>Pico y placa en Cali para el miercoles 18 de noviembre</title>'
                    .'<link>https://news.google.com/rss/articles/TESTID</link>'
                    .'<pubDate>Wed, 18 Nov 2026 13:17:19 GMT</pubDate>'
                    .'</item></channel></rss>',
                    200,
                );
            }

            if (str_contains($url, 'news.google.com/rss/articles/TESTID') && ! str_contains($url, 'continue=1')) {
                return Http::response('', 302, ['Location' => 'https://news.google.com/rss/articles/TESTID?continue=1']);
            }

            if (str_contains($url, 'continue=1')) {
                return Http::response('<div data-n-a-ts="1700000000" data-n-a-sg="abc"></div>', 200);
            }

            if (str_contains($url, 'batchexecute')) {
                $body = ")]}'\n\n".json_encode([[
                    'wrb.fr',
                    'Fbv4je',
                    json_encode(['garturlres', 'https://example.com/pico', 1], JSON_UNESCAPED_SLASHES),
                ]], JSON_UNESCAPED_SLASHES);

                return Http::response($body, 200);
            }

            if (str_contains($url, 'example.com')) {
                return Http::response(
                    '<html><body><script>{"body":"La restriccion va desde las 6:00 de la manana hasta las 7:00 de la noche. Este esquema se aplica de lunes a viernes. Para este miercoles 18 de noviembre de 2026, los vehiculos particulares cuyas placas terminen en 3 y 4 tendran prohibido circular."}</script></body></html>',
                    200,
                );
            }

            return Http::response('', 404);
        });

        $text = (new DuckDuckGoPublicFactLookup(new SecretRedactionRule))
            ->lookup('cual es el pico y placa de cali hoy');

        $this->assertStringContainsString('Fuente: example.com', $text);
        $this->assertStringContainsString('3 y 4', $text);
        $this->assertStringContainsString('6:00', $text);
        $this->assertStringNotContainsString('lunes a viernes', $text);
        $this->assertStringNotContainsString('news.google.com', $text);
    }

    public function test_a_long_crime_question_keeps_the_sentences_that_state_the_figures(): void
    {
        Http::preventStrayRequests();
        Http::fake(function ($request) {
            $url = $request->url();

            if (str_contains($url, 'duckduckgo.com')) {
                return Http::response('bloqueo', 202);
            }

            if (str_contains($url, 'news.google.com/rss/search')) {
                return Http::response(
                    '<rss><channel>'
                    .'<item><title>Homicidios en el Valle del Cauca durante octubre - Diario Occidente</title><link>https://news.google.com/rss/articles/HOMI</link><pubDate>Wed, 18 Nov 2026 13:00:00 GMT</pubDate></item>'
                    .'<item><title>Hurto a personas en el Valle del Cauca - El Tiempo</title><link>https://news.google.com/rss/articles/HURT</link><pubDate>Tue, 17 Nov 2026 13:00:00 GMT</pubDate></item>'
                    .'<item><title>El clima en el Valle anuncia lluvias - El Pais</title><link>https://news.google.com/rss/articles/CLIMA</link><pubDate>Wed, 18 Nov 2026 12:00:00 GMT</pubDate></item>'
                    .'</channel></rss>',
                    200,
                );
            }

            if (str_contains($url, 'news.google.com/rss/articles/')) {
                return Http::response('<div data-n-a-ts="1700000000" data-n-a-sg="abc"></div>', 200);
            }

            if (str_contains($url, 'batchexecute')) {
                $publisher = str_contains($request->url(), 'HURT')
                    ? 'https://example.org/hurto'
                    : 'https://example.com/homicidios';
                $body = ")]}'\n\n".json_encode([[
                    'wrb.fr',
                    'Fbv4je',
                    json_encode(['garturlres', $publisher, 1], JSON_UNESCAPED_SLASHES),
                ]], JSON_UNESCAPED_SLASHES);

                return Http::response($body, 200);
            }

            if (str_contains($url, 'example.com')) {
                return Http::response(
                    '<html><body><p>En octubre de 2026 se registraron 179 homicidios en el Valle del Cauca, un 8,2 % menos que en 2025.</p><p>El informe no trae otra cifra de delitos.</p></body></html>',
                    200,
                );
            }

            if (str_contains($url, 'example.org')) {
                return Http::response(
                    '<html><body><p>Entre enero y julio de 2026 hubo 9.009 hurtos a personas en el Valle del Cauca.</p></body></html>',
                    200,
                );
            }

            return Http::response('', 404);
        });

        $text = (new DuckDuckGoPublicFactLookup(new SecretRedactionRule))
            ->lookup('cual es la estadistica de delitos para el valle del cauca en el 2026');

        $this->assertStringContainsString('179', $text);
        $this->assertStringContainsString('8,2', $text);
        $this->assertStringContainsString('9.009', $text);
        $this->assertStringContainsString('Fuente: example.com', $text);
        $this->assertStringContainsString('Fuente: example.org', $text);
        $this->assertStringNotContainsString('lluvias', $text);
        $this->assertStringNotContainsString('news.google.com', $text);
    }

    public function test_a_crime_headline_is_kept_when_the_page_does_not_state_the_figure(): void
    {
        Http::preventStrayRequests();
        Http::fake(function ($request) {
            $url = $request->url();

            if (str_contains($url, 'duckduckgo.com')) {
                return Http::response('bloqueo', 202);
            }

            if (str_contains($url, 'news.google.com/rss/search')) {
                return Http::response(
                    '<rss><channel><item><title>Valle del Cauca cierra el semestre con 524 homicidios y un descenso del 6 % - El Tiempo</title><link>https://news.google.com/rss/articles/HOMI</link><pubDate>Fri, 03 Jul 2026 15:49:00 GMT</pubDate></item></channel></rss>',
                    200,
                );
            }

            if (str_contains($url, 'news.google.com/rss/articles/')) {
                return Http::response('<div data-n-a-ts="1700000000" data-n-a-sg="abc"></div>', 200);
            }

            if (str_contains($url, 'batchexecute')) {
                $body = ")]}'\n\n".json_encode([[
                    'wrb.fr',
                    'Fbv4je',
                    json_encode(['garturlres', 'https://example.com/nota', 1], JSON_UNESCAPED_SLASHES),
                ]], JSON_UNESCAPED_SLASHES);

                return Http::response($body, 200);
            }

            if (str_contains($url, 'example.com')) {
                return Http::response('<html><body><p>Nota publicada sin repetir la cifra pedida.</p></body></html>', 200);
            }

            return Http::response('', 404);
        });

        $text = (new DuckDuckGoPublicFactLookup(new SecretRedactionRule))
            ->lookup('cual es la estadistica de delitos para el valle del cauca en el 2026');

        $this->assertStringContainsString('524', $text);
        $this->assertStringContainsString('homicidios', $text);
        $this->assertStringContainsString('Fuente: El Tiempo', $text);
        $this->assertStringNotContainsString('sin repetir', $text);
    }
}

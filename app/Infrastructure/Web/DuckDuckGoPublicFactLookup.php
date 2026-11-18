<?php

declare(strict_types=1);

namespace App\Infrastructure\Web;

use App\Domain\Assistant\Contracts\PublicFactSource;
use App\Domain\Security\Rules\SecretRedactionRule;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

final readonly class DuckDuckGoPublicFactLookup implements PublicFactSource
{
    private const SIGNAL = '/\b(investiga|investigue|busca|busque|qu[eé]\s+es|qui[eé]n\s+es|a\s+qu[eé]\s+se\s+dedica|informaci[oó]n\s+(de|sobre|acerca))\b/iu';

    private const TASK = '/\b(diseñ|implement|c[oó]digo|funci[oó]n|clase|repositorio|endpoint|laravel|php|sql|javascript|python|bug|error|refactor|programa|script|arregla|corrige|escribe|redacta|traduce|resume)\b/iu';

    private const SMALLTALK = '/^(hola|buenas|buenos\s+d[ií]as|buenas\s+tardes|buenas\s+noches|gracias|ok|vale|hey|hi)\b/iu';

    public function __construct(
        private SecretRedactionRule $secrets,
    ) {}

    public function lookup(string $question): string
    {
        $query = $this->query($question);

        if ($query === '') {
            return '';
        }

        try {
            if ($this->wantsWeb($question)) {
                $web = $this->webPages($query);

                if ($web !== '' && (! $this->statistic($query) || $this->statesCount($web, $query))) {
                    return $web;
                }
            }

            if ($this->plate($query) || ($this->statistic($query) && ! $this->population($query))) {
                $web = $this->newsPages($query);

                if ($web !== '') {
                    return $web;
                }
            }

            $sourced = $this->sourced($query);

            if ($sourced !== '') {
                return $sourced;
            }

            $best = '';
            $bestScore = -1;
            $reads = 0;

            foreach ($this->search($query) as $url) {
                if ($reads === 2) {
                    break;
                }

                $text = $this->page($url, $query);
                $reads++;

                if ($text === '') {
                    continue;
                }

                $score = $this->overlap($text, $query);

                if ($score > $bestScore) {
                    $best = $text;
                    $bestScore = $score;
                }

                if ($bestScore >= 2) {
                    break;
                }
            }

            return $best;
        } catch (Throwable) {
            return '';
        }
    }

    public function applies(string $question): bool
    {
        return $this->query($question) !== '';
    }

    private function query(string $question): string
    {
        $question = trim($this->secrets->redact($question));

        if ($question === '' || mb_strlen($question) > 400 || str_contains($question, '```')) {
            return '';
        }

        if (preg_match(self::TASK, $question) === 1 || preg_match(self::SMALLTALK, $question) === 1) {
            return '';
        }

        $query = trim((string) preg_replace('/\s+/u', ' ', $question));

        if (preg_match(self::SIGNAL, $question) === 1 || preg_match('/pico\s+y\s+placa/iu', $query) === 1 || $this->counted($query)) {
            $subject = trim((string) preg_replace(self::SIGNAL, ' ', $query));
            $subject = trim((string) preg_replace('/\s+/u', ' ', $subject));

            if (mb_strlen($subject) < 3) {
                $subject = $query;
            }

            return mb_substr($subject, 0, 180);
        }

        $named = $this->names($query);

        return $named === '' ? '' : mb_substr($named, 0, 180);
    }

    private function names(string $query): string
    {
        if (mb_strlen($query) > 80) {
            return '';
        }

        $words = preg_split('/\s+/u', $query) ?: [];

        if (count($words) < 2 || count($words) > 8) {
            return '';
        }

        $stop = [
            'el', 'la', 'los', 'las', 'un', 'una', 'de', 'del', 'y', 'o', 'en', 'con', 'por', 'para',
            'que', 'qué', 'como', 'cómo', 'esta', 'está', 'estas', 'estás', 'hola', 'gracias', 'bien',
            'mal', 'si', 'sí', 'no', 'me', 'te', 'se', 'mi', 'tu', 'su', 'es', 'son', 'hay', 'al', 'lo',
        ];
        $kept = [];

        foreach ($words as $word) {
            $clean = trim($word, ".,;:!?¡¿\"'");

            if ($clean === '' || in_array(mb_strtolower($clean), $stop, true)) {
                continue;
            }

            $kept[] = $clean;
        }

        if (count($kept) < 2) {
            return '';
        }

        return implode(' ', $kept);
    }

    private function sourced(string $query): string
    {
        if ($this->population($query)) {
            $wiki = $this->wikiExtract($query);

            if ($wiki !== '') {
                return $wiki;
            }
        }

        if ($this->figure($query)) {
            $news = $this->news($query);

            if ($news !== '') {
                return $news;
            }
        }

        $wiki = $this->wikiExtract($query);

        if ($wiki !== '') {
            return $wiki;
        }

        if (! $this->figure($query)) {
            $news = $this->news($query);

            if ($news !== '') {
                return $news;
            }
        }

        return $this->duckAbstract($query);
    }

    private function figure(string $query): bool
    {
        return preg_match('/\b(cu[aá]nt[oa]s?|tasa|porcentaje|habitantes|poblaci[oó]n|desempleo|desocupaci[oó]n|homicid)/iu', $query) === 1;
    }

    private function statistic(string $query): bool
    {
        return preg_match('/estad[ií]stic|delito|homicid|hurto|extorsi|secuestro|lesion/iu', $query) === 1;
    }

    private function counted(string $query): bool
    {
        return $this->statistic($query) || preg_match('/\b(tasa|porcentaje|desempleo|desocupaci[oó]n)\b/iu', $query) === 1;
    }

    private function winner(string $query): bool
    {
        return preg_match('/\b(gan[oó]|campe[oó]n|ganador)/iu', $query) === 1;
    }

    private function plate(string $query): bool
    {
        return preg_match('/pico\s+y\s+placa/iu', $query) === 1;
    }

    private function wantsWeb(string $query): bool
    {
        if ($this->plate($query) || $this->figure($query) || $this->statistic($query) || $this->winner($query)) {
            return true;
        }

        if (preg_match('/\b(hoy|horario|precio|empresa|d[oó]nde)\b/iu', $query) === 1) {
            return true;
        }

        return preg_match('/qu[eé]\s+es|qui[eé]n\s+es/iu', $query) !== 1;
    }

    private function webPages(string $query): string
    {
        $best = '';
        $bestScore = 0;
        $reads = 0;
        $attempts = 0;
        $seen = [];

        foreach ($this->duckLinks($query) as $url) {
            if ($reads === 2 || $attempts === 4) {
                break;
            }

            $host = strtolower((string) parse_url($url, PHP_URL_HOST));
            $host = preg_replace('/^www\./', '', $host) ?? $host;

            if ($host === '' || isset($seen[$host])) {
                continue;
            }

            $seen[$host] = true;
            $attempts++;
            $text = $this->readArticle($url, $query);

            if ($text === '') {
                continue;
            }

            $reads++;

            $score = $this->overlap($text, $query);

            if (preg_match('/\d{1,2}:\d{2}/', $text) === 1) {
                $score += 3;
            }

            if ($this->statesPlates($text)) {
                $score += 4;
            }

            if ($score > $bestScore) {
                $best = $text;
                $bestScore = $score;
            }
        }

        return $best;
    }

    private function readArticle(string $url, string $query): string
    {
        $current = $url;

        for ($hop = 0; $hop < 3; $hop++) {
            if (! $this->allowed($current)) {
                return '';
            }

            $response = $this->get($current);

            if (in_array($response->status(), [301, 302, 303, 307, 308], true)) {
                $location = $response->header('Location');
                $current = is_string($location) ? $this->absolute($current, $location) : '';

                continue;
            }

            if (! $response->successful()) {
                return '';
            }

            return $this->article($response->body(), $query, $current);
        }

        return '';
    }

    private function article(string $body, string $query, string $url): string
    {
        $body = substr($body, 0, 350_000);
        $candidates = [];

        if (preg_match('/<title>(.*?)<\/title>/is', $body, $titleTag) === 1) {
            $candidates[] = $this->plain($titleTag[1]);
        }

        if (preg_match('/<meta\b[^>]*(?:name|property)=["\'](?:description|og:description)["\'][^>]*content=["\']([^"\']+)["\']/i', $body, $meta) === 1) {
            $candidates[] = $this->plain($meta[1]);
        }

        if (preg_match_all('/<script\b[^>]*>(.*?)<\/script>/is', $body, $scripts) > 0) {
            foreach ($scripts[1] as $script) {
                $script = $this->unicodeText($script);

                if (preg_match_all('/"text"\s*:\s*"((?:[^"\\\\]|\\\\.){30,500})"/', $script, $answers) > 0) {
                    foreach ($answers[1] as $raw) {
                        $decoded = json_decode('"'.$raw.'"');
                        $candidates[] = is_string($decoded) ? $this->plain($decoded) : $this->plain($raw);
                    }
                }

                if (preg_match('/[^"\\\\]{30,260}(?:termin[ae]n?|placas\s+terminadas)[^"\\\\]{0,260}/u', $script, $slice) === 1) {
                    $candidates[] = $this->plain($slice[0]);
                }

                if ($this->statistic($query) && preg_match('/[^"\\\\]{20,220}(?:homicid|hurto|extorsi|delito|secuestro)[^"\\\\]{0,220}/u', $script, $crime) === 1) {
                    $candidates[] = $this->plain($crime[0]);
                }
            }
        }

        $visible = preg_replace('/<(script|style|noscript|nav|header|form|aside)\b[^>]*>.*?<\/\1>/is', ' ', $body) ?? $body;
        $footer = '';

        if (preg_match('/<footer\b[^>]*>(.*?)<\/footer>/is', $body, $footerMatch) === 1) {
            $footer = $this->plain($footerMatch[1]);
        }

        $paragraphsOnly = [];

        if (preg_match_all('/<p\b[^>]*>(.*?)<\/p>/is', $visible, $paragraphs) > 0) {
            foreach ($paragraphs[1] as $piece) {
                $plain = $this->plain($piece);

                if (mb_strlen($plain) >= 40 && ! $this->noiseText($plain)) {
                    $paragraphsOnly[] = $plain;
                    $candidates[] = $plain;
                }
            }
        }

        $chosen = '';

        if (count($paragraphsOnly) > 0 && count($paragraphsOnly) <= 3) {
            $joined = implode(' ', $paragraphsOnly);
            $needsDigit = $this->plate($query) || $this->figure($query);

            if ($this->overlap($joined, $query) >= 1 && (! $needsDigit || preg_match('/\d/u', $joined) === 1)) {
                if (! $this->plate($query) || $this->statesPlates($joined)) {
                    $chosen = $joined;
                }
            }
        }

        if ($chosen === '' || $this->overlap($chosen, $query) < 1) {
            $ranked = [];

            foreach ($candidates as $text) {
                $plates = $this->statesPlates($text);

                if ($this->noiseText($text) || ($this->overlap($text, $query) < 1 && ! $plates)) {
                    continue;
                }

                if (($this->plate($query) || $this->figure($query)) && preg_match('/\d/u', $text) !== 1) {
                    continue;
                }

                $rank = $this->overlap($text, $query);

                if (preg_match('/\d{1,2}:\d{2}/', $text) === 1) {
                    $rank += 3;
                }

                if ($plates || ($this->statistic($query) && $this->statesCount($text, $query))) {
                    $rank += 4;
                }

                $ranked[] = ['rank' => $rank, 'text' => $text];
            }

            if ($ranked === []) {
                return '';
            }

            usort($ranked, fn (array $left, array $right): int => $right['rank'] <=> $left['rank']);
            $chosen = $ranked[0]['text'];
        }

        if (($this->plate($query) || $this->figure($query)) && preg_match('/\d/u', $chosen) !== 1) {
            return '';
        }

        if ($this->plate($query)) {
            $kept = [];

            foreach (preg_split('/(?<=[.!?])\s+/u', $chosen) ?: [] as $sentence) {
                $sentence = trim($sentence);

                if ($sentence !== '' && ($this->statesPlates($sentence) || preg_match('/\d{1,2}:\d{2}/', $sentence) === 1)) {
                    $kept[] = $sentence;
                }
            }

            if ($kept !== []) {
                $chosen = implode(' ', $kept);
            }

            $chosen = preg_replace(
                '/^las\s+(\d{1,2}:\d{2} de la mañana hasta las \d{1,2}:\d{2} de la noche)\./u',
                'Horario: $1.',
                $chosen,
            ) ?? $chosen;
        }

        if ($footer !== '' && ! $this->noiseText($footer) && ! str_contains($chosen, $footer)) {
            $chosen .= ' '.$footer;
        }

        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        $host = preg_replace('/^www\./', '', $host) ?? $host;

        return "Fuente: {$host}\n".mb_substr($chosen, 0, 700);
    }

    private function statesPlates(string $text): bool
    {
        return preg_match('/terminad[ao]s?\s+en\s+\d|terminen\s+en\s+\d|\b\d\s+y\s+\d\b/iu', $text) === 1;
    }

    private function noiseText(string $text): bool
    {
        return preg_match('/cookie|suscr[ií]b|bolet[ií]n|cerrar sesi[oó]n|aceptando esta/iu', $text) === 1;
    }

    private function unicodeText(string $text): string
    {
        $decoded = preg_replace_callback(
            '/\\\\u([0-9a-fA-F]{4})/',
            fn (array $match): string => mb_chr((int) hexdec($match[1]), 'UTF-8'),
            $text,
        );

        return is_string($decoded) ? $decoded : $text;
    }

    private function wikiExtract(string $query): string
    {
        $subjects = $this->subjects($query);

        if ($subjects === []) {
            return '';
        }

        $response = $this->get('https://es.wikipedia.org/w/api.php', [
            'action' => 'query',
            'list' => 'search',
            'srsearch' => implode(' ', $subjects),
            'srlimit' => 5,
            'format' => 'json',
        ]);

        if ($response->status() !== 200) {
            return '';
        }

        $hits = $response->json('query.search');

        if (! is_array($hits)) {
            return '';
        }

        foreach ($hits as $hit) {
            if (! is_array($hit)) {
                continue;
            }

            $title = trim((string) ($hit['title'] ?? ''));

            if ($title === '' || ! $this->titleCovers($title, $subjects)) {
                continue;
            }

            $fact = $this->articleFact($query, $title);

            if ($fact !== '') {
                return $fact;
            }
        }

        return '';
    }

    private function articleFact(string $query, string $title): string
    {
        $wikitext = $this->wikiSection($title);

        if ($this->population($query)) {
            $card = $this->populationCard($title, $wikitext);

            if ($card !== '') {
                return $card;
            }
        }

        if ($this->winner($query)) {
            return $this->championSentence($title, $wikitext);
        }

        $extract = $this->wikiPlain($title);

        if ($extract === '' || ($this->figure($query) && ! $this->hasMeasure($extract, $query))) {
            return '';
        }

        $split = explode("\n", $extract, 2);

        if (count($split) === 1) {
            return $extract;
        }

        return $split[0]."\n".mb_substr($split[1], 0, 700);
    }

    private function population(string $query): bool
    {
        return preg_match('/habitant|poblaci/iu', $query) === 1;
    }

    private function wikiSection(string $title): string
    {
        $response = $this->get('https://es.wikipedia.org/w/api.php', [
            'action' => 'parse',
            'page' => $title,
            'prop' => 'wikitext',
            'section' => '0',
            'format' => 'json',
        ]);

        if ($response->status() !== 200) {
            return '';
        }

        $block = $response->json('parse.wikitext');

        if (! is_array($block)) {
            return '';
        }

        return (string) ($block['*'] ?? '');
    }

    private function populationCard(string $title, string $wikitext): string
    {
        if (preg_match('/\|\s*población\s*=\s*([0-9][0-9.]*)/u', $wikitext, $population) !== 1) {
            return '';
        }

        if (preg_match('/\|\s*población_año\s*=\s*(20[0-9]{2})/u', $wikitext, $year) !== 1) {
            return '';
        }

        $lines = ["Población {$year[1]}: {$population[1]}."];

        if (preg_match('/\|\s*población_urb\s*=\s*([0-9][0-9.]*)/u', $wikitext, $urban) === 1) {
            $lines[] = "Población urbana: {$urban[1]}.";
        }

        if (preg_match('/\|\s*población_met\s*=\s*([0-9][0-9.]*)/u', $wikitext, $metro) === 1) {
            $lines[] = "Población metropolitana: {$metro[1]}.";
        }

        $cite = $this->daneCite($wikitext);
        $slug = rawurlencode(str_replace(' ', '_', $title));

        return "Fuente: es.wikipedia.org/wiki/{$slug}\n".implode(' ', $lines).($cite === '' ? '' : "\n".$cite);
    }

    private function daneCite(string $wikitext): string
    {
        $flat = preg_replace('/\s+/u', ' ', $wikitext) ?? $wikitext;

        if (preg_match('/\{\{cita web\b[^{}]*dane\.gov\.co[^{}]*\}\}/iu', $flat, $match) !== 1) {
            return '';
        }

        $block = $match[0];
        $parts = ['Cita de la ficha:'];

        if (preg_match('/\|t[ií]tulo=([^|]+)/u', $block, $label) === 1) {
            $parts[] = trim($label[1]).'.';
        }

        if (preg_match('/\|url=(\S+)/u', $block, $url) === 1) {
            $parts[] = rtrim($url[1], '|}');
        }

        if (preg_match('/\|fechaacceso=([^|}]+)/u', $block, $seen) === 1) {
            $parts[] = 'Consulta: '.trim($seen[1]).'.';
        }

        return implode(' ', $parts);
    }

    private function championSentence(string $title, string $wikitext): string
    {
        $plain = $this->wikiToPlain($wikitext);

        if (preg_match('/El campe[oó]n fue [^.]{10,500}\./u', $plain, $match) !== 1) {
            return '';
        }

        $slug = rawurlencode(str_replace(' ', '_', $title));

        return "Fuente: es.wikipedia.org/wiki/{$slug}\n".$match[0];
    }

    private function wikiToPlain(string $wikitext): string
    {
        $text = preg_replace('/<ref\b[^>]*>.*?<\/ref>/is', ' ', $wikitext) ?? $wikitext;
        $text = preg_replace('/<ref\b[^>]*\/>/i', ' ', $text) ?? $text;
        $text = preg_replace('/\{\{[^{}]*\}\}/', ' ', $text) ?? $text;
        $text = preg_replace('/\[\[(?:[^|\]]+\|)?([^\]]+)\]\]/u', '$1', $text) ?? $text;
        $text = str_replace(["'''", "''"], '', $text);

        return trim(preg_replace('/\s+/u', ' ', $this->plain($text)) ?? $text);
    }

    private function hasMeasure(string $text, string $query): bool
    {
        $pattern = $this->measurePattern($query);

        if ($pattern !== '' && preg_match('/'.$pattern.'/iu', $text) !== 1) {
            return false;
        }

        if (preg_match('/desempleo|desocup|tasa|porcentaje/iu', $query) === 1) {
            return preg_match('/\d\s*%|\d[.,]\d/u', $text) === 1;
        }

        if (preg_match('/habitant|poblaci/iu', $query) === 1) {
            return preg_match('/\d{6,}|\d{1,3}(?:\.\d{3}){2,}/u', $text) === 1;
        }

        return preg_match('/\d/u', $text) === 1;
    }

    private function measurePattern(string $query): string
    {
        if (preg_match('/desempleo|desocup/iu', $query) === 1) {
            return 'desempleo|desocup';
        }

        if (preg_match('/homicid/iu', $query) === 1) {
            return 'homicid';
        }

        if (preg_match('/habitant|poblaci/iu', $query) === 1) {
            return 'habitant|poblaci';
        }

        if (preg_match('/tasa|porcentaje/iu', $query) === 1) {
            return 'tasa|%';
        }

        return '';
    }

    private function wikiPlain(string $title): string
    {
        $response = $this->get('https://es.wikipedia.org/w/api.php', [
            'action' => 'query',
            'prop' => 'extracts',
            'explaintext' => '1',
            'exchars' => '1400',
            'redirects' => '1',
            'titles' => $title,
            'format' => 'json',
        ]);

        if ($response->status() !== 200) {
            return '';
        }

        $pages = $response->json('query.pages');

        if (! is_array($pages)) {
            return '';
        }

        foreach ($pages as $page) {
            if (! is_array($page)) {
                continue;
            }

            $text = trim((string) ($page['extract'] ?? ''));

            if (mb_strlen($text) < 80) {
                continue;
            }

            $slug = rawurlencode(str_replace(' ', '_', (string) ($page['title'] ?? $title)));

            return "Fuente: es.wikipedia.org/wiki/{$slug}\n".mb_substr($text, 0, 1400);
        }

        return '';
    }

    /**
     * @param  list<string>  $subjects
     */
    private function titleCovers(string $title, array $subjects): bool
    {
        $folded = $this->fold($title);

        foreach ($subjects as $token) {
            if (! str_contains($folded, $token)) {
                return false;
            }
        }

        return $subjects !== [];
    }

    /**
     * @return list<string>
     */
    private function subjects(string $query): array
    {
        $skip = [
            'cuantos', 'cuantas', 'cuanto', 'cuanta', 'habitantes', 'tiene', 'tienen', 'donde', 'queda',
            'quien', 'quienes', 'gano', 'que', 'es', 'cual', 'cuales', 'tasa', 'hubo', 'hay', 'esta',
            'distancia', 'maximo', 'maxima', 'goleador', 'seleccion', 'numero', 'ano', 'ultimo',
            'ultima', 'reciente', 'actual', 'hoy', 'fue', 'son', 'del', 'las', 'los', 'una', 'unos',
            'sobre', 'informacion', 'dedica', 'poblacion', 'estadistica', 'estadisticas',
        ];
        $subjects = [];

        foreach ($this->tokens($query) as $token) {
            if (in_array($token, $skip, true)) {
                continue;
            }

            $subjects[] = $token;
        }

        return $subjects;
    }

    private function newsPages(string $query): string
    {
        try {
            $response = $this->get('https://news.google.com/rss/search', [
                'q' => $query,
                'hl' => 'es-419',
                'gl' => 'CO',
                'ceid' => 'CO:es-419',
            ], 8);
        } catch (Throwable) {
            return '';
        }

        if ($response->status() !== 200) {
            return '';
        }

        $xml = @simplexml_load_string($response->body());

        if ($xml === false || ! isset($xml->channel->item)) {
            return '';
        }

        $subjects = $this->subjects($query);
        $statistic = $this->statistic($query) && ! $this->plate($query);
        $best = '';
        $bestScore = 0;
        $cards = [];
        $attempts = 0;
        $limit = $statistic ? 3 : 3;

        foreach ($xml->channel->item as $item) {
            if ($attempts === $limit || count($cards) === 2) {
                break;
            }

            $title = $this->plain((string) $item->title);
            $link = trim((string) $item->link);
            $covers = $statistic ? $this->titleTouches($title, $subjects) : $this->titleCovers($title, $subjects);

            if ($link === '' || ! $covers || ! str_contains($link, 'news.google.com')) {
                continue;
            }

            $attempts++;

            try {
                $url = $this->publisherUrl($link);
                $text = $url === '' ? '' : $this->readArticle($url, $query);
            } catch (Throwable) {
                continue;
            }

            if ($statistic) {
                $line = $text === '' ? '' : $this->figureLines($text, $query);

                if ($line === '' && $this->statesCount($title, $query)) {
                    $outlet = $this->outlet($title);
                    $source = $outlet !== '' ? $outlet : 'news.google.com';
                    $line = 'Fuente: '.$source.'. '.$this->headline($title, $outlet);
                }

                if ($line !== '') {
                    $cards[] = $line;
                }

                continue;
            }

            if ($text === '') {
                continue;
            }

            if (! $this->statesPlates($text)) {
                continue;
            }

            $score = $this->overlap($text, $query) + 4;

            if (preg_match('/\d{1,2}:\d{2}/', $text) === 1) {
                $score += 3;
            }

            if ($score > $bestScore) {
                $best = $text;
                $bestScore = $score;
            }
        }

        if ($statistic) {
            return implode("\n", $cards);
        }

        return $best;
    }

    private function figureLines(string $page, string $query): string
    {
        if (! str_contains($page, "\n")) {
            return '';
        }

        [$head, $body] = explode("\n", $page, 2);
        $host = trim(substr($head, strlen('Fuente:')));
        $lines = [];

        foreach (preg_split('/(?<=[.!?])\s+/u', $body) ?: [] as $sentence) {
            $sentence = trim($sentence);

            if ($sentence === '' || $this->noiseText($sentence) || ! $this->statesCount($sentence, $query)) {
                continue;
            }

            $lines[] = $sentence;

            if (count($lines) === 2) {
                break;
            }
        }

        if ($host === '' || $lines === []) {
            return '';
        }

        return 'Fuente: '.$host.'. '.implode(' ', $lines);
    }

    /**
     * @param  list<string>  $subjects
     */
    private function titleTouches(string $title, array $subjects): bool
    {
        $places = [];

        foreach ($subjects as $token) {
            if (preg_match('/^(?:19|20)\d{2}$/', $token) === 1) {
                continue;
            }

            if (preg_match('/delito|homicid|hurto|extorsi|secuestro|lesion|estadistic|desempleo|desocup|tasa|porcentaje/', $token) === 1) {
                continue;
            }

            $places[] = $token;
        }

        if ($places === []) {
            return preg_match('/delito|homicid|hurto|extorsi|secuestro|lesion|desempleo|desocup|tasa/iu', $title) === 1;
        }

        $folded = $this->fold($title);

        foreach ($places as $token) {
            if (str_contains($folded, $token)) {
                return true;
            }
        }

        return false;
    }

    private function statesCount(string $text, string $query): bool
    {
        $pattern = $this->measurePattern($query);

        if ($pattern === '' && $this->statistic($query)) {
            $pattern = 'delito|homicid|hurto|extorsi|secuestro|lesion';
        }

        if ($pattern !== '' && preg_match('/'.$pattern.'/iu', $text) !== 1) {
            return false;
        }

        $stripped = preg_replace('/\b(?:19|20)\d{2}\b/u', ' ', $text) ?? $text;

        return preg_match('/\d\s*%|\d+[.,]\d+|\b\d{1,3}(?:\.\d{3})+\b|\b\d{2,4}\b/u', $stripped) === 1;
    }

    private function publisherUrl(string $link): string
    {
        $page = $this->open($link);

        if (! $page->successful()) {
            return '';
        }

        $body = $page->body();

        if (preg_match('/data-n-a-sg=["\']([^"\']+)["\']/', $body, $signature) !== 1
            || preg_match('/data-n-a-ts=["\']([^"\']+)["\']/', $body, $timestamp) !== 1) {
            return '';
        }

        $id = basename((string) parse_url($link, PHP_URL_PATH));
        $inner = json_encode([
            'garturlreq',
            [
                ['X', 'X', ['X', 'X'], null, null, 1, 1, 'US:en', null, 1, null, null, null, null, null, 0, 1],
                'X', 'X', 1, [1, 1, 1], 1, 1, null, 0, 0, null, 0,
            ],
            $id,
            (int) $timestamp[1],
            html_entity_decode($signature[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'),
        ], JSON_UNESCAPED_SLASHES);

        if (! is_string($inner)) {
            return '';
        }

        $payload = json_encode([[['Fbv4je', $inner]]], JSON_UNESCAPED_SLASHES);

        if (! is_string($payload)) {
            return '';
        }

        $response = Http::timeout(8)
            ->withOptions(['allow_redirects' => false])
            ->withHeaders(['User-Agent' => 'WCodexIA/1.0', 'Accept' => 'text/html,application/json'])
            ->asForm()
            ->post(
                'https://news.google.com/_/DotsSplashUi/data/batchexecute?rpcids=Fbv4je&source-path='.rawurlencode('/rss/articles/'.$id),
                ['f.req' => $payload],
            );

        if (! $response->successful()) {
            return '';
        }

        $url = $this->decodedUrl($response->body());

        return $url !== '' && $this->allowed($url) ? $url : '';
    }

    private function decodedUrl(string $body): string
    {
        $raw = ltrim($body, ")]}'\n\r ");

        foreach (preg_split("/\r?\n\r?\n/", $raw) ?: [] as $chunk) {
            $chunk = trim($chunk);

            if ($chunk === '' || ! str_starts_with($chunk, '[')) {
                continue;
            }

            $parsed = json_decode($chunk, true);
            $encoded = is_array($parsed) ? ($parsed[0][2] ?? null) : null;

            if (! is_string($encoded)) {
                continue;
            }

            $url = $this->firstPublicUrl(json_decode($encoded, true));

            if ($url !== '') {
                return $url;
            }
        }

        return '';
    }

    private function firstPublicUrl(mixed $value): string
    {
        if (is_string($value) && preg_match('/^https?:\/\//', $value) === 1) {
            $host = strtolower((string) parse_url($value, PHP_URL_HOST));

            if ($host !== '' && ! str_contains($host, 'google.') && ! str_contains($host, 'gstatic.')) {
                return $value;
            }
        }

        if (! is_array($value)) {
            return '';
        }

        foreach ($value as $item) {
            $url = $this->firstPublicUrl($item);

            if ($url !== '') {
                return $url;
            }
        }

        return '';
    }

    private function open(string $url, int $timeout = 8): Response
    {
        $current = $url;
        $response = $this->get($current, [], $timeout);

        for ($hop = 0; $hop < 3; $hop++) {
            if (! in_array($response->status(), [301, 302, 303, 307, 308], true)) {
                return $response;
            }

            $location = $response->header('Location');

            if (! is_string($location) || $location === '') {
                return $response;
            }

            $current = $this->absolute($current, $location);

            if ($current === '' || ! $this->allowed($current)) {
                return $response;
            }

            $response = $this->get($current, [], $timeout);
        }

        return $response;
    }

    private function news(string $query): string
    {
        $response = $this->get('https://news.google.com/rss/search', [
            'q' => $query,
            'hl' => 'es-419',
            'gl' => 'CO',
            'ceid' => 'CO:es-419',
        ]);

        if ($response->status() !== 200) {
            return '';
        }

        $xml = @simplexml_load_string($response->body());

        if ($xml === false || ! isset($xml->channel->item)) {
            return '';
        }

        $subjects = $this->subjects($query);
        $rows = [];

        foreach ($xml->channel->item as $item) {
            $title = $this->plain((string) $item->title);
            $when = trim((string) $item->pubDate);
            $line = trim($when.' '.$title);

            if (mb_strlen($line) < 40 || ! $this->titleCovers($line, $subjects)) {
                continue;
            }

            if ($this->figure($query) && ! $this->hasMeasure($title, $query)) {
                continue;
            }

            if ($this->plate($query) && preg_match('/terminad[ao]s?\s+en\s+\d|\b\d\s+y\s+\d\b/iu', $title) !== 1) {
                continue;
            }

            $rows[] = [
                'stamp' => strtotime($when) ?: 0,
                'line' => $this->newsLine($title, $when),
            ];
        }

        if ($rows === []) {
            return '';
        }

        usort($rows, fn (array $left, array $right): int => $right['stamp'] <=> $left['stamp']);
        $lines = array_map(
            fn (array $row): string => $row['line'],
            array_slice($rows, 0, 2),
        );

        return implode("\n", $lines);
    }

    private function newsLine(string $title, string $published): string
    {
        $outlet = $this->outlet($title);
        $headline = $this->headline($title, $outlet);
        $when = $this->spanishWhen($published);
        $medium = $outlet === '' ? 'news.google.com' : $outlet.', vía news.google.com';
        $date = $when === '' ? '' : " Publicado: {$when}.";

        return mb_substr("Fuente: {$medium}.{$date} {$headline}", 0, 360);
    }

    private function outlet(string $title): string
    {
        $parts = preg_split('/\s[-|]\s/u', $title) ?: [];
        $last = trim((string) end($parts));

        if ($last === '' || $last === $title || mb_strlen($last) > 40) {
            return '';
        }

        return $last;
    }

    private function headline(string $title, string $outlet): string
    {
        $suffix = ' - '.$outlet;

        if ($outlet !== '' && str_ends_with($title, $suffix)) {
            return trim(mb_substr($title, 0, mb_strlen($title) - mb_strlen($suffix)));
        }

        return $title;
    }

    private function spanishWhen(string $published): string
    {
        $stamp = strtotime($published);

        if ($stamp === false) {
            return '';
        }

        $months = [
            1 => 'enero', 2 => 'febrero', 3 => 'marzo', 4 => 'abril', 5 => 'mayo', 6 => 'junio',
            7 => 'julio', 8 => 'agosto', 9 => 'septiembre', 10 => 'octubre', 11 => 'noviembre', 12 => 'diciembre',
        ];
        $zone = new \DateTimeZone('America/Bogota');
        $moment = (new \DateTimeImmutable('@'.$stamp))->setTimezone($zone);
        $day = $moment->format('j');
        $month = $months[(int) $moment->format('n')];
        $year = $moment->format('Y');
        $hour = $moment->format('H:i');

        return "{$day} de {$month} de {$year}, {$hour} hora de Colombia";
    }

    private function duckAbstract(string $query): string
    {
        $response = $this->get('https://api.duckduckgo.com/', [
            'q' => $query,
            'format' => 'json',
            'no_html' => '1',
        ]);

        if ($response->status() !== 200) {
            return '';
        }

        $text = trim((string) $response->json('AbstractText'));
        $url = (string) $response->json('AbstractURL');

        if (mb_strlen($text) < 80 || ! $this->titleCovers($text, $this->subjects($query)) || ! $this->allowed($url)) {
            return '';
        }

        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        $host = preg_replace('/^www\./', '', $host) ?? $host;

        return "Fuente: {$host}\n".mb_substr($text, 0, 1200);
    }

    /**
     * @return list<string>
     */
    private function search(string $query): array
    {
        $links = $this->duckLinks($query);

        if ($links === []) {
            $links = $this->wikiLinks($query);
        }

        $ranked = [];

        foreach ($links as $index => $url) {
            $ranked[] = ['url' => $url, 'rank' => $this->rank($url, $query), 'index' => $index];
        }

        usort(
            $ranked,
            fn (array $left, array $right): int => $right['rank'] <=> $left['rank'] ?: $left['index'] <=> $right['index'],
        );

        $links = [];
        $seen = [];

        foreach ($ranked as $item) {
            $host = strtolower((string) parse_url($item['url'], PHP_URL_HOST));
            $host = preg_replace('/^www\./', '', $host) ?? $host;

            if (isset($seen[$host])) {
                continue;
            }

            $seen[$host] = true;
            $links[] = $item['url'];
        }

        return array_slice($links, 0, 4);
    }

    /**
     * @return list<string>
     */
    private function duckLinks(string $query): array
    {
        $response = $this->get('https://html.duckduckgo.com/html/', ['q' => $query]);

        if ($response->status() !== 200) {
            return [];
        }

        preg_match_all('/uddg=([^&"\']+)/', $response->body(), $matches);
        $links = [];

        foreach ($matches[1] as $encoded) {
            $url = rawurldecode(html_entity_decode($encoded, ENT_QUOTES | ENT_HTML5, 'UTF-8'));

            if (! $this->allowed($url) || $this->noisy($url)) {
                continue;
            }

            $links[] = $url;

            if (count($links) === 6) {
                break;
            }
        }

        return $links;
    }

    /**
     * @return list<string>
     */
    private function wikiLinks(string $query): array
    {
        $response = $this->get('https://es.wikipedia.org/w/api.php', [
            'action' => 'opensearch',
            'search' => $query,
            'limit' => 1,
            'namespace' => 0,
            'format' => 'json',
        ]);

        if (! $response->successful()) {
            return [];
        }

        $payload = $response->json();
        $url = is_array($payload) ? ($payload[3][0] ?? null) : null;

        if (! is_string($url) || ! $this->allowed($url)) {
            return [];
        }

        return [$url];
    }

    private function page(string $url, string $query): string
    {
        $current = $url;

        for ($hop = 0; $hop < 3; $hop++) {
            if (! $this->allowed($current)) {
                return '';
            }

            $response = $this->get($current);

            if (in_array($response->status(), [301, 302, 303, 307, 308], true)) {
                $location = $response->header('Location');
                $current = is_string($location) ? $this->absolute($current, $location) : '';

                continue;
            }

            if (! $response->successful()) {
                return '';
            }

            return $this->excerpt($response->body(), $query, $current);
        }

        return '';
    }

    /**
     * @param  array<string, scalar>  $query
     */
    private function get(string $url, array $query = [], int $timeout = 4): Response
    {
        $pending = Http::timeout($timeout)
            ->withOptions(['allow_redirects' => false])
            ->withHeaders([
                'User-Agent' => 'WCodexIA/1.0',
                'Accept' => 'text/html,application/json',
            ]);

        return $query === [] ? $pending->get($url) : $pending->get($url, $query);
    }

    private function excerpt(string $body, string $query, string $url): string
    {
        $body = substr($body, 0, 200_000);
        $footer = '';

        if (preg_match('/<footer\b[^>]*>(.*?)<\/footer>/is', $body, $footerMatch) === 1) {
            $footer = $this->plain($footerMatch[1]);
        }

        $body = preg_replace('/<(script|style|noscript|nav|header|form|aside)\b[^>]*>.*?<\/\1>/is', ' ', $body) ?? $body;
        preg_match_all('/<p\b[^>]*>(.*?)<\/p>/is', $body, $matches);
        $chunks = [];

        foreach ($matches[1] as $piece) {
            $plain = $this->plain($piece);

            if (mb_strlen($plain) >= 24) {
                $chunks[] = $plain;
            }
        }

        if ($chunks === []) {
            $plain = $this->plain($body);
            $chunks = mb_strlen($plain) >= 80 ? [$plain] : [];
        }

        $joined = implode(' ', $chunks);

        if (mb_strlen($footer) >= 24 && ! str_contains($joined, $footer)) {
            $chunks[] = $footer;
        }

        $scored = [];

        foreach ($chunks as $index => $chunk) {
            $scored[] = ['index' => $index, 'score' => $this->overlap($chunk, $query), 'text' => $chunk];
        }

        usort(
            $scored,
            fn (array $left, array $right): int => $right['score'] <=> $left['score'] ?: $left['index'] <=> $right['index'],
        );

        $text = '';

        foreach ($scored as $chunk) {
            if (mb_strlen($text) >= 1800) {
                break;
            }

            $text = trim($text.' '.$chunk['text']);
        }

        if (mb_strlen($text) < 80) {
            return '';
        }

        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        $host = preg_replace('/^www\./', '', $host) ?? $host;

        return "Fuente: {$host}\n".mb_substr($text, 0, 1800);
    }

    private function plain(string $html): string
    {
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    private function noisy(string $url): bool
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        $host = preg_replace('/^www\./', '', $host) ?? $host;

        foreach (['facebook.com', 'instagram.com', 'twitter.com', 'x.com', 'tiktok.com', 'youtube.com', 'youtu.be', 'linkedin.com', 'pinterest.com'] as $blocked) {
            if ($host === $blocked || str_ends_with($host, '.'.$blocked)) {
                return true;
            }
        }

        return false;
    }

    private function rank(string $url, string $query): int
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        $host = preg_replace('/^www\./', '', $this->fold($host)) ?? $host;
        $score = 0;

        foreach ($this->tokens($query) as $token) {
            if (str_contains($host, $token)) {
                $score += 5;
            }
        }

        if (preg_match('/\.(?:com|org|gov|edu)\.co$/', $host) === 1) {
            $score += 2;
        }

        if (str_ends_with($host, 'wikipedia.org')) {
            $score += 1;
        }

        return $score;
    }

    private function overlap(string $text, string $query): int
    {
        $folded = $this->fold($text);
        $hits = 0;

        foreach ($this->tokens($query) as $token) {
            if (str_contains($folded, $token)) {
                $hits++;
            }
        }

        return $hits;
    }

    /**
     * @return list<string>
     */
    private function tokens(string $query): array
    {
        $words = preg_split('/\s+/u', $query) ?: [];
        $tokens = [];

        foreach ($words as $word) {
            $raw = trim($word, ".,;:!?¡¿\"'");
            $token = $this->fold($raw);

            if ($token === '' || in_array($token, ['para', 'como', 'esta', 'sobre', 'desde', 'entre'], true)) {
                continue;
            }

            $shortName = mb_strlen($raw) >= 2 && mb_strlen($raw) < 4 && mb_strtoupper($raw, 'UTF-8') === $raw;
            if (mb_strlen($token) >= 4 || $shortName) {
                $tokens[$token] = $token;
            }
        }

        return array_values($tokens);
    }

    private function fold(string $text): string
    {
        $text = mb_strtolower($text, 'UTF-8');

        return strtr($text, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n']);
    }

    private function allowed(string $url): bool
    {
        $parts = parse_url($url);

        if (! is_array($parts)) {
            return false;
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower((string) ($parts['host'] ?? ''));

        if (! in_array($scheme, ['http', 'https'], true) || $host === '' || isset($parts['user'])) {
            return false;
        }

        if ($host === 'localhost' || str_ends_with($host, '.local') || str_ends_with($host, '.internal')) {
            return false;
        }

        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return $this->publicIp($host);
        }

        if (! str_contains($host, '.')) {
            return false;
        }

        $records = @gethostbynamel($host);

        if (! is_array($records) || $records === []) {
            return false;
        }

        foreach ($records as $ip) {
            if (! $this->publicIp($ip)) {
                return false;
            }
        }

        return true;
    }

    private function publicIp(string $ip): bool
    {
        return filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE,
        ) !== false;
    }

    private function absolute(string $base, string $location): string
    {
        if (str_starts_with($location, 'http://') || str_starts_with($location, 'https://')) {
            return $location;
        }

        $parts = parse_url($base);

        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host'])) {
            return '';
        }

        $origin = $parts['scheme'].'://'.$parts['host'];

        return str_starts_with($location, '/') ? $origin.$location : $origin.'/'.$location;
    }
}

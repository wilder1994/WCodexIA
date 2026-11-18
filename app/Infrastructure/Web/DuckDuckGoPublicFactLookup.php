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

        if (preg_match(self::SIGNAL, $question) === 1) {
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

        if (! $response->successful()) {
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
    private function get(string $url, array $query = []): Response
    {
        return Http::timeout(4)
            ->withOptions(['allow_redirects' => false])
            ->withHeaders([
                'User-Agent' => 'WCodexIA/1.0',
                'Accept' => 'text/html,application/json',
            ])
            ->get($url, $query);
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

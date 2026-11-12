<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class EnsureApiToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $configured = (string) config('wcodex.api_token');
        $provided = (string) $request->bearerToken();

        if ($configured === '' || $provided === '' || ! hash_equals($configured, $provided)) {
            return response()->json([
                'message' => 'No autorizado.',
            ], 401);
        }

        return $next($request);
    }
}

<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class EnsureUserRole
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $role = (string) $request->user()?->role;

        if ($role === '' || ! in_array($role, $roles, true)) {
            abort(403);
        }

        return $next($request);
    }
}

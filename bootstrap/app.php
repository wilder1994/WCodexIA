<?php

use App\Domain\Assistant\Exceptions\LlmUnavailableException;
use App\Domain\Assistant\Exceptions\UnknownConversationException;
use App\Domain\Security\Exceptions\PolicyDeniedException;
use App\Domain\Security\Exceptions\WorkspaceBoundaryException;
use App\Http\Middleware\EnsureApiToken;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'wcodex.token' => EnsureApiToken::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(function (PolicyDeniedException $exception, Request $request) {
            if ($request->is('api/*')) {
                return response()->json([
                    'message' => $exception->getMessage(),
                    'code' => $exception->ruleCode,
                ], 403);
            }
        });

        $exceptions->render(function (WorkspaceBoundaryException $exception, Request $request) {
            if ($request->is('api/*')) {
                return response()->json([
                    'message' => $exception->getMessage(),
                    'code' => $exception->errorCode,
                ], 422);
            }
        });

        $exceptions->render(function (UnknownConversationException $exception, Request $request) {
            if ($request->is('api/*')) {
                return response()->json([
                    'message' => $exception->getMessage(),
                ], 404);
            }
        });

        $exceptions->render(function (LlmUnavailableException $exception, Request $request) {
            if ($request->is('api/*')) {
                return response()->json([
                    'message' => $exception->getMessage(),
                    'code' => 'llm_unavailable',
                ], 503);
            }
        });
    })->create();

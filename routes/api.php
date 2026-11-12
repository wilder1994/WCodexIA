<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\AssistantController;
use App\Http\Controllers\Api\V1\PolicyController;
use Illuminate\Support\Facades\Route;

Route::middleware(['wcodex.token', 'throttle:30,1'])->prefix('v1')->group(function (): void {
    Route::get('policies', PolicyController::class);
    Route::post('assistant/completions', AssistantController::class);
});

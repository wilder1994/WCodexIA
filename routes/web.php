<?php

declare(strict_types=1);

use App\Http\Controllers\AgentController;
use App\Http\Controllers\ChatController;
use Illuminate\Support\Facades\Route;

Route::get('/', [ChatController::class, 'index'])->name('chat.index');
Route::post('/c', [ChatController::class, 'store'])->name('chat.store');
Route::get('/c/{conversation}', [ChatController::class, 'show'])
    ->whereUuid('conversation')
    ->name('chat.show');
Route::get('/c/{conversation}/m/{message}', [ChatController::class, 'image'])
    ->whereUuid('conversation')
    ->whereUuid('message')
    ->name('chat.image');

Route::get('/agent', [AgentController::class, 'index'])->name('agent.index');
Route::post('/agent', [AgentController::class, 'propose'])->name('agent.propose');
Route::post('/agent/apply', [AgentController::class, 'apply'])->name('agent.apply');
Route::post('/agent/run', [AgentController::class, 'run'])->name('agent.run');

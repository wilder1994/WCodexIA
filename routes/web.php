<?php

declare(strict_types=1);

use App\Http\Controllers\AgentController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\ChatController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\SupervisionController;
use App\Http\Controllers\UserController;
use Illuminate\Support\Facades\Route;

Route::get('/', HomeController::class)->name('home');

Route::middleware('guest')->group(function (): void {
    Route::get('/login', [LoginController::class, 'create'])->name('login');
    Route::post('/login', [LoginController::class, 'store'])->middleware('throttle:10,1');
});

Route::post('/logout', [LoginController::class, 'destroy'])->middleware('auth')->name('logout');

Route::middleware(['auth', 'role:superadmin'])->group(function (): void {
    Route::get('/usuarios', [UserController::class, 'index'])->name('users.index');
    Route::get('/usuarios/nuevo', [UserController::class, 'create'])->name('users.create');
    Route::post('/usuarios', [UserController::class, 'store'])->name('users.store');
    Route::get('/usuarios/{user}/editar', [UserController::class, 'edit'])->name('users.edit');
    Route::put('/usuarios/{user}', [UserController::class, 'update'])->name('users.update');
    Route::delete('/usuarios/{user}', [UserController::class, 'destroy'])->name('users.destroy');

    Route::get('/agent', [AgentController::class, 'index'])->name('agent.index');
    Route::post('/agent', [AgentController::class, 'propose'])->name('agent.propose');
    Route::post('/agent/apply', [AgentController::class, 'apply'])->name('agent.apply');
    Route::post('/agent/run', [AgentController::class, 'run'])->name('agent.run');
});

Route::middleware(['auth', 'permission:chat'])->group(function (): void {
    Route::get('/chat', [ChatController::class, 'index'])->name('chat.index');
    Route::post('/c', [ChatController::class, 'store'])->name('chat.store');
    Route::delete('/c/{conversation}', [ChatController::class, 'destroy'])
        ->whereUuid('conversation')
        ->name('chat.destroy');
    Route::get('/c/{conversation}', [ChatController::class, 'show'])
        ->whereUuid('conversation')
        ->name('chat.show');
    Route::get('/c/{conversation}/m/{message}', [ChatController::class, 'image'])
        ->whereUuid('conversation')
        ->whereUuid('message')
        ->name('chat.image');
});

Route::middleware(['auth', 'permission:versions'])->group(function (): void {
    Route::get('/versiones', [SupervisionController::class, 'index'])->name('versions.index');
    Route::get('/versiones/datos', [SupervisionController::class, 'data'])->name('versions.data');
    Route::post('/versiones/{id}/decision', [SupervisionController::class, 'decide'])
        ->where('id', '[A-Za-z0-9._:-]+')
        ->name('versions.decide');
});

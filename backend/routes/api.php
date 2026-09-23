<?php

use App\Http\Controllers\Api\AgentController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ContactController;
use App\Http\Controllers\Api\InteractionController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    Route::post('/auth/login', [AuthController::class, 'login'])
        ->middleware('throttle:login')
        ->name('auth.login');

    Route::middleware('auth:sanctum')->group(function () {
        Route::post('/auth/logout', [AuthController::class, 'logout'])->name('auth.logout');
        Route::get('/auth/me', [AuthController::class, 'me'])->name('auth.me');

        Route::get('/agents', [AgentController::class, 'index'])->name('agents.index');

        Route::get('/contacts', [ContactController::class, 'index'])->name('contacts.index');
        Route::post('/contacts', [ContactController::class, 'store'])->name('contacts.store');
        Route::get('/contacts/{contact}', [ContactController::class, 'show'])->name('contacts.show');
        Route::patch('/contacts/{contact}', [ContactController::class, 'update'])->name('contacts.update');
        Route::delete('/contacts/{contact}', [ContactController::class, 'destroy'])->name('contacts.destroy');
        Route::post('/contacts/{contact}/restore', [ContactController::class, 'restore'])->name('contacts.restore');
        Route::post('/contacts/{contact}/assign', [ContactController::class, 'assign'])->name('contacts.assign');

        Route::get('/contacts/{contact}/interactions', [InteractionController::class, 'index'])->name('contacts.interactions.index');
        Route::post('/contacts/{contact}/interactions', [InteractionController::class, 'store'])
            ->middleware('throttle:interactions')
            ->name('contacts.interactions.store');
    });
});

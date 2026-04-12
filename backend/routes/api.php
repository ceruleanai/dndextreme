<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\CampaignController;
use App\Http\Controllers\CharacterController;
use App\Http\Controllers\GameController;
use Illuminate\Support\Facades\Route;

Route::get('/ping', function () {
    return response()->json([
        'status' => 'ok',
        'app' => 'DnD Extreme',
        'ai_provider' => config('ai.default'),
    ]);
});

// Auth
Route::post('/auth/register', [AuthController::class, 'register']);
Route::post('/auth/login', [AuthController::class, 'login']);

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/auth/logout', [AuthController::class, 'logout']);

    // Campaigns
    Route::apiResource('campaigns', CampaignController::class)->only(['index', 'store', 'show', 'destroy']);

    // Characters
    Route::post('/campaigns/{campaign}/characters', [CharacterController::class, 'store']);
    Route::get('/campaigns/{campaign}/characters/{character}', [CharacterController::class, 'show']);
    Route::put('/campaigns/{campaign}/characters/{character}', [CharacterController::class, 'update']);

    // Game Sessions
    Route::post('/campaigns/{campaign}/sessions', [GameController::class, 'startSession']);
    Route::get('/campaigns/{campaign}/sessions/{session}', [GameController::class, 'showSession']);
    Route::post('/campaigns/{campaign}/sessions/{session}/end', [GameController::class, 'endSession']);

    // Game Play
    Route::post('/campaigns/{campaign}/sessions/{session}/message', [GameController::class, 'sendMessage']);
    Route::get('/campaigns/{campaign}/sessions/{session}/messages', [GameController::class, 'getMessages']);
});

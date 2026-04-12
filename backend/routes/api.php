<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\CampaignController;
use App\Http\Controllers\CharacterController;
use App\Http\Controllers\CharacterTemplateController;
use App\Http\Controllers\CombatController;
use App\Http\Controllers\DndReferenceController;
use App\Http\Controllers\GameController;
use App\Http\Controllers\MapController;
use App\Http\Controllers\TTSController;
use App\Http\Controllers\VoiceModeController;
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

    // Characters (within campaign)
    Route::post('/campaigns/{campaign}/characters', [CharacterController::class, 'store']);
    Route::get('/campaigns/{campaign}/characters/{character}', [CharacterController::class, 'show']);
    Route::put('/campaigns/{campaign}/characters/{character}', [CharacterController::class, 'update']);

    // Character Portrait
    Route::post('/campaigns/{campaign}/characters/{character}/portrait', [CharacterController::class, 'generatePortrait']);
    Route::post('/portrait-preview', [CharacterController::class, 'previewPortrait']);

    // Character Progression
    Route::post('/campaigns/{campaign}/characters/{character}/level-up', [CharacterController::class, 'levelUp']);
    Route::post('/campaigns/{campaign}/characters/{character}/add-xp', [CharacterController::class, 'addXp']);
    Route::post('/campaigns/{campaign}/characters/{character}/asi', [CharacterController::class, 'applyAsi']);
    Route::post('/campaigns/{campaign}/characters/{character}/equip', [CharacterController::class, 'equip']);

    // Spellcasting
    Route::get('/campaigns/{campaign}/characters/{character}/spells', [CharacterController::class, 'spells']);
    Route::post('/campaigns/{campaign}/characters/{character}/cast-spell', [CharacterController::class, 'castSpell']);
    Route::post('/campaigns/{campaign}/characters/{character}/prepare-spells', [CharacterController::class, 'prepareSpells']);
    Route::post('/campaigns/{campaign}/characters/{character}/learn-spell', [CharacterController::class, 'learnSpell']);

    // Rest & Recovery
    Route::post('/campaigns/{campaign}/characters/{character}/short-rest', [CharacterController::class, 'shortRest']);
    Route::post('/campaigns/{campaign}/characters/{character}/long-rest', [CharacterController::class, 'longRest']);

    // Damage & Death
    Route::post('/campaigns/{campaign}/characters/{character}/damage', [CharacterController::class, 'applyDamage']);
    Route::post('/campaigns/{campaign}/characters/{character}/heal', [CharacterController::class, 'heal']);
    Route::post('/campaigns/{campaign}/characters/{character}/death-save', [CharacterController::class, 'deathSave']);

    // Combat Encounters
    Route::post('/campaigns/{campaign}/sessions/{session}/combat', [CombatController::class, 'start']);
    Route::get('/campaigns/{campaign}/sessions/{session}/combat', [CombatController::class, 'show']);
    Route::post('/campaigns/{campaign}/sessions/{session}/combat/{encounter}/next-turn', [CombatController::class, 'nextTurn']);
    Route::post('/campaigns/{campaign}/sessions/{session}/combat/{encounter}/attack', [CombatController::class, 'attack']);
    Route::post('/campaigns/{campaign}/sessions/{session}/combat/{encounter}/end', [CombatController::class, 'end']);

    // Character Templates (multi-campaign)
    Route::get('/character-templates', [CharacterTemplateController::class, 'index']);
    Route::post('/character-templates', [CharacterTemplateController::class, 'store']);
    Route::get('/character-templates/{template}', [CharacterTemplateController::class, 'show']);
    Route::delete('/character-templates/{template}', [CharacterTemplateController::class, 'destroy']);
    Route::post('/character-templates/{template}/campaigns/{campaign}', [CharacterTemplateController::class, 'instantiate']);

    // Campaign Maps
    Route::get('/campaigns/{campaign}/maps', [MapController::class, 'index']);
    Route::get('/campaigns/{campaign}/maps/current', [MapController::class, 'current']);
    Route::post('/campaigns/{campaign}/maps', [MapController::class, 'upload']);
    Route::post('/campaigns/{campaign}/maps/{map}/set-active', [MapController::class, 'setActive']);
    Route::delete('/campaigns/{campaign}/maps/{map}', [MapController::class, 'destroy']);
    Route::get('/maps/categories', [MapController::class, 'bundledCategories']);

    // Game Sessions
    Route::post('/campaigns/{campaign}/sessions', [GameController::class, 'startSession']);
    Route::get('/campaigns/{campaign}/sessions/{session}', [GameController::class, 'showSession']);
    Route::post('/campaigns/{campaign}/sessions/{session}/end', [GameController::class, 'endSession']);

    // Game Play
    Route::post('/campaigns/{campaign}/sessions/{session}/message', [GameController::class, 'sendMessage']);
    Route::get('/campaigns/{campaign}/sessions/{session}/messages', [GameController::class, 'getMessages']);

    // Text-to-Speech
    Route::post('/tts', [TTSController::class, 'synthesize']);

    // Voice Mode (Gemini Live API)
    Route::post('/campaigns/{campaign}/sessions/{session}/voice-config', [VoiceModeController::class, 'getVoiceConfig']);
    Route::post('/campaigns/{campaign}/sessions/{session}/voice-transcript', [VoiceModeController::class, 'saveTranscript']);

    // DnD 5e Reference Data
    Route::prefix('reference')->group(function () {
        Route::get('/classes', [DndReferenceController::class, 'classes']);
        Route::get('/classes/{class}', [DndReferenceController::class, 'classDetail']);
        Route::get('/spells', [DndReferenceController::class, 'spells']);
        Route::get('/spells/{slug}', [DndReferenceController::class, 'spell']);
        Route::get('/equipment', [DndReferenceController::class, 'equipment']);
        Route::get('/conditions', [DndReferenceController::class, 'conditions']);
        Route::get('/xp-table', [DndReferenceController::class, 'xpTable']);
    });
});

<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default AI Provider
    |--------------------------------------------------------------------------
    |
    | Supported: "claude", "gemini"
    |
    */
    'default' => env('AI_PROVIDER', 'claude'),

    'providers' => [

        'claude' => [
            'api_key' => env('ANTHROPIC_API_KEY'),
            'model' => env('CLAUDE_MODEL', 'claude-sonnet-4-6'),
            'summary_model' => env('CLAUDE_SUMMARY_MODEL', 'claude-haiku-4-5-20251001'),
        ],

        'gemini' => [
            'api_key' => env('GEMINI_API_KEY'),
            'model' => env('GEMINI_MODEL', 'gemini-2.5-flash'),
            'summary_model' => env('GEMINI_SUMMARY_MODEL', 'gemini-2.0-flash-lite'),
        ],

    ],

    'live_model' => env('GEMINI_LIVE_MODEL', 'gemini-2.5-flash-native-audio-latest'),
    'tts_model' => env('GEMINI_TTS_MODEL', 'gemini-2.5-flash-preview-tts'),

];

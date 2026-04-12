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
        ],

        'gemini' => [
            'api_key' => env('GEMINI_API_KEY'),
            'model' => env('GEMINI_MODEL', 'gemini-2.5-flash'),
        ],

    ],

    'live_model' => env('GEMINI_LIVE_MODEL', 'gemini-2.5-flash-native-audio-latest'),

];

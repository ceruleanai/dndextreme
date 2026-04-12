<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Http;

class TTSController extends Controller
{
    public function synthesize(Request $request): Response
    {
        $request->validate([
            'text' => 'required|string|max:5000',
            'voice' => 'nullable|string',
        ]);

        $apiKey = config('ai.providers.gemini.api_key');
        $text = $request->input('text');
        $voice = $request->input('voice', 'Charon');

        // Use Gemini's TTS model
        $response = Http::post(
            "https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash-preview-tts:generateContent?key={$apiKey}",
            [
                'contents' => [
                    [
                        'parts' => [
                            ['text' => $text],
                        ],
                    ],
                ],
                'generationConfig' => [
                    'responseModalities' => ['AUDIO'],
                    'speechConfig' => [
                        'voiceConfig' => [
                            'prebuiltVoiceConfig' => [
                                'voiceName' => $voice,
                            ],
                        ],
                    ],
                ],
            ]
        );

        if ($response->failed()) {
            // Fall back to indicating the client should use browser TTS
            return response(['error' => 'TTS unavailable', 'fallback' => true], 503);
        }

        $data = $response->json();
        $audioBase64 = $data['candidates'][0]['content']['parts'][0]['inlineData']['data'] ?? null;
        $mimeType = $data['candidates'][0]['content']['parts'][0]['inlineData']['mimeType'] ?? 'audio/mp3';

        if (!$audioBase64) {
            return response(['error' => 'No audio generated', 'fallback' => true], 503);
        }

        $audioBytes = base64_decode($audioBase64);

        return response($audioBytes, 200, [
            'Content-Type' => $mimeType,
            'Content-Length' => strlen($audioBytes),
        ]);
    }
}

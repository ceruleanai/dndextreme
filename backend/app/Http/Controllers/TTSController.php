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

        $response = Http::timeout(30)->post(
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
            return response(['error' => 'TTS unavailable', 'fallback' => true], 503);
        }

        $data = $response->json();
        $audioBase64 = $data['candidates'][0]['content']['parts'][0]['inlineData']['data'] ?? null;
        $mimeType = $data['candidates'][0]['content']['parts'][0]['inlineData']['mimeType'] ?? 'audio/mp3';

        if (!$audioBase64) {
            return response(['error' => 'No audio generated', 'fallback' => true], 503);
        }

        $pcmBytes = base64_decode($audioBase64);

        // Gemini returns raw PCM (audio/L16, 24kHz, 16-bit, mono).
        // Browsers can't play raw PCM, so wrap it in a WAV header.
        if (str_contains($mimeType, 'L16') || str_contains($mimeType, 'pcm')) {
            $sampleRate = 24000;
            if (preg_match('/rate=(\d+)/', $mimeType, $m)) {
                $sampleRate = (int) $m[1];
            }
            $wavBytes = $this->pcmToWav($pcmBytes, $sampleRate, 16, 1);

            return response($wavBytes, 200, [
                'Content-Type' => 'audio/wav',
                'Content-Length' => strlen($wavBytes),
            ]);
        }

        return response($pcmBytes, 200, [
            'Content-Type' => $mimeType,
            'Content-Length' => strlen($pcmBytes),
        ]);
    }

    private function pcmToWav(string $pcmData, int $sampleRate, int $bitsPerSample, int $channels): string
    {
        $dataSize = strlen($pcmData);
        $byteRate = $sampleRate * $channels * ($bitsPerSample / 8);
        $blockAlign = $channels * ($bitsPerSample / 8);
        $chunkSize = 36 + $dataSize;

        $header = pack('A4', 'RIFF');
        $header .= pack('V', $chunkSize);
        $header .= pack('A4', 'WAVE');
        $header .= pack('A4', 'fmt ');
        $header .= pack('V', 16);              // Subchunk1Size (PCM = 16)
        $header .= pack('v', 1);               // AudioFormat (PCM = 1)
        $header .= pack('v', $channels);
        $header .= pack('V', $sampleRate);
        $header .= pack('V', $byteRate);
        $header .= pack('v', $blockAlign);
        $header .= pack('v', $bitsPerSample);
        $header .= pack('A4', 'data');
        $header .= pack('V', $dataSize);

        return $header . $pcmData;
    }
}

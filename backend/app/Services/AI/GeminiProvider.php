<?php

namespace App\Services\AI;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GeminiProvider implements AIProvider
{
    private string $apiKey;
    private string $model;
    private string $baseUrl = 'https://generativelanguage.googleapis.com/v1beta';

    public function __construct()
    {
        $this->apiKey = config('ai.providers.gemini.api_key');
        $this->model = config('ai.providers.gemini.model');
    }

    public function chat(string $systemPrompt, array $messages, array $options = []): string
    {
        $model = $options['model'] ?? $this->model;

        // Try context caching for large system prompts (>4K chars)
        $cachedContentName = null;
        if (strlen($systemPrompt) > 4000 && ($options['cache_key'] ?? null)) {
            $cachedContentName = $this->getOrCreateCache($model, $systemPrompt, $options['cache_key']);
        }

        $payload = $cachedContentName
            ? $this->buildCachedPayload($cachedContentName, $messages, $options)
            : $this->buildPayload($systemPrompt, $messages, $options);

        $response = Http::timeout($options['timeout'] ?? 120)->post(
            "{$this->baseUrl}/models/{$model}:generateContent?key={$this->apiKey}",
            $payload
        );

        $response->throw();

        return $response->json('candidates.0.content.parts.0.text');
    }

    public function stream(string $systemPrompt, array $messages, callable $onChunk, array $options = []): string
    {
        $model = $options['model'] ?? $this->model;
        $fullResponse = '';

        $cachedContentName = null;
        if (strlen($systemPrompt) > 4000 && ($options['cache_key'] ?? null)) {
            $cachedContentName = $this->getOrCreateCache($model, $systemPrompt, $options['cache_key']);
        }

        $payload = $cachedContentName
            ? $this->buildCachedPayload($cachedContentName, $messages, $options)
            : $this->buildPayload($systemPrompt, $messages, $options);

        $response = Http::timeout($options['timeout'] ?? 120)
            ->withOptions(['stream' => true])
            ->post(
                "{$this->baseUrl}/models/{$model}:streamGenerateContent?alt=sse&key={$this->apiKey}",
                $payload
            );

        $body = $response->getBody();
        $buffer = '';

        while (!$body->eof()) {
            $buffer .= $body->read(1024);
            while (($pos = strpos($buffer, "\n")) !== false) {
                $line = substr($buffer, 0, $pos);
                $buffer = substr($buffer, $pos + 1);

                if (str_starts_with($line, 'data: ')) {
                    $data = json_decode(substr($line, 6), true);
                    if ($data) {
                        $text = $data['candidates'][0]['content']['parts'][0]['text'] ?? '';
                        if ($text) {
                            $fullResponse .= $text;
                            $onChunk($text);
                        }
                    }
                }
            }
        }

        return $fullResponse;
    }

    public function generateImage(string $prompt, array $options = []): ?string
    {
        $model = $options['model'] ?? 'gemini-2.5-flash-image';

        $response = Http::timeout(60)->post(
            "{$this->baseUrl}/models/{$model}:generateContent?key={$this->apiKey}",
            [
                'contents' => [
                    ['parts' => [['text' => $prompt]]],
                ],
                'generationConfig' => [
                    'responseModalities' => ['TEXT', 'IMAGE'],
                ],
            ]
        );

        $response->throw();

        $parts = $response->json('candidates.0.content.parts', []);
        foreach ($parts as $part) {
            if (isset($part['inlineData'])) {
                return $part['inlineData']['data'];
            }
        }

        return null;
    }

    // =========================================================
    // Context Caching — caches large system prompts server-side
    // to reduce per-request input token costs.
    // =========================================================

    private function getOrCreateCache(string $model, string $systemPrompt, string $cacheKey): ?string
    {
        $localKey = "gemini_cache:{$cacheKey}";

        // Check if we already have a valid cached content name
        $cached = Cache::get($localKey);
        if ($cached) {
            return $cached;
        }

        try {
            $response = Http::timeout(15)->post(
                "{$this->baseUrl}/cachedContents?key={$this->apiKey}",
                [
                    'model' => "models/{$model}",
                    'systemInstruction' => [
                        'parts' => [['text' => $systemPrompt]],
                    ],
                    'ttl' => '300s', // 5 minute TTL — matches typical session interaction cadence
                ]
            );

            if ($response->successful()) {
                $name = $response->json('name');
                // Store locally for 4 minutes (under the 5min TTL to avoid stale refs)
                Cache::put($localKey, $name, 240);
                return $name;
            }

            Log::debug('Gemini cache creation failed', ['status' => $response->status()]);
        } catch (\Throwable $e) {
            Log::debug('Gemini cache creation error', ['error' => $e->getMessage()]);
        }

        return null;
    }

    /**
     * Invalidate a cached context (e.g. when game state changes).
     */
    public function invalidateCache(string $cacheKey): void
    {
        $localKey = "gemini_cache:{$cacheKey}";
        $name = Cache::pull($localKey);

        if ($name) {
            try {
                Http::timeout(5)->delete(
                    "{$this->baseUrl}/{$name}?key={$this->apiKey}"
                );
            } catch (\Throwable $e) {
                // Best-effort cleanup; TTL will expire it anyway
            }
        }
    }

    // =========================================================
    // Payload Builders
    // =========================================================

    private function buildPayload(string $systemPrompt, array $messages, array $options): array
    {
        return [
            'system_instruction' => [
                'parts' => [['text' => $systemPrompt]],
            ],
            'contents' => $this->formatMessages($messages),
            'generationConfig' => $this->buildGenerationConfig($options),
        ];
    }

    private function buildCachedPayload(string $cachedContentName, array $messages, array $options): array
    {
        return [
            'cachedContent' => $cachedContentName,
            'contents' => $this->formatMessages($messages),
            'generationConfig' => $this->buildGenerationConfig($options),
        ];
    }

    private function buildGenerationConfig(array $options): array
    {
        $config = [
            'maxOutputTokens' => $options['max_tokens'] ?? 4096,
        ];

        if (isset($options['temperature'])) {
            $config['temperature'] = $options['temperature'];
        }

        return $config;
    }

    private function formatMessages(array $messages): array
    {
        return array_map(fn($msg) => [
            'role' => $msg['role'] === 'assistant' ? 'model' : 'user',
            'parts' => [['text' => $msg['content']]],
        ], $messages);
    }
}

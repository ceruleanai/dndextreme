<?php

namespace App\Services\AI;

use Illuminate\Support\Facades\Http;

class ClaudeProvider implements AIProvider
{
    private string $apiKey;
    private string $model;

    public function __construct()
    {
        $this->apiKey = config('ai.providers.claude.api_key');
        $this->model = config('ai.providers.claude.model');
    }

    public function chat(string $systemPrompt, array $messages, array $options = []): string
    {
        $model = $options['model'] ?? $this->model;
        $enableCache = (bool) ($options['cache_key'] ?? false);

        $payload = [
            'model' => $model,
            'max_tokens' => $options['max_tokens'] ?? 4096,
            'system' => $this->formatSystemPrompt($systemPrompt, $enableCache),
            'messages' => $this->formatMessages($messages, $enableCache),
        ];

        if (isset($options['temperature'])) {
            $payload['temperature'] = $options['temperature'];
        }

        $response = Http::timeout($options['timeout'] ?? 120)
            ->withHeaders($this->buildHeaders())
            ->post('https://api.anthropic.com/v1/messages', $payload);

        $response->throw();

        return $response->json('content.0.text');
    }

    public function stream(string $systemPrompt, array $messages, callable $onChunk, array $options = []): string
    {
        $model = $options['model'] ?? $this->model;
        $enableCache = (bool) ($options['cache_key'] ?? false);
        $fullResponse = '';

        $payload = [
            'model' => $model,
            'max_tokens' => $options['max_tokens'] ?? 4096,
            'system' => $this->formatSystemPrompt($systemPrompt, $enableCache),
            'messages' => $this->formatMessages($messages, $enableCache),
            'stream' => true,
        ];

        if (isset($options['temperature'])) {
            $payload['temperature'] = $options['temperature'];
        }

        $response = Http::timeout($options['timeout'] ?? 120)
            ->withHeaders($this->buildHeaders())
            ->withOptions(['stream' => true])
            ->post('https://api.anthropic.com/v1/messages', $payload);

        $body = $response->getBody();
        $buffer = '';

        while (!$body->eof()) {
            $buffer .= $body->read(1024);
            while (($pos = strpos($buffer, "\n")) !== false) {
                $line = substr($buffer, 0, $pos);
                $buffer = substr($buffer, $pos + 1);

                if (str_starts_with($line, 'data: ')) {
                    $data = json_decode(substr($line, 6), true);
                    if ($data && ($data['type'] ?? '') === 'content_block_delta') {
                        $text = $data['delta']['text'] ?? '';
                        $fullResponse .= $text;
                        $onChunk($text);
                    }
                }
            }
        }

        return $fullResponse;
    }

    public function generateImage(string $prompt, array $options = []): ?string
    {
        // Claude doesn't support image generation — delegate to Gemini
        return (new GeminiProvider())->generateImage($prompt, $options);
    }

    // =========================================================
    // Anthropic Prompt Caching
    //
    // Unlike Gemini (which requires a separate cached-content API
    // call), Anthropic caching is inline: you add cache_control
    // breakpoints to content blocks and the API handles the rest.
    // Cached prefixes have a 5-minute TTL, auto-keyed by content
    // hash — no manual invalidation needed.
    // =========================================================

    /**
     * Format the system prompt. When caching is enabled, send as a
     * content-block array with a cache_control breakpoint so
     * Anthropic caches the full system prompt across turns.
     */
    private function formatSystemPrompt(string $systemPrompt, bool $enableCache): string|array
    {
        if (!$enableCache) {
            return $systemPrompt;
        }

        return [
            [
                'type' => 'text',
                'text' => $systemPrompt,
                'cache_control' => ['type' => 'ephemeral'],
            ],
        ];
    }

    /**
     * Format messages. When caching is enabled, place a second
     * cache_control breakpoint on the penultimate user message so
     * the growing conversation history is also cached turn-over-turn.
     */
    private function formatMessages(array $messages, bool $enableCache = false): array
    {
        $formatted = array_map(fn($msg) => [
            'role' => $msg['role'],
            'content' => $msg['content'],
        ], $messages);

        if ($enableCache && count($formatted) >= 3) {
            // Find the second-to-last user message and mark it as a cache breakpoint.
            // This caches the conversation prefix, so each new turn only processes
            // the latest user message + assistant reply.
            for ($i = count($formatted) - 2; $i >= 0; $i--) {
                if ($formatted[$i]['role'] === 'user') {
                    $formatted[$i]['content'] = [
                        [
                            'type' => 'text',
                            'text' => $formatted[$i]['content'],
                            'cache_control' => ['type' => 'ephemeral'],
                        ],
                    ];
                    break;
                }
            }
        }

        return $formatted;
    }

    private function buildHeaders(): array
    {
        return [
            'x-api-key' => $this->apiKey,
            'anthropic-version' => '2023-06-01',
            'content-type' => 'application/json',
        ];
    }
}

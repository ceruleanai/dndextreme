<?php

namespace App\Services\AI;

use Illuminate\Support\Facades\Http;

class GeminiProvider implements AIProvider
{
    private string $apiKey;
    private string $model;

    public function __construct()
    {
        $this->apiKey = config('ai.providers.gemini.api_key');
        $this->model = config('ai.providers.gemini.model');
    }

    public function chat(string $systemPrompt, array $messages, array $options = []): string
    {
        $model = $options['model'] ?? $this->model;

        $response = Http::post(
            "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key={$this->apiKey}",
            [
                'system_instruction' => [
                    'parts' => [['text' => $systemPrompt]],
                ],
                'contents' => $this->formatMessages($messages),
                'generationConfig' => [
                    'maxOutputTokens' => $options['max_tokens'] ?? 4096,
                ],
            ]
        );

        $response->throw();

        return $response->json('candidates.0.content.parts.0.text');
    }

    public function stream(string $systemPrompt, array $messages, callable $onChunk, array $options = []): string
    {
        $model = $options['model'] ?? $this->model;
        $fullResponse = '';

        $response = Http::withOptions(['stream' => true])
            ->post(
                "https://generativelanguage.googleapis.com/v1beta/models/{$model}:streamGenerateContent?alt=sse&key={$this->apiKey}",
                [
                    'system_instruction' => [
                        'parts' => [['text' => $systemPrompt]],
                    ],
                    'contents' => $this->formatMessages($messages),
                    'generationConfig' => [
                        'maxOutputTokens' => $options['max_tokens'] ?? 4096,
                    ],
                ]
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
            "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key={$this->apiKey}",
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

    private function formatMessages(array $messages): array
    {
        return array_map(fn($msg) => [
            'role' => $msg['role'] === 'assistant' ? 'model' : 'user',
            'parts' => [['text' => $msg['content']]],
        ], $messages);
    }
}

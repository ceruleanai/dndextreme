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
        $response = Http::withHeaders([
            'x-api-key' => $this->apiKey,
            'anthropic-version' => '2023-06-01',
            'content-type' => 'application/json',
        ])->post('https://api.anthropic.com/v1/messages', [
            'model' => $options['model'] ?? $this->model,
            'max_tokens' => $options['max_tokens'] ?? 4096,
            'system' => $systemPrompt,
            'messages' => $this->formatMessages($messages),
        ]);

        $response->throw();

        return $response->json('content.0.text');
    }

    public function stream(string $systemPrompt, array $messages, callable $onChunk, array $options = []): string
    {
        $fullResponse = '';

        $response = Http::withHeaders([
            'x-api-key' => $this->apiKey,
            'anthropic-version' => '2023-06-01',
            'content-type' => 'application/json',
        ])->withOptions(['stream' => true])
          ->post('https://api.anthropic.com/v1/messages', [
              'model' => $options['model'] ?? $this->model,
              'max_tokens' => $options['max_tokens'] ?? 4096,
              'system' => $systemPrompt,
              'messages' => $this->formatMessages($messages),
              'stream' => true,
          ]);

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

    private function formatMessages(array $messages): array
    {
        return array_map(fn($msg) => [
            'role' => $msg['role'],
            'content' => $msg['content'],
        ], $messages);
    }
}

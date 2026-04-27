<?php

namespace App\Services\AI;

interface AIProvider
{
    /**
     * Send a message to the AI and get a response.
     *
     * @param string $systemPrompt The system prompt (DM personality, rules, etc.)
     * @param array $messages Conversation history [{role: 'user'|'assistant', content: '...'}]
     * @param array $options Additional provider-specific options (model, max_tokens, temperature, cache_key, timeout)
     * @return string The AI response text
     */
    public function chat(string $systemPrompt, array $messages, array $options = []): string;

    /**
     * Send a message and stream the response.
     *
     * @param string $systemPrompt
     * @param array $messages
     * @param callable $onChunk Called with each text chunk as it arrives
     * @param array $options
     * @return string The full accumulated response
     */
    public function stream(string $systemPrompt, array $messages, callable $onChunk, array $options = []): string;

    /**
     * Generate an image from a text prompt.
     *
     * @param string $prompt The image generation prompt
     * @param array $options Provider-specific options (model, etc.)
     * @return string|null Base64-encoded image data, or null on failure
     */
    public function generateImage(string $prompt, array $options = []): ?string;
}

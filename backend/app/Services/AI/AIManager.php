<?php

namespace App\Services\AI;

use InvalidArgumentException;

class AIManager
{
    public function provider(?string $name = null): AIProvider
    {
        $name = $name ?? config('ai.default');

        return match ($name) {
            'claude' => new ClaudeProvider(),
            'gemini' => new GeminiProvider(),
            default => throw new InvalidArgumentException("Unsupported AI provider: {$name}"),
        };
    }

    /**
     * Get the provider used for image generation.
     * Currently only Gemini supports native image generation;
     * ClaudeProvider delegates to Gemini internally.
     */
    public function imageProvider(): AIProvider
    {
        return new GeminiProvider();
    }
}

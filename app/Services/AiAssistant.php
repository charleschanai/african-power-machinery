<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class AiAssistant
{
    public function isConfigured(): bool
    {
        return filled(config('services.ai.key'));
    }

    public function model(): string
    {
        return config('services.ai.model');
    }

    /**
     * Generate a chat completion using any OpenAI-compatible chat API.
     * Returns null when the service is not configured or the call fails,
     * so callers can always fall back to deterministic answers.
     */
    public function generate(string $systemPrompt, string $userMessage): ?string
    {
        if (! $this->isConfigured()) {
            return null;
        }

        try {
            $response = Http::withToken(config('services.ai.key'))
                ->timeout(config('services.ai.timeout'))
                ->post(
                    rtrim(config('services.ai.base_url'), '/') . '/chat/completions',
                    [
                        'model' => config('services.ai.model'),
                        'temperature' => config('services.ai.temperature'),
                        'messages' => [
                            ['role' => 'system', 'content' => $systemPrompt],
                            ['role' => 'user', 'content' => $userMessage],
                        ],
                    ]
                );

            if ($response->failed()) {
                Log::warning('AI API failed', ['response' => $response->body()]);

                return null;
            }

            $content = $response->json('choices.0.message.content');

            return is_string($content) && trim($content) !== '' ? trim($content) : null;
        } catch (\Throwable $e) {
            Log::warning('AI API exception', ['error' => $e->getMessage()]);

            return null;
        }
    }
}

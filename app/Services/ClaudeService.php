<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;

class ClaudeService
{
    private string $apiKey;
    private string $apiUrl;
    private string $model = 'claude-3-5-sonnet-20241022';

    public function __construct()
    {
        $this->apiKey = config('services.claude.api_key');
        $this->apiUrl = config('services.claude.api_url');
    }

    /**
     * Send a message to Claude and get a response
     * 
     * @param string $message The user message
     * @param array $conversationHistory Optional array of previous messages for context
     * @return string The Claude response
     */
    public function chat(string $message, array $conversationHistory = []): string
    {
        // Build messages array
        $messages = $conversationHistory;
        $messages[] = [
            'role' => 'user',
            'content' => $message,
        ];

        $response = Http::withHeaders([
            'anthropic-version' => '2023-06-01',
            'content-type' => 'application/json',
            'x-api-key' => $this->apiKey,
        ])->post("{$this->apiUrl}/messages", [
            'model' => $this->model,
            'max_tokens' => 1024,
            'messages' => $messages,
        ]);

        if (!$response->successful()) {
            throw new \Exception('Claude API error: ' . $response->body());
        }

        $data = $response->json();
        return $data['content'][0]['text'] ?? '';
    }

    /**
     * Exchange OAuth code for API key
     * 
     * @param string $code The authorization code from OAuth callback
     * @return string|null The API key if successful
     */
    public function exchangeCodeForApiKey(string $code): ?string
    {
        $response = Http::post('https://claude.ai/oauth/token', [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'client_id' => config('services.claude.client_id'),
            'client_secret' => config('services.claude.client_secret'),
            'redirect_uri' => config('services.claude.redirect_uri'),
        ]);

        if (!$response->successful()) {
            return null;
        }

        $data = $response->json();
        $apiKey = $data['access_token'] ?? null;

        // Cache the API key
        if ($apiKey) {
            Cache::put('claude_api_key', $apiKey, now()->addDays(30));
        }

        return $apiKey;
    }

    /**
     * Generate a response for a specific task
     * 
     * @param string $task The task description
     * @param array $context Additional context data
     * @return string The generated response
     */
    public function generate(string $task, array $context = []): string
    {
        $prompt = "Task: {$task}\n\nContext: " . json_encode($context);
        return $this->chat($prompt);
    }
}

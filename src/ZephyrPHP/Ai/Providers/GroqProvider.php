<?php

declare(strict_types=1);

namespace ZephyrPHP\Ai\Providers;

use ZephyrPHP\Ai\AiProviderInterface;
use ZephyrPHP\Ai\AiResponse;

/**
 * Groq provider — uses OpenAI-compatible API format.
 * Free tier with rate limits. Great for fast inference (Llama, Mixtral).
 */
class GroqProvider implements AiProviderInterface
{
    private string $apiKey;
    private string $model;
    private string $baseUrl;

    public function __construct(array $config)
    {
        $this->apiKey = $config['api_key'] ?? '';
        $this->model = $config['model'] ?? 'llama-3.3-70b-versatile';
        $this->baseUrl = $config['base_url'] ?? 'https://api.groq.com/openai/v1';
    }

    public function generate(string $systemPrompt, string $userPrompt, array $options = []): AiResponse
    {
        if (empty($this->apiKey)) {
            throw new \RuntimeException('Groq API key is not configured.');
        }

        $startTime = microtime(true);

        $payload = [
            'model' => $this->model,
            'max_tokens' => $options['max_tokens'] ?? 4096,
            'messages' => [
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user', 'content' => $userPrompt],
            ],
        ];

        if (isset($options['temperature'])) {
            $payload['temperature'] = $options['temperature'];
        }

        $url = $this->baseUrl . '/chat/completions';

        $response = $this->httpPost($url, $payload, [
            'Authorization: Bearer ' . $this->apiKey,
        ]);

        $duration = microtime(true) - $startTime;

        $content = $response['choices'][0]['message']['content'] ?? '';
        $inputTokens = $response['usage']['prompt_tokens'] ?? 0;
        $outputTokens = $response['usage']['completion_tokens'] ?? 0;
        $finishReason = $response['choices'][0]['finish_reason'] ?? null;

        return new AiResponse($content, $this->model, 'groq', $inputTokens, $outputTokens, $finishReason, $duration);
    }

    public function getName(): string
    {
        return 'groq';
    }

    public function supportsStreaming(): bool
    {
        return true;
    }

    public function getModel(): string
    {
        return $this->model;
    }

    private function httpPost(string $url, array $payload, array $extraHeaders = []): array
    {
        $ch = curl_init($url);
        $headers = array_merge(['Content-Type: application/json'], $extraHeaders);

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_TIMEOUT => 120,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);

        $body = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($body === false) {
            throw new \RuntimeException('Groq API request failed: ' . $error);
        }

        $data = json_decode($body, true);

        if ($httpCode >= 400) {
            $msg = $data['error']['message'] ?? "HTTP $httpCode";
            throw new \RuntimeException('Groq API error: ' . $msg);
        }

        return $data ?? [];
    }
}

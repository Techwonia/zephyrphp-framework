<?php

declare(strict_types=1);

namespace ZephyrPHP\Ai\Providers;

use ZephyrPHP\Ai\AiProviderInterface;
use ZephyrPHP\Ai\AiResponse;

/**
 * OpenRouter provider — access any model via openrouter.ai.
 * Uses OpenAI-compatible format with additional headers.
 */
class OpenRouterProvider implements AiProviderInterface
{
    private string $apiKey;
    private string $model;
    private string $baseUrl;
    private string $siteUrl;
    private string $siteName;

    public function __construct(array $config)
    {
        $this->apiKey = $config['api_key'] ?? '';
        $this->model = $config['model'] ?? 'anthropic/claude-sonnet-4';
        $this->baseUrl = $config['base_url'] ?? 'https://openrouter.ai/api/v1';
        $this->siteUrl = $config['site_url'] ?? '';
        $this->siteName = $config['site_name'] ?? 'ZephyrPHP';
    }

    public function generate(string $systemPrompt, string $userPrompt, array $options = []): AiResponse
    {
        if (empty($this->apiKey)) {
            throw new \RuntimeException('OpenRouter API key is not configured.');
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

        $headers = [
            'Authorization: Bearer ' . $this->apiKey,
        ];
        if ($this->siteUrl) {
            $headers[] = 'HTTP-Referer: ' . $this->siteUrl;
        }
        if ($this->siteName) {
            $headers[] = 'X-Title: ' . $this->siteName;
        }

        $response = $this->httpPost($url, $payload, $headers);
        $duration = microtime(true) - $startTime;

        $content = $response['choices'][0]['message']['content'] ?? '';
        $inputTokens = $response['usage']['prompt_tokens'] ?? 0;
        $outputTokens = $response['usage']['completion_tokens'] ?? 0;
        $finishReason = $response['choices'][0]['finish_reason'] ?? null;

        return new AiResponse($content, $this->model, 'openrouter', $inputTokens, $outputTokens, $finishReason, $duration);
    }

    public function getName(): string
    {
        return 'openrouter';
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
            throw new \RuntimeException('OpenRouter API request failed: ' . $error);
        }

        $data = json_decode($body, true);

        if ($httpCode >= 400) {
            $msg = $data['error']['message'] ?? "HTTP $httpCode";
            throw new \RuntimeException('OpenRouter API error: ' . $msg);
        }

        return $data ?? [];
    }
}

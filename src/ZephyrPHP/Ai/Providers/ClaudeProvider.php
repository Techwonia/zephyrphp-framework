<?php

declare(strict_types=1);

namespace ZephyrPHP\Ai\Providers;

use ZephyrPHP\Ai\AiProviderInterface;
use ZephyrPHP\Ai\AiResponse;

class ClaudeProvider implements AiProviderInterface
{
    private string $apiKey;
    private string $model;
    private string $baseUrl;

    public function __construct(array $config)
    {
        $this->apiKey = $config['api_key'] ?? '';
        $this->model = $config['model'] ?? 'claude-sonnet-4-20250514';
        $this->baseUrl = $config['base_url'] ?? 'https://api.anthropic.com/v1';
    }

    public function generate(string $systemPrompt, string $userPrompt, array $options = []): AiResponse
    {
        if (empty($this->apiKey)) {
            throw new \RuntimeException('Anthropic API key is not configured.');
        }

        $startTime = microtime(true);

        $payload = [
            'model' => $this->model,
            'max_tokens' => $options['max_tokens'] ?? 4096,
            'system' => $systemPrompt,
            'messages' => [
                ['role' => 'user', 'content' => $userPrompt],
            ],
        ];

        if (isset($options['temperature'])) {
            $payload['temperature'] = $options['temperature'];
        }

        $url = $this->baseUrl . '/messages';

        $response = $this->httpPost($url, $payload, [
            'x-api-key: ' . $this->apiKey,
            'anthropic-version: 2023-06-01',
        ]);

        $duration = microtime(true) - $startTime;

        $content = '';
        if (isset($response['content'])) {
            foreach ($response['content'] as $block) {
                if (($block['type'] ?? '') === 'text') {
                    $content .= $block['text'];
                }
            }
        }

        $inputTokens = $response['usage']['input_tokens'] ?? 0;
        $outputTokens = $response['usage']['output_tokens'] ?? 0;
        $finishReason = $response['stop_reason'] ?? null;

        return new AiResponse($content, $this->model, 'claude', $inputTokens, $outputTokens, $finishReason, $duration);
    }

    public function getName(): string
    {
        return 'claude';
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
            throw new \RuntimeException('Claude API request failed: ' . $error);
        }

        $data = json_decode($body, true);

        if ($httpCode >= 400) {
            $msg = $data['error']['message'] ?? "HTTP $httpCode";
            throw new \RuntimeException('Claude API error: ' . $msg);
        }

        return $data ?? [];
    }
}

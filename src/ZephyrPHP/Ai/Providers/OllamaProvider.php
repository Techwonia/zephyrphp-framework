<?php

declare(strict_types=1);

namespace ZephyrPHP\Ai\Providers;

use ZephyrPHP\Ai\AiProviderInterface;
use ZephyrPHP\Ai\AiResponse;

/**
 * Ollama provider — local AI models for self-hosted users.
 * Completely free, private, no API key required.
 * Uses the Ollama REST API (OpenAI-compatible endpoint).
 */
class OllamaProvider implements AiProviderInterface
{
    private string $host;
    private string $model;

    public function __construct(array $config)
    {
        $this->host = rtrim($config['host'] ?? 'http://localhost:11434', '/');
        $this->model = $config['model'] ?? 'llama3';
    }

    public function generate(string $systemPrompt, string $userPrompt, array $options = []): AiResponse
    {
        $startTime = microtime(true);

        $payload = [
            'model' => $this->model,
            'messages' => [
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user', 'content' => $userPrompt],
            ],
            'stream' => false,
            'options' => [],
        ];

        if (isset($options['temperature'])) {
            $payload['options']['temperature'] = $options['temperature'];
        }
        if (isset($options['max_tokens'])) {
            $payload['options']['num_predict'] = $options['max_tokens'];
        }

        $url = $this->host . '/api/chat';

        $response = $this->httpPost($url, $payload);
        $duration = microtime(true) - $startTime;

        $content = $response['message']['content'] ?? '';
        $inputTokens = $response['prompt_eval_count'] ?? 0;
        $outputTokens = $response['eval_count'] ?? 0;
        $finishReason = ($response['done'] ?? false) ? 'stop' : null;

        return new AiResponse($content, $this->model, 'ollama', $inputTokens, $outputTokens, $finishReason, $duration);
    }

    public function getName(): string
    {
        return 'ollama';
    }

    public function supportsStreaming(): bool
    {
        return true;
    }

    public function getModel(): string
    {
        return $this->model;
    }

    private function httpPost(string $url, array $payload): array
    {
        // Only disable SSL verification for truly local hosts
        $parsedHost = parse_url($this->host, PHP_URL_HOST);
        $isLocal = in_array($parsedHost, ['localhost', '127.0.0.1', '::1'], true);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_TIMEOUT => 300, // Local models can be slow
            CURLOPT_SSL_VERIFYPEER => !$isLocal,
        ]);

        $body = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($body === false) {
            throw new \RuntimeException('Ollama request failed: ' . $error . '. Is Ollama running at ' . $this->host . '?');
        }

        $data = json_decode($body, true);

        if ($httpCode >= 400) {
            $msg = $data['error'] ?? "HTTP $httpCode";
            throw new \RuntimeException('Ollama error: ' . $msg);
        }

        return $data ?? [];
    }
}

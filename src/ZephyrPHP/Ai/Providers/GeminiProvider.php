<?php

declare(strict_types=1);

namespace ZephyrPHP\Ai\Providers;

use ZephyrPHP\Ai\AiProviderInterface;
use ZephyrPHP\Ai\AiResponse;

class GeminiProvider implements AiProviderInterface
{
    private string $apiKey;
    private string $model;
    private string $baseUrl;

    public function __construct(array $config)
    {
        $this->apiKey = $config['api_key'] ?? '';
        $this->model = $config['model'] ?? 'gemini-2.0-flash';
        $this->baseUrl = $config['base_url'] ?? 'https://generativelanguage.googleapis.com/v1beta';
    }

    public function generate(string $systemPrompt, string $userPrompt, array $options = []): AiResponse
    {
        if (empty($this->apiKey)) {
            throw new \RuntimeException('Gemini API key is not configured.');
        }

        $startTime = microtime(true);

        $payload = [
            'contents' => [
                [
                    'role' => 'user',
                    'parts' => [['text' => $userPrompt]],
                ],
            ],
            'systemInstruction' => [
                'parts' => [['text' => $systemPrompt]],
            ],
            'generationConfig' => [
                'temperature' => $options['temperature'] ?? 0.7,
                'maxOutputTokens' => $options['max_tokens'] ?? 4096,
                'topP' => $options['top_p'] ?? 0.95,
            ],
        ];

        $url = $this->baseUrl . '/models/' . $this->model . ':generateContent?key=' . $this->apiKey;

        $response = $this->httpPost($url, $payload);
        $duration = microtime(true) - $startTime;

        $content = '';
        $inputTokens = 0;
        $outputTokens = 0;
        $finishReason = null;

        if (isset($response['candidates'][0]['content']['parts'])) {
            foreach ($response['candidates'][0]['content']['parts'] as $part) {
                $content .= $part['text'] ?? '';
            }
        }

        if (isset($response['candidates'][0]['finishReason'])) {
            $finishReason = $response['candidates'][0]['finishReason'];
        }

        if (isset($response['usageMetadata'])) {
            $inputTokens = $response['usageMetadata']['promptTokenCount'] ?? 0;
            $outputTokens = $response['usageMetadata']['candidatesTokenCount'] ?? 0;
        }

        return new AiResponse($content, $this->model, 'gemini', $inputTokens, $outputTokens, $finishReason, $duration);
    }

    public function getName(): string
    {
        return 'gemini';
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
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
            ],
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_TIMEOUT => 120,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);

        $body = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($body === false) {
            throw new \RuntimeException('Gemini API request failed: ' . $error);
        }

        $data = json_decode($body, true);

        if ($httpCode >= 400) {
            $msg = $data['error']['message'] ?? "HTTP $httpCode";
            throw new \RuntimeException('Gemini API error: ' . $msg);
        }

        return $data ?? [];
    }
}

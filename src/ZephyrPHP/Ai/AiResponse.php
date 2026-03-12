<?php

declare(strict_types=1);

namespace ZephyrPHP\Ai;

class AiResponse
{
    private string $content;
    private string $model;
    private string $provider;
    private int $inputTokens;
    private int $outputTokens;
    private ?string $finishReason;
    private float $duration;

    public function __construct(
        string $content,
        string $model,
        string $provider,
        int $inputTokens = 0,
        int $outputTokens = 0,
        ?string $finishReason = null,
        float $duration = 0.0
    ) {
        $this->content = $content;
        $this->model = $model;
        $this->provider = $provider;
        $this->inputTokens = $inputTokens;
        $this->outputTokens = $outputTokens;
        $this->finishReason = $finishReason;
        $this->duration = $duration;
    }

    public function getContent(): string
    {
        return $this->content;
    }

    public function getModel(): string
    {
        return $this->model;
    }

    public function getProvider(): string
    {
        return $this->provider;
    }

    public function getInputTokens(): int
    {
        return $this->inputTokens;
    }

    public function getOutputTokens(): int
    {
        return $this->outputTokens;
    }

    public function getTotalTokens(): int
    {
        return $this->inputTokens + $this->outputTokens;
    }

    public function getFinishReason(): ?string
    {
        return $this->finishReason;
    }

    public function getDuration(): float
    {
        return $this->duration;
    }

    /**
     * Try to extract JSON from the response content.
     */
    public function json(): ?array
    {
        $content = trim($this->content);

        // Try direct parse first
        $data = json_decode($content, true);
        if (is_array($data)) {
            return $data;
        }

        // Try to extract JSON from markdown code blocks (greedy match for full content)
        if (preg_match('/```(?:json)?\s*\n?([\s\S]+?)\n?\s*```/', $content, $matches)) {
            $data = json_decode(trim($matches[1]), true);
            if (is_array($data)) {
                return $data;
            }
        }

        // Try to find the first { ... } JSON object in the response
        $start = strpos($content, '{');
        if ($start !== false) {
            $candidate = substr($content, $start);
            // Find matching closing brace by counting braces
            $depth = 0;
            $len = strlen($candidate);
            for ($i = 0; $i < $len; $i++) {
                if ($candidate[$i] === '{') $depth++;
                elseif ($candidate[$i] === '}') $depth--;
                if ($depth === 0) {
                    $jsonStr = substr($candidate, 0, $i + 1);
                    $data = json_decode($jsonStr, true);
                    if (is_array($data)) {
                        return $data;
                    }
                    break;
                }
            }
        }

        return null;
    }

    public function toArray(): array
    {
        return [
            'content' => $this->content,
            'model' => $this->model,
            'provider' => $this->provider,
            'input_tokens' => $this->inputTokens,
            'output_tokens' => $this->outputTokens,
            'total_tokens' => $this->getTotalTokens(),
            'finish_reason' => $this->finishReason,
            'duration' => $this->duration,
        ];
    }
}

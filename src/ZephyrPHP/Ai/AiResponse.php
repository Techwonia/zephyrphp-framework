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
        // Try direct parse first
        $data = json_decode($this->content, true);
        if ($data !== null) {
            return $data;
        }

        // Try to extract JSON from markdown code blocks
        if (preg_match('/```(?:json)?\s*\n([\s\S]*?)\n```/', $this->content, $matches)) {
            $data = json_decode($matches[1], true);
            if ($data !== null) {
                return $data;
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

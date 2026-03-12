<?php

declare(strict_types=1);

namespace ZephyrPHP\Ai;

interface AiProviderInterface
{
    /**
     * Generate a response from the AI model.
     */
    public function generate(string $systemPrompt, string $userPrompt, array $options = []): AiResponse;

    /**
     * Get the provider name (e.g. 'claude', 'openai', 'gemini').
     */
    public function getName(): string;

    /**
     * Whether this provider supports streaming responses.
     */
    public function supportsStreaming(): bool;

    /**
     * Get the model identifier being used.
     */
    public function getModel(): string;
}

<?php

declare(strict_types=1);

namespace ZephyrPHP\Ai;

use ZephyrPHP\Ai\Providers\ClaudeProvider;
use ZephyrPHP\Ai\Providers\GeminiProvider;
use ZephyrPHP\Ai\Providers\GroqProvider;
use ZephyrPHP\Ai\Providers\MistralProvider;
use ZephyrPHP\Ai\Providers\OllamaProvider;
use ZephyrPHP\Ai\Providers\OpenAiProvider;
use ZephyrPHP\Ai\Providers\OpenRouterProvider;

class AiProviderManager
{
    private static ?self $instance = null;

    private array $config;
    private array $resolved = [];

    private const DRIVER_MAP = [
        'gemini' => GeminiProvider::class,
        'claude' => ClaudeProvider::class,
        'openai' => OpenAiProvider::class,
        'groq' => GroqProvider::class,
        'mistral' => MistralProvider::class,
        'openrouter' => OpenRouterProvider::class,
        'ollama' => OllamaProvider::class,
    ];

    public function __construct(array $config = [])
    {
        $this->config = $config;
    }

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            $config = self::loadConfig();
            self::$instance = new self($config);
        }
        return self::$instance;
    }

    /**
     * Get a provider instance by name. Falls back to default if null.
     */
    public function provider(?string $name = null): AiProviderInterface
    {
        $name = $name ?? $this->getDefault();

        if (isset($this->resolved[$name])) {
            return $this->resolved[$name];
        }

        $providerConfig = $this->config['providers'][$name] ?? null;
        if (!$providerConfig) {
            throw new \RuntimeException("AI provider '{$name}' is not configured.");
        }

        $driver = $providerConfig['driver'] ?? $name;
        $class = self::DRIVER_MAP[$driver] ?? null;

        if (!$class) {
            throw new \RuntimeException("Unknown AI driver '{$driver}'.");
        }

        $this->resolved[$name] = new $class($providerConfig);
        return $this->resolved[$name];
    }

    /**
     * Get the default provider name.
     */
    public function getDefault(): string
    {
        return $this->config['default'] ?? 'gemini';
    }

    /**
     * Get all configured provider names.
     */
    public function getConfigured(): array
    {
        return array_keys($this->config['providers'] ?? []);
    }

    /**
     * Get all available providers (configured + have API key or are local).
     */
    public function getAvailable(): array
    {
        $available = [];
        foreach ($this->config['providers'] ?? [] as $name => $config) {
            $driver = $config['driver'] ?? $name;
            // Ollama doesn't need an API key
            if ($driver === 'ollama') {
                $available[] = $name;
                continue;
            }
            // Others need an API key
            if (!empty($config['api_key'])) {
                $available[] = $name;
            }
        }
        return $available;
    }

    /**
     * Get provider display info for the UI.
     */
    public function getProviderInfo(): array
    {
        $info = [
            'gemini' => [
                'name' => 'Google Gemini',
                'icon' => 'gemini',
                'tier' => 'free',
                'description' => 'Fast and free. Great for general page generation.',
            ],
            'groq' => [
                'name' => 'Groq (Llama/Mixtral)',
                'icon' => 'groq',
                'tier' => 'free',
                'description' => 'Ultra-fast inference. Free with rate limits.',
            ],
            'claude' => [
                'name' => 'Anthropic Claude',
                'icon' => 'claude',
                'tier' => 'pro',
                'description' => 'Excellent code generation and template quality.',
            ],
            'openai' => [
                'name' => 'OpenAI GPT',
                'icon' => 'openai',
                'tier' => 'pro',
                'description' => 'Versatile model with strong creative output.',
            ],
            'mistral' => [
                'name' => 'Mistral AI',
                'icon' => 'mistral',
                'tier' => 'pro',
                'description' => 'European AI with strong multilingual support.',
            ],
            'openrouter' => [
                'name' => 'OpenRouter',
                'icon' => 'openrouter',
                'tier' => 'pro',
                'description' => 'Access any model through a single API.',
            ],
            'ollama' => [
                'name' => 'Ollama (Local)',
                'icon' => 'ollama',
                'tier' => 'self-hosted',
                'description' => 'Run AI locally. Fully private, no API key needed.',
            ],
        ];

        $available = $this->getAvailable();
        $result = [];
        foreach ($info as $key => $data) {
            $data['key'] = $key;
            $data['available'] = in_array($key, $available);
            $data['model'] = $this->config['providers'][$key]['model'] ?? '';
            $data['is_default'] = ($key === $this->getDefault());
            $result[] = $data;
        }

        return $result;
    }

    private static function loadConfig(): array
    {
        // Try loading from config/ai.php
        $paths = [
            (defined('BASE_PATH') ? BASE_PATH : getcwd()) . '/config/ai.php',
        ];

        foreach ($paths as $path) {
            if (file_exists($path)) {
                return require $path;
            }
        }

        // Fall back to env-based config
        return [
            'default' => $_ENV['AI_PROVIDER'] ?? 'gemini',
            'providers' => [
                'gemini' => [
                    'driver' => 'gemini',
                    'api_key' => $_ENV['GEMINI_API_KEY'] ?? '',
                    'model' => $_ENV['GEMINI_MODEL'] ?? 'gemini-2.0-flash',
                ],
                'claude' => [
                    'driver' => 'claude',
                    'api_key' => $_ENV['ANTHROPIC_API_KEY'] ?? '',
                    'model' => $_ENV['CLAUDE_MODEL'] ?? 'claude-sonnet-4-20250514',
                ],
                'openai' => [
                    'driver' => 'openai',
                    'api_key' => $_ENV['OPENAI_API_KEY'] ?? '',
                    'model' => $_ENV['OPENAI_MODEL'] ?? 'gpt-4o',
                ],
                'groq' => [
                    'driver' => 'groq',
                    'api_key' => $_ENV['GROQ_API_KEY'] ?? '',
                    'model' => $_ENV['GROQ_MODEL'] ?? 'llama-3.3-70b-versatile',
                ],
                'mistral' => [
                    'driver' => 'mistral',
                    'api_key' => $_ENV['MISTRAL_API_KEY'] ?? '',
                    'model' => $_ENV['MISTRAL_MODEL'] ?? 'mistral-large-latest',
                ],
                'openrouter' => [
                    'driver' => 'openrouter',
                    'api_key' => $_ENV['OPENROUTER_API_KEY'] ?? '',
                    'model' => $_ENV['OPENROUTER_MODEL'] ?? 'anthropic/claude-sonnet-4',
                ],
                'ollama' => [
                    'driver' => 'ollama',
                    'host' => $_ENV['OLLAMA_HOST'] ?? 'http://localhost:11434',
                    'model' => $_ENV['OLLAMA_MODEL'] ?? 'llama3',
                ],
            ],
        ];
    }
}

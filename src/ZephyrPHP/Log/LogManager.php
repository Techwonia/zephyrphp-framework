<?php

declare(strict_types=1);

namespace ZephyrPHP\Log;

use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;

class LogManager
{
    private static ?LogManager $instance = null;
    private array $channels = [];
    private string $defaultChannel = 'app';

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function channel(string $name = null): LoggerInterface
    {
        $name = $name ?? $this->defaultChannel;

        if (!isset($this->channels[$name])) {
            $this->channels[$name] = $this->createLogger($name);
        }

        return $this->channels[$name];
    }

    protected function createLogger(string $channel): Logger
    {
        $path = defined('BASE_PATH') ? BASE_PATH . '/storage/logs' : sys_get_temp_dir();
        $level = $_ENV['LOG_LEVEL'] ?? LogLevel::DEBUG;

        return new Logger($channel, $path, $level);
    }

    public function setDefaultChannel(string $channel): self
    {
        $this->defaultChannel = $channel;
        return $this;
    }

    public function addChannel(string $name, LoggerInterface $logger): self
    {
        $this->channels[$name] = $logger;
        return $this;
    }

    public function emergency($message, array $context = []): void
    {
        $this->channel()->emergency($message, $context);
    }

    public function alert($message, array $context = []): void
    {
        $this->channel()->alert($message, $context);
    }

    public function critical($message, array $context = []): void
    {
        $this->channel()->critical($message, $context);
    }

    public function error($message, array $context = []): void
    {
        $this->channel()->error($message, $context);
    }

    public function warning($message, array $context = []): void
    {
        $this->channel()->warning($message, $context);
    }

    public function notice($message, array $context = []): void
    {
        $this->channel()->notice($message, $context);
    }

    public function info($message, array $context = []): void
    {
        $this->channel()->info($message, $context);
    }

    public function debug($message, array $context = []): void
    {
        $this->channel()->debug($message, $context);
    }
}

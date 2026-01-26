<?php

declare(strict_types=1);

namespace ZephyrPHP\Log;

use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;

class Logger implements LoggerInterface
{
    private string $channel;
    private string $path;
    private string $minLevel;
    private array $processors = [];

    private const LEVELS = [
        LogLevel::DEBUG => 0,
        LogLevel::INFO => 1,
        LogLevel::NOTICE => 2,
        LogLevel::WARNING => 3,
        LogLevel::ERROR => 4,
        LogLevel::CRITICAL => 5,
        LogLevel::ALERT => 6,
        LogLevel::EMERGENCY => 7,
    ];

    public function __construct(string $channel = 'app', ?string $path = null, string $minLevel = LogLevel::DEBUG)
    {
        $this->channel = $channel;
        $this->path = $path ?? (defined('BASE_PATH') ? BASE_PATH . '/storage/logs' : sys_get_temp_dir());
        $this->minLevel = $minLevel;

        if (!is_dir($this->path)) {
            mkdir($this->path, 0755, true);
        }
    }

    public function emergency($message, array $context = []): void
    {
        $this->log(LogLevel::EMERGENCY, $message, $context);
    }

    public function alert($message, array $context = []): void
    {
        $this->log(LogLevel::ALERT, $message, $context);
    }

    public function critical($message, array $context = []): void
    {
        $this->log(LogLevel::CRITICAL, $message, $context);
    }

    public function error($message, array $context = []): void
    {
        $this->log(LogLevel::ERROR, $message, $context);
    }

    public function warning($message, array $context = []): void
    {
        $this->log(LogLevel::WARNING, $message, $context);
    }

    public function notice($message, array $context = []): void
    {
        $this->log(LogLevel::NOTICE, $message, $context);
    }

    public function info($message, array $context = []): void
    {
        $this->log(LogLevel::INFO, $message, $context);
    }

    public function debug($message, array $context = []): void
    {
        $this->log(LogLevel::DEBUG, $message, $context);
    }

    public function log($level, $message, array $context = []): void
    {
        if (!$this->shouldLog($level)) {
            return;
        }

        $record = [
            'timestamp' => date('Y-m-d H:i:s'),
            'channel' => $this->channel,
            'level' => strtoupper($level),
            'message' => $this->interpolate($message, $context),
            'context' => $context,
        ];

        foreach ($this->processors as $processor) {
            $record = $processor($record);
        }

        $this->write($record);
    }

    protected function shouldLog(string $level): bool
    {
        return self::LEVELS[$level] >= self::LEVELS[$this->minLevel];
    }

    protected function interpolate(string $message, array $context): string
    {
        $replace = [];
        foreach ($context as $key => $val) {
            if (is_string($val) || (is_object($val) && method_exists($val, '__toString'))) {
                $replace['{' . $key . '}'] = $val;
            } elseif (is_array($val)) {
                $replace['{' . $key . '}'] = json_encode($val);
            } elseif (is_bool($val)) {
                $replace['{' . $key . '}'] = $val ? 'true' : 'false';
            } elseif (is_null($val)) {
                $replace['{' . $key . '}'] = 'null';
            }
        }

        return strtr($message, $replace);
    }

    protected function write(array $record): void
    {
        $filename = $this->path . '/' . $this->channel . '-' . date('Y-m-d') . '.log';

        $line = sprintf(
            "[%s] %s.%s: %s %s\n",
            $record['timestamp'],
            $record['channel'],
            $record['level'],
            $record['message'],
            !empty($record['context']) ? json_encode($record['context']) : ''
        );

        file_put_contents($filename, $line, FILE_APPEND | LOCK_EX);
    }

    public function pushProcessor(callable $processor): self
    {
        $this->processors[] = $processor;
        return $this;
    }

    public function getChannel(): string
    {
        return $this->channel;
    }

    public function withChannel(string $channel): self
    {
        $logger = clone $this;
        $logger->channel = $channel;
        return $logger;
    }
}

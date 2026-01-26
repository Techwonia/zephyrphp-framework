<?php

declare(strict_types=1);

namespace ZephyrPHP\Exception;

use Exception;

class HttpException extends Exception
{
    private int $statusCode;
    private array $headers;

    public function __construct(int $statusCode, string $message = '', array $headers = [], ?Exception $previous = null)
    {
        $this->statusCode = $statusCode;
        $this->headers = $headers;

        parent::__construct($message, 0, $previous);
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    public function getHeaders(): array
    {
        return $this->headers;
    }

    public static function notFound(string $message = 'Not Found'): self
    {
        return new self(404, $message);
    }

    public static function badRequest(string $message = 'Bad Request'): self
    {
        return new self(400, $message);
    }

    public static function unauthorized(string $message = 'Unauthorized'): self
    {
        return new self(401, $message);
    }

    public static function forbidden(string $message = 'Forbidden'): self
    {
        return new self(403, $message);
    }

    public static function methodNotAllowed(string $message = 'Method Not Allowed'): self
    {
        return new self(405, $message);
    }

    public static function conflict(string $message = 'Conflict'): self
    {
        return new self(409, $message);
    }

    public static function unprocessableEntity(string $message = 'Unprocessable Entity'): self
    {
        return new self(422, $message);
    }

    public static function tooManyRequests(string $message = 'Too Many Requests'): self
    {
        return new self(429, $message);
    }

    public static function serverError(string $message = 'Internal Server Error'): self
    {
        return new self(500, $message);
    }

    public static function serviceUnavailable(string $message = 'Service Unavailable'): self
    {
        return new self(503, $message);
    }
}

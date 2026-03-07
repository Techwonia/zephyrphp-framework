<?php

declare(strict_types=1);

namespace ZephyrPHP\Core\Controllers;

use ZephyrPHP\Core\Http\Request;
use ZephyrPHP\Core\Http\Response;
use ZephyrPHP\View\View;
use ZephyrPHP\Session\Session;
use ZephyrPHP\Validation\Validator;
use ZephyrPHP\Router\Route;

abstract class Controller
{
    protected View $view;
    protected Request $request;
    protected Response $response;
    protected Session $session;

    public function __construct()
    {
        $this->view = View::getInstance();
        $this->request = Request::getInstance();
        $this->response = new Response();
        $this->session = Session::getInstance();
    }

    protected function render(string $template, array $variables = []): string
    {
        $variables['session'] = $this->session;
        $variables['flash'] = fn(string $key, $default = null) => $this->session->getFlash($key, $default);
        $variables['old'] = fn(string $key, $default = null) => $this->request->old($key, $default);

        return $this->view->render($template, $variables);
    }

    protected function json($data, int $statusCode = 200): string
    {
        $this->response->setHeader('Content-Type', 'application/json; charset=utf-8');
        $this->response->setHeader('X-Content-Type-Options', 'nosniff');
        $this->response->setStatusCode($statusCode);
        $content = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        $this->response->setContent($content);
        $this->response->send();
        return $content;
    }

    protected function text(string $text, int $statusCode = 200): void
    {
        $this->response->setHeader('Content-Type', 'text/plain; charset=utf-8');
        $this->response->setHeader('X-Content-Type-Options', 'nosniff');
        $this->response->setStatusCode($statusCode);
        $this->response->setContent($text);
        $this->response->send();
    }

    protected function xml(string $xml, int $statusCode = 200): void
    {
        $this->response->setHeader('Content-Type', 'application/xml; charset=utf-8');
        $this->response->setHeader('X-Content-Type-Options', 'nosniff');
        $this->response->setStatusCode($statusCode);
        $this->response->setContent($xml);
        $this->response->send();
    }

    protected function html(string $content, int $statusCode = 200): void
    {
        $this->response->setHeader('Content-Type', 'text/html; charset=utf-8');
        $this->response->setHeader('X-Content-Type-Options', 'nosniff');
        $this->response->setStatusCode($statusCode);
        $this->response->setContent($content);
        $this->response->send();
    }

    protected function custom(string $content, string $contentType, int $statusCode = 200): void
    {
        $this->response->setHeader('Content-Type', $contentType);
        $this->response->setHeader('X-Content-Type-Options', 'nosniff');
        $this->response->setStatusCode($statusCode);
        $this->response->setContent($content);
        $this->response->send();
    }

    protected function redirect(string $url, int $statusCode = 302): never
    {
        $basePath = $_ENV["BASE_PATH"] ?? '';
        $url = $basePath . $url;
        $this->response->redirect($url, $statusCode);
    }

    protected function redirectToRoute(string $name, array $parameters = [], int $statusCode = 302): never
    {
        $url = Route::url($name, $parameters);
        $this->redirect($url, $statusCode);
    }

    protected function back(string $fallback = '/'): never
    {
        $url = $this->request->previousUrl() ?? $fallback;
        $this->response->redirect($url);
    }

    protected function backWithInput(): never
    {
        $this->request->flash();
        $this->back();
    }

    protected function backWithErrors(array $errors): never
    {
        $this->request->flash();
        $this->session->flash('errors', $errors);
        $this->back();
    }

    protected function getParameters(): array
    {
        return $this->request->all();
    }

    protected function getQueries(): array
    {
        return $this->request->query();
    }

    // ========================================================================
    // REQUEST INPUT SHORTCUTS
    // ========================================================================

    /**
     * Get input value
     */
    protected function input(string $key, $default = null)
    {
        return $this->request->input($key, $default);
    }

    /**
     * Get all input
     */
    protected function all(): array
    {
        return $this->request->all();
    }

    /**
     * Get only specific keys
     */
    protected function only(array $keys): array
    {
        return $this->request->only($keys);
    }

    /**
     * Get all except specific keys
     */
    protected function except(array $keys): array
    {
        return $this->request->except($keys);
    }

    /**
     * Check if input exists
     */
    protected function has(string|array $keys): bool
    {
        return $this->request->has($keys);
    }

    /**
     * Check if input exists and is not empty
     */
    protected function filled(string|array $keys): bool
    {
        return $this->request->filled($keys);
    }

    /**
     * Get input as string
     */
    protected function string(string $key, string $default = ''): string
    {
        return $this->request->string($key, $default);
    }

    /**
     * Get input as integer
     */
    protected function integer(string $key, int $default = 0): int
    {
        return $this->request->integer($key, $default);
    }

    /**
     * Get input as float
     */
    protected function float(string $key, float $default = 0.0): float
    {
        return $this->request->float($key, $default);
    }

    /**
     * Get input as boolean
     */
    protected function boolean(string $key, bool $default = false): bool
    {
        return $this->request->boolean($key, $default);
    }

    /**
     * Get input as array
     */
    protected function array(string $key, array $default = []): array
    {
        return $this->request->array($key, $default);
    }

    /**
     * Get input as date
     */
    protected function date(string $key, ?string $format = null): ?\DateTime
    {
        return $this->request->date($key, $format);
    }

    /**
     * Get query parameter
     */
    protected function query(?string $key = null, $default = null)
    {
        return $this->request->query($key, $default);
    }

    /**
     * Get old input value
     */
    protected function old(string $key, $default = null)
    {
        return $this->request->old($key, $default);
    }

    /**
     * Get bearer token
     */
    protected function bearerToken(): ?string
    {
        return $this->request->bearerToken();
    }

    /**
     * Get client IP
     */
    protected function ip(): string
    {
        return $this->request->ip();
    }

    /**
     * Get user agent
     */
    protected function userAgent(): string
    {
        return $this->request->userAgent();
    }

    /**
     * Check if expects JSON
     */
    protected function wantsJson(): bool
    {
        return $this->request->wantsJson();
    }

    /**
     * Get route parameter
     */
    protected function route(?string $key = null, $default = null)
    {
        return $this->request->route($key, $default);
    }

    protected function validate(array $rules, ?array $messages = null): array
    {
        $validator = Validator::make($this->request->all(), $rules, $messages ?? []);

        if ($validator->fails()) {
            if ($this->request->expectsJson()) {
                Response::validationError($validator->errors())->sendAndExit();
            }

            $this->backWithErrors($validator->errors());
        }

        return $validator->validated();
    }

    protected function jsonResponse(bool $success, string $message, int $statusCode = 200, array $data = []): string
    {
        return $this->json(array_merge([
            "success" => $success,
            "message" => $message
        ], $data), $statusCode);
    }

    protected function getMethod(): string
    {
        return $this->request->getMethod();
    }

    protected function getJsonPayload(): ?array
    {
        $data = $this->request->getJsonPayload();

        if ($data === null) {
            $this->jsonResponse(false, "Invalid JSON format", 400);
            return null;
        }

        return $data;
    }

    protected function validateCSRF(): bool
    {
        if (!$this->request->validateCSRFToken()) {
            if ($this->request->expectsJson()) {
                $this->jsonResponse(false, "Invalid CSRF token", 403);
            } else {
                $this->session->flash('error', 'Invalid CSRF token. Please try again.');
                $this->back();
            }
            return false;
        }
        return true;
    }

    protected function isAjax(): bool
    {
        return $this->request->isAjax();
    }

    protected function getUploadedFiles(): array
    {
        return $this->request->files();
    }

    protected function file(string $key): ?array
    {
        return $this->request->file($key);
    }

    protected function flash(string $key, $value): void
    {
        $this->session->flash($key, $value);
    }

    protected function getFlash(string $key, $default = null)
    {
        return $this->session->getFlash($key, $default);
    }

    protected function hasFlash(string $key): bool
    {
        return $this->session->hasFlash($key);
    }

    protected function download(string $filePath, ?string $name = null): never
    {
        $this->response->download($filePath, $name);
    }

    protected function streamFile(string $filePath, ?string $name = null): never
    {
        $this->response->file($filePath, $name);
    }

    protected function noContent(): void
    {
        Response::noContent()->send();
    }

    protected function created($data = null, ?string $location = null): void
    {
        Response::created($data, $location)->send();
    }

    protected function notFound(string $message = 'Not Found'): void
    {
        Response::notFound($message)->send();
    }

    protected function badRequest(string $message = 'Bad Request'): void
    {
        Response::badRequest($message)->send();
    }

    protected function unauthorized(string $message = 'Unauthorized'): void
    {
        Response::unauthorized($message)->send();
    }

    protected function forbidden(string $message = 'Forbidden'): void
    {
        Response::forbidden($message)->send();
    }
}

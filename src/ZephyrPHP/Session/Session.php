<?php

declare(strict_types=1);

namespace ZephyrPHP\Session;

class Session
{
    private static ?Session $instance = null;
    private bool $started = false;
    private array $config = [];

    public function __construct(array $config = [])
    {
        $this->config = array_merge([
            'name' => 'zephyr_session',
            'lifetime' => (int) ($_ENV['SESSION_LIFETIME'] ?? 120) * 60,
            'path' => '/',
            'domain' => null,
            'secure' => filter_var($_ENV['SESSION_SECURE_COOKIE'] ?? (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'), FILTER_VALIDATE_BOOLEAN),
            'httponly' => true,
            'samesite' => 'Lax',
        ], $config);
    }

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function start(): bool
    {
        if ($this->started || session_status() === PHP_SESSION_ACTIVE) {
            $this->started = true;
            return true;
        }

        if (headers_sent()) {
            return false;
        }

        session_name($this->config['name']);

        session_set_cookie_params([
            'lifetime' => $this->config['lifetime'],
            'path' => $this->config['path'],
            'domain' => $this->config['domain'],
            'secure' => $this->config['secure'],
            'httponly' => $this->config['httponly'],
            'samesite' => $this->config['samesite'],
        ]);

        $this->started = session_start();

        if ($this->started) {
            $this->regenerateIfNeeded();
        }

        return $this->started;
    }

    protected function regenerateIfNeeded(): void
    {
        $lastRegenerate = $this->get('_last_regenerate', 0);
        $regenerateInterval = 300;

        if (time() - $lastRegenerate > $regenerateInterval) {
            $this->regenerate();
        }
    }

    public function regenerate(bool $deleteOld = true): bool
    {
        if (!$this->started) {
            return false;
        }

        $result = session_regenerate_id($deleteOld);

        if ($result) {
            $this->set('_last_regenerate', time());
        }

        return $result;
    }

    public function get(string $key, $default = null)
    {
        $this->ensureStarted();

        $keys = explode('.', $key);
        $value = $_SESSION;

        foreach ($keys as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return $default;
            }
            $value = $value[$segment];
        }

        return $value;
    }

    public function set(string $key, $value): self
    {
        $this->ensureStarted();

        $keys = explode('.', $key);
        $session = &$_SESSION;

        foreach ($keys as $i => $segment) {
            if ($i === count($keys) - 1) {
                $session[$segment] = $value;
            } else {
                if (!isset($session[$segment]) || !is_array($session[$segment])) {
                    $session[$segment] = [];
                }
                $session = &$session[$segment];
            }
        }

        return $this;
    }

    public function has(string $key): bool
    {
        return $this->get($key) !== null;
    }

    public function remove(string $key): self
    {
        $this->ensureStarted();

        $keys = explode('.', $key);
        $session = &$_SESSION;

        foreach ($keys as $i => $segment) {
            if ($i === count($keys) - 1) {
                unset($session[$segment]);
            } else {
                if (!isset($session[$segment]) || !is_array($session[$segment])) {
                    return $this;
                }
                $session = &$session[$segment];
            }
        }

        return $this;
    }

    public function all(): array
    {
        $this->ensureStarted();
        return $_SESSION;
    }

    public function clear(): self
    {
        $this->ensureStarted();
        $_SESSION = [];
        return $this;
    }

    public function destroy(): bool
    {
        if (!$this->started) {
            return false;
        }

        $_SESSION = [];

        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(
                session_name(),
                '',
                time() - 42000,
                $params['path'],
                $params['domain'],
                $params['secure'],
                $params['httponly']
            );
        }

        $result = session_destroy();
        $this->started = false;

        return $result;
    }

    /**
     * Set a flash message
     */
    public function flash(string $key, $value): self
    {
        Flash::set($key, $value);
        return $this;
    }

    /**
     * Get a flash message
     */
    public function getFlash(string $key, $default = null)
    {
        return Flash::get($key, $default);
    }

    /**
     * Check if flash message exists
     */
    public function hasFlash(string $key): bool
    {
        return Flash::has($key);
    }

    /**
     * Age flash data - call at start of each request
     */
    public function ageFlashData(): self
    {
        Flash::age();
        return $this;
    }

    /**
     * Keep flash data for another request
     */
    public function reflash(): self
    {
        Flash::keep();
        return $this;
    }

    /**
     * Keep specific flash keys for another request
     */
    public function keep(array $keys): self
    {
        Flash::keep($keys);
        return $this;
    }

    public function getId(): string
    {
        $this->ensureStarted();
        return session_id();
    }

    public function setId(string $id): self
    {
        if ($this->started) {
            throw new \RuntimeException('Cannot change session ID after session has started');
        }

        // Validate session ID format to prevent session fixation with arbitrary IDs
        if (!preg_match('/^[a-zA-Z0-9,-]{22,256}$/', $id)) {
            throw new \InvalidArgumentException('Invalid session ID format');
        }

        session_id($id);
        return $this;
    }

    public function isStarted(): bool
    {
        return $this->started;
    }

    protected function ensureStarted(): void
    {
        if (!$this->started) {
            $this->start();
        }
    }

    public function previousUrl(): ?string
    {
        return $this->get('_previous_url');
    }

    public function setPreviousUrl(string $url): self
    {
        return $this->set('_previous_url', $url);
    }

    public function token(): string
    {
        $this->ensureStarted();

        if (!$this->has('_token')) {
            $this->set('_token', bin2hex(random_bytes(32)));
        }

        return $this->get('_token');
    }

    public function regenerateToken(): string
    {
        $this->set('_token', bin2hex(random_bytes(32)));
        return $this->get('_token');
    }

    public function increment(string $key, int $amount = 1): int
    {
        $value = (int) $this->get($key, 0) + $amount;
        $this->set($key, $value);
        return $value;
    }

    public function decrement(string $key, int $amount = 1): int
    {
        return $this->increment($key, -$amount);
    }

    public function push(string $key, $value): self
    {
        $array = $this->get($key, []);

        if (!is_array($array)) {
            $array = [$array];
        }

        $array[] = $value;
        $this->set($key, $array);

        return $this;
    }

    public function pull(string $key, $default = null)
    {
        $value = $this->get($key, $default);
        $this->remove($key);
        return $value;
    }
}

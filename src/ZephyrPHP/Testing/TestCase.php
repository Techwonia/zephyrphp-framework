<?php

declare(strict_types=1);

namespace ZephyrPHP\Testing;

/**
 * Base test case for ZephyrPHP applications.
 *
 * Extends PHPUnit's TestCase with framework-specific helpers:
 * - HTTP request simulation
 * - Database assertions
 * - Session/Flash helpers
 * - JSON assertions
 *
 * Usage:
 *   class UserTest extends \ZephyrPHP\Testing\TestCase
 *   {
 *       public function testHomePage(): void
 *       {
 *           $response = $this->get('/');
 *           $response->assertStatus(200);
 *           $response->assertSee('Welcome');
 *       }
 *   }
 */
class TestCase extends \PHPUnit\Framework\TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Reset superglobals
        $_GET = [];
        $_POST = [];
        $_COOKIE = [];
        $_SESSION = [];
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/';
    }

    /**
     * Simulate a GET request.
     */
    protected function get(string $uri, array $headers = []): TestResponse
    {
        return $this->call('GET', $uri, [], $headers);
    }

    /**
     * Simulate a POST request.
     */
    protected function post(string $uri, array $data = [], array $headers = []): TestResponse
    {
        return $this->call('POST', $uri, $data, $headers);
    }

    /**
     * Simulate a PUT request.
     */
    protected function put(string $uri, array $data = [], array $headers = []): TestResponse
    {
        return $this->call('PUT', $uri, $data, $headers);
    }

    /**
     * Simulate a PATCH request.
     */
    protected function patch(string $uri, array $data = [], array $headers = []): TestResponse
    {
        return $this->call('PATCH', $uri, $data, $headers);
    }

    /**
     * Simulate a DELETE request.
     */
    protected function delete(string $uri, array $data = [], array $headers = []): TestResponse
    {
        return $this->call('DELETE', $uri, $data, $headers);
    }

    /**
     * Simulate a JSON request.
     */
    protected function json(string $method, string $uri, array $data = [], array $headers = []): TestResponse
    {
        $headers['HTTP_ACCEPT'] = 'application/json';
        $headers['CONTENT_TYPE'] = 'application/json';

        return $this->call($method, $uri, $data, $headers);
    }

    /**
     * Simulate an HTTP request.
     */
    protected function call(string $method, string $uri, array $data = [], array $headers = []): TestResponse
    {
        $_SERVER['REQUEST_METHOD'] = strtoupper($method);
        $_SERVER['REQUEST_URI'] = $uri;

        // Parse query string
        $parts = parse_url($uri);
        if (isset($parts['query'])) {
            parse_str($parts['query'], $_GET);
        }

        // Set POST data
        if (in_array(strtoupper($method), ['POST', 'PUT', 'PATCH', 'DELETE'])) {
            $_POST = $data;
        }

        // Set headers
        foreach ($headers as $key => $value) {
            $serverKey = strtoupper(str_replace('-', '_', $key));
            if (!str_starts_with($serverKey, 'HTTP_') && $serverKey !== 'CONTENT_TYPE') {
                $serverKey = 'HTTP_' . $serverKey;
            }
            $_SERVER[$serverKey] = $value;
        }

        // Capture output
        ob_start();
        $statusCode = 200;

        try {
            // If the app has a router, dispatch through it
            if (class_exists(\ZephyrPHP\Router\Route::class)) {
                \ZephyrPHP\Router\Route::dispatch();
            }
        } catch (\ZephyrPHP\Exception\HttpException $e) {
            $statusCode = $e->getStatusCode();
        } catch (\Throwable $e) {
            $statusCode = 500;
        }

        $content = ob_get_clean() ?: '';
        $actualStatus = http_response_code() ?: $statusCode;

        return new TestResponse($content, $actualStatus);
    }

    /**
     * Set a session value before making a request.
     */
    protected function withSession(array $data): static
    {
        foreach ($data as $key => $value) {
            $_SESSION[$key] = $value;
        }
        return $this;
    }

    /**
     * Set cookies before making a request.
     */
    protected function withCookies(array $cookies): static
    {
        foreach ($cookies as $key => $value) {
            $_COOKIE[$key] = $value;
        }
        return $this;
    }

    /**
     * Set the authenticated user for the request.
     */
    protected function actingAs(object $user): static
    {
        $_SESSION['user_id'] = $user->id ?? null;
        $_SESSION['user'] = $user;
        return $this;
    }

    /**
     * Assert a database table has a row matching conditions.
     */
    protected function assertDatabaseHas(string $table, array $data): void
    {
        if (!class_exists(\ZephyrPHP\Database\DB::class)) {
            $this->markTestSkipped('Database module not available');
            return;
        }

        $qb = \ZephyrPHP\Database\DB::table($table);
        foreach ($data as $column => $value) {
            $qb->where($column, '=', $value);
        }

        $result = $qb->first();
        $this->assertNotNull($result, "Failed asserting that table [{$table}] has a matching row: " . json_encode($data));
    }

    /**
     * Assert a database table does not have a row matching conditions.
     */
    protected function assertDatabaseMissing(string $table, array $data): void
    {
        if (!class_exists(\ZephyrPHP\Database\DB::class)) {
            $this->markTestSkipped('Database module not available');
            return;
        }

        $qb = \ZephyrPHP\Database\DB::table($table);
        foreach ($data as $column => $value) {
            $qb->where($column, '=', $value);
        }

        $result = $qb->first();
        $this->assertNull($result, "Failed asserting that table [{$table}] does not have a matching row: " . json_encode($data));
    }

    /**
     * Assert a database table has a specific number of rows.
     */
    protected function assertDatabaseCount(string $table, int $count): void
    {
        if (!class_exists(\ZephyrPHP\Database\DB::class)) {
            $this->markTestSkipped('Database module not available');
            return;
        }

        $actual = \ZephyrPHP\Database\DB::table($table)->count();
        $this->assertEquals($count, $actual, "Failed asserting that table [{$table}] has {$count} rows. Found {$actual}.");
    }
}

<?php

declare(strict_types=1);

namespace ZephyrPHP\Testing;

/**
 * Fluent response wrapper for testing HTTP responses.
 *
 * Usage:
 *   $response = $this->get('/users');
 *   $response->assertStatus(200)
 *            ->assertSee('John')
 *            ->assertDontSee('Admin Panel');
 */
class TestResponse
{
    protected string $content;
    protected int $statusCode;

    public function __construct(string $content, int $statusCode)
    {
        $this->content = $content;
        $this->statusCode = $statusCode;
    }

    /**
     * Get the response body content.
     */
    public function getContent(): string
    {
        return $this->content;
    }

    /**
     * Get the response status code.
     */
    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    /**
     * Assert the response has the given status code.
     */
    public function assertStatus(int $status): static
    {
        \PHPUnit\Framework\Assert::assertEquals(
            $status,
            $this->statusCode,
            "Expected status code {$status} but received {$this->statusCode}."
        );
        return $this;
    }

    /**
     * Assert the response is successful (2xx).
     */
    public function assertOk(): static
    {
        return $this->assertStatus(200);
    }

    /**
     * Assert the response is a redirect (3xx).
     */
    public function assertRedirect(?string $uri = null): static
    {
        \PHPUnit\Framework\Assert::assertTrue(
            $this->statusCode >= 300 && $this->statusCode < 400,
            "Expected redirect status but received {$this->statusCode}."
        );
        return $this;
    }

    /**
     * Assert the response is not found.
     */
    public function assertNotFound(): static
    {
        return $this->assertStatus(404);
    }

    /**
     * Assert the response is forbidden.
     */
    public function assertForbidden(): static
    {
        return $this->assertStatus(403);
    }

    /**
     * Assert the response is unauthorized.
     */
    public function assertUnauthorized(): static
    {
        return $this->assertStatus(401);
    }

    /**
     * Assert the response body contains the given string.
     */
    public function assertSee(string $value): static
    {
        \PHPUnit\Framework\Assert::assertStringContainsString(
            $value,
            $this->content,
            "Failed asserting that response contains \"{$value}\"."
        );
        return $this;
    }

    /**
     * Assert the response body does NOT contain the given string.
     */
    public function assertDontSee(string $value): static
    {
        \PHPUnit\Framework\Assert::assertStringNotContainsString(
            $value,
            $this->content,
            "Failed asserting that response does not contain \"{$value}\"."
        );
        return $this;
    }

    /**
     * Assert the response body contains the given text (HTML decoded).
     */
    public function assertSeeText(string $value): static
    {
        $plainText = strip_tags($this->content);
        \PHPUnit\Framework\Assert::assertStringContainsString(
            $value,
            $plainText,
            "Failed asserting that response text contains \"{$value}\"."
        );
        return $this;
    }

    /**
     * Decode the response as JSON.
     */
    public function json(?string $key = null): mixed
    {
        $data = json_decode($this->content, true);

        if ($key === null) {
            return $data;
        }

        $segments = explode('.', $key);
        foreach ($segments as $segment) {
            if (!is_array($data) || !array_key_exists($segment, $data)) {
                return null;
            }
            $data = $data[$segment];
        }

        return $data;
    }

    /**
     * Assert the response is valid JSON.
     */
    public function assertJson(array $data = []): static
    {
        $decoded = json_decode($this->content, true);

        \PHPUnit\Framework\Assert::assertNotNull(
            $decoded,
            'Failed asserting that response is valid JSON.'
        );

        if (!empty($data)) {
            foreach ($data as $key => $value) {
                \PHPUnit\Framework\Assert::assertArrayHasKey($key, $decoded);
                \PHPUnit\Framework\Assert::assertEquals($value, $decoded[$key]);
            }
        }

        return $this;
    }

    /**
     * Assert the JSON response has the given key.
     */
    public function assertJsonHas(string $key): static
    {
        $value = $this->json($key);
        \PHPUnit\Framework\Assert::assertNotNull(
            $value,
            "Failed asserting that JSON response has key \"{$key}\"."
        );
        return $this;
    }

    /**
     * Assert the JSON response does not have the given key.
     */
    public function assertJsonMissing(string $key): static
    {
        $value = $this->json($key);
        \PHPUnit\Framework\Assert::assertNull(
            $value,
            "Failed asserting that JSON response is missing key \"{$key}\"."
        );
        return $this;
    }

    /**
     * Assert the JSON response has the given count for an array key.
     */
    public function assertJsonCount(int $count, ?string $key = null): static
    {
        $data = $key ? $this->json($key) : $this->json();

        \PHPUnit\Framework\Assert::assertIsArray($data);
        \PHPUnit\Framework\Assert::assertCount(
            $count,
            $data,
            "Failed asserting that JSON " . ($key ? "key \"{$key}\"" : "response") . " has {$count} items."
        );

        return $this;
    }

    /**
     * Assert the JSON response matches the given structure.
     */
    public function assertJsonStructure(array $structure, ?array $data = null): static
    {
        $data = $data ?? $this->json();

        foreach ($structure as $key => $value) {
            if (is_array($value)) {
                \PHPUnit\Framework\Assert::assertArrayHasKey($key, $data);
                $this->assertJsonStructure($value, $data[$key]);
            } else {
                \PHPUnit\Framework\Assert::assertArrayHasKey($value, $data);
            }
        }

        return $this;
    }

    /**
     * Dump the response content for debugging.
     */
    public function dump(): static
    {
        echo "\n--- Response [{$this->statusCode}] ---\n";
        echo $this->content;
        echo "\n--- End Response ---\n";
        return $this;
    }
}

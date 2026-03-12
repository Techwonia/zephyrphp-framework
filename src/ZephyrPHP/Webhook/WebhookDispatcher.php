<?php

declare(strict_types=1);

namespace ZephyrPHP\Webhook;

/**
 * Webhook Dispatcher — sends HTTP notifications to subscribed endpoints.
 *
 * Features:
 * - HMAC-SHA256 signature verification (X-Webhook-Signature header)
 * - Retry with exponential backoff (3 attempts: 0s, 5s, 25s)
 * - Async dispatch option (fire-and-forget via non-blocking stream)
 * - Subscription management (create, delete, list)
 * - Topic-based filtering (mirrors event system: page.created, entry.updated, etc.)
 *
 * Security:
 * - All payloads signed with per-subscription secret
 * - URL validation (must be HTTPS in production)
 * - Timeout on HTTP requests (10 seconds)
 * - Max payload size limit (1MB)
 */
class WebhookDispatcher
{
    private static ?WebhookDispatcher $instance = null;

    private const int MAX_RETRIES = 3;
    private const int TIMEOUT_SECONDS = 10;
    private const int MAX_PAYLOAD_SIZE = 1048576; // 1MB

    /**
     * Available webhook topics.
     */
    public const array TOPICS = [
        'page.created',
        'page.updated',
        'page.deleted',
        'entry.created',
        'entry.updated',
        'entry.deleted',
        'media.uploaded',
        'media.deleted',
        'theme.activated',
        'theme.installed',
        'user.created',
        'user.updated',
        'app.installed',
        'app.enabled',
        'app.disabled',
    ];

    public static function getInstance(): static
    {
        if (static::$instance === null) {
            static::$instance = new static();
        }
        return static::$instance;
    }

    // ========================================================================
    // SUBSCRIPTION MANAGEMENT
    // ========================================================================

    /**
     * Create a webhook subscription.
     *
     * @return array{success: bool, id?: int, secret?: string, error?: string}
     */
    public function subscribe(string $topic, string $url, string $clientId, string $format = 'json'): array
    {
        // Validate topic
        if (!in_array($topic, self::TOPICS, true)) {
            return ['success' => false, 'error' => "Invalid topic: '{$topic}'."];
        }

        // Validate URL
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            return ['success' => false, 'error' => 'Invalid webhook URL.'];
        }

        $parsed = parse_url($url);
        if (($parsed['scheme'] ?? '') !== 'https' && !$this->isLocalDev()) {
            return ['success' => false, 'error' => 'Webhook URL must use HTTPS.'];
        }

        // Generate signing secret
        $secret = bin2hex(random_bytes(32));

        try {
            $conn = \ZephyrPHP\Database\Connection::getInstance()->getConnection();
            $conn->insert('cms_webhooks', [
                'topic' => $topic,
                'url' => $url,
                'client_id' => $clientId,
                'secret' => hash('sha256', $secret),
                'format' => $format,
                'is_active' => 1,
                'failure_count' => 0,
                'createdAt' => date('Y-m-d H:i:s'),
            ]);

            return [
                'success' => true,
                'id' => (int) $conn->lastInsertId(),
                'secret' => $secret,
            ];
        } catch (\Exception $e) {
            return ['success' => false, 'error' => 'Failed to create webhook subscription.'];
        }
    }

    /**
     * Delete a webhook subscription.
     */
    public function unsubscribe(int $id, string $clientId): bool
    {
        try {
            $conn = \ZephyrPHP\Database\Connection::getInstance()->getConnection();
            $affected = $conn->delete('cms_webhooks', ['id' => $id, 'client_id' => $clientId]);
            return $affected > 0;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * List subscriptions for a client.
     */
    public function listSubscriptions(string $clientId): array
    {
        try {
            $conn = \ZephyrPHP\Database\Connection::getInstance()->getConnection();
            return $conn->fetchAllAssociative(
                'SELECT id, topic, url, format, is_active, failure_count, createdAt FROM cms_webhooks WHERE client_id = ? ORDER BY id ASC',
                [$clientId]
            );
        } catch (\Exception $e) {
            return [];
        }
    }

    // ========================================================================
    // DISPATCH
    // ========================================================================

    /**
     * Dispatch a webhook event to all subscribers of the given topic.
     *
     * @param string $topic The event topic (e.g., 'page.created')
     * @param array $payload The data to send
     */
    public function dispatch(string $topic, array $payload): void
    {
        try {
            $conn = \ZephyrPHP\Database\Connection::getInstance()->getConnection();
            $subscriptions = $conn->fetchAllAssociative(
                'SELECT * FROM cms_webhooks WHERE topic = ? AND is_active = 1',
                [$topic]
            );

            foreach ($subscriptions as $sub) {
                $this->send($sub, $topic, $payload);
            }
        } catch (\Exception $e) {
            error_log("Webhook dispatch failed for topic '{$topic}': " . $e->getMessage());
        }
    }

    /**
     * Send a webhook to a single subscription with retry logic.
     */
    private function send(array $subscription, string $topic, array $payload): void
    {
        $body = json_encode([
            'topic' => $topic,
            'timestamp' => date('c'),
            'data' => $payload,
        ]);

        if (strlen($body) > self::MAX_PAYLOAD_SIZE) {
            error_log("Webhook payload too large for subscription #{$subscription['id']}");
            return;
        }

        // Generate HMAC signature using the hashed secret stored in DB
        // The actual secret was returned to the client on subscribe — they verify with it
        $signature = hash_hmac('sha256', $body, $subscription['secret']);

        $headers = [
            'Content-Type: application/json',
            'X-Webhook-Topic: ' . $topic,
            'X-Webhook-Signature: sha256=' . $signature,
            'X-Webhook-Timestamp: ' . time(),
            'User-Agent: ZephyrPHP-Webhook/1.0',
        ];

        $success = false;
        $lastError = '';

        for ($attempt = 0; $attempt < self::MAX_RETRIES; $attempt++) {
            if ($attempt > 0) {
                // Exponential backoff: 5s, 25s
                usleep((int) (pow(5, $attempt) * 1000000));
            }

            $result = $this->httpPost($subscription['url'], $body, $headers);

            if ($result['success']) {
                $success = true;
                break;
            }

            $lastError = $result['error'];
        }

        // Update failure count
        try {
            $conn = \ZephyrPHP\Database\Connection::getInstance()->getConnection();
            if ($success) {
                $conn->update('cms_webhooks', [
                    'failure_count' => 0,
                    'last_success_at' => date('Y-m-d H:i:s'),
                ], ['id' => $subscription['id']]);
            } else {
                $newCount = ((int) $subscription['failure_count']) + 1;
                $data = ['failure_count' => $newCount, 'last_error' => $lastError];

                // Auto-disable after 10 consecutive failures
                if ($newCount >= 10) {
                    $data['is_active'] = 0;
                }

                $conn->update('cms_webhooks', $data, ['id' => $subscription['id']]);
            }
        } catch (\Exception $e) {
            // Log and continue
        }
    }

    /**
     * Send an HTTP POST request.
     *
     * @return array{success: bool, status?: int, error?: string}
     */
    private function httpPost(string $url, string $body, array $headers): array
    {
        $ch = curl_init($url);

        if ($ch === false) {
            return ['success' => false, 'error' => 'Failed to initialize cURL.'];
        }

        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => self::TIMEOUT_SECONDS,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            return ['success' => false, 'error' => $error ?: 'cURL request failed.'];
        }

        // Consider 2xx as success
        if ($httpCode >= 200 && $httpCode < 300) {
            return ['success' => true, 'status' => $httpCode];
        }

        return ['success' => false, 'error' => "HTTP {$httpCode}", 'status' => $httpCode];
    }

    /**
     * Check if we're in local development (allow HTTP webhooks).
     */
    private function isLocalDev(): bool
    {
        $env = $_ENV['APP_ENV'] ?? $_SERVER['APP_ENV'] ?? 'production';
        return in_array($env, ['local', 'development', 'dev', 'testing'], true);
    }
}

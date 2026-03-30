<?php

declare(strict_types=1);

namespace ZephyrPHP\Exception;

use Throwable;
use ZephyrPHP\Config\Config;
use ZephyrPHP\Session\Flash;

class Handler
{
    private static ?Handler $instance = null;
    private bool $debug = false;
    private ?string $logPath = null;
    private array $dontReport = [];
    /** @var callable|null */
    private mixed $customRenderer = null;
    private bool $rendering = false;

    /**
     * Custom exception handlers registered by exception class
     * @var array<string, callable>
     */
    private array $exceptionHandlers = [];

    /**
     * Exception messages from config (config/exceptions.php)
     */
    private array $exceptionMessages = [];

    public function __construct()
    {
        $isDebug = filter_var($_ENV['APP_DEBUG'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $appEnv = $_ENV['ENV'] ?? $_ENV['APP_ENV'] ?? 'dev';

        // Safety: never enable debug mode in production even if APP_DEBUG is misconfigured
        if (in_array($appEnv, ['production', 'prod'], true)) {
            $isDebug = false;
        }

        $this->debug = $isDebug;
        $this->logPath = defined('BASE_PATH') ? BASE_PATH . '/storage/logs' : null;
        $this->loadExceptionConfig();
    }

    /**
     * Load exception messages from config file
     */
    private function loadExceptionConfig(): void
    {
        $this->exceptionMessages = Config::get('exceptions', []);
    }

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public static function register(): void
    {
        $handler = self::getInstance();

        set_exception_handler([$handler, 'handleException']);
        set_error_handler([$handler, 'handleError']);
        register_shutdown_function([$handler, 'handleShutdown']);
    }

    public function handleException(Throwable $e): void
    {
        $this->report($e);

        // Check for custom exception handler
        $exceptionClass = get_class($e);
        foreach ($this->exceptionHandlers as $class => $handler) {
            if ($e instanceof $class) {
                $result = $handler($e);
                if ($result !== null) {
                    return;
                }
            }
        }

        // Check if this is a database exception we can handle with friendly message
        if ($this->handleDatabaseException($e)) {
            return;
        }

        $this->render($e);
    }

    /**
     * Handle database-specific exceptions with user-friendly messages
     */
    private function handleDatabaseException(Throwable $e): bool
    {
        $exceptionClass = get_class($e);
        $errorMessage = $this->getFullExceptionMessage($e);

        // Get configured messages for database exceptions
        $dbMessages = $this->exceptionMessages['database'] ?? [];
        $fieldMessages = $this->exceptionMessages['fields'] ?? [];

        $message = null;
        $field = null;
        $statusCode = 422; // Unprocessable Entity for validation-type errors

        // Check for Doctrine DBAL exceptions
        if (str_contains($exceptionClass, 'UniqueConstraintViolationException')) {
            $field = $this->extractFieldFromUniqueError($errorMessage);
            $message = $this->getFieldMessage($field, 'unique', $fieldMessages, $dbMessages);
        } elseif (str_contains($exceptionClass, 'NotNullConstraintViolationException')) {
            $field = $this->extractFieldFromNotNullError($errorMessage);
            $message = $this->getFieldMessage($field, 'not_null', $fieldMessages, $dbMessages);
        } elseif (str_contains($exceptionClass, 'ForeignKeyConstraintViolationException')) {
            $field = $this->extractFieldFromForeignKeyError($errorMessage);
            $message = $this->getFieldMessage($field, 'foreign_key', $fieldMessages, $dbMessages);
        } elseif (str_contains($exceptionClass, 'ConnectionException') || str_contains($exceptionClass, 'ConnectionFailed')) {
            $message = $dbMessages['connection'] ?? 'Unable to connect to the database. Please try again later.';
            $statusCode = 503; // Service Unavailable
        } elseif (str_contains($exceptionClass, 'TableNotFoundException')) {
            $message = $dbMessages['table_not_found'] ?? 'The requested resource is not available.';
            $statusCode = 500;
        } elseif (str_contains($exceptionClass, 'SyntaxErrorException')) {
            $message = $dbMessages['syntax_error'] ?? 'An error occurred while processing your request.';
            $statusCode = 500;
        } elseif (str_contains($exceptionClass, 'DeadlockException')) {
            $message = $dbMessages['deadlock'] ?? 'The server is busy. Please try again.';
            $statusCode = 503;
        } elseif (str_contains($exceptionClass, 'LockWaitTimeoutException')) {
            $message = $dbMessages['lock_timeout'] ?? 'The operation timed out. Please try again.';
            $statusCode = 503;
        } elseif (str_contains($exceptionClass, 'InvalidFieldNameException')) {
            $message = $dbMessages['invalid_field'] ?? 'An error occurred while processing your request.';
            $statusCode = 500;
        } elseif (str_contains($exceptionClass, 'NonUniqueFieldNameException')) {
            $message = $dbMessages['ambiguous_field'] ?? 'An error occurred while processing your request.';
            $statusCode = 500;
        } elseif ($this->isDataTypeMismatchError($errorMessage)) {
            $field = $this->extractFieldFromDataTypeError($errorMessage);
            $message = $this->getFieldMessage($field, 'data_type', $fieldMessages, $dbMessages);
        } elseif ($this->isDataTruncationError($errorMessage)) {
            $field = $this->extractFieldFromTruncationError($errorMessage);
            $message = $this->getFieldMessage($field, 'truncation', $fieldMessages, $dbMessages);
        } elseif ($this->isCheckConstraintError($exceptionClass, $errorMessage)) {
            $field = $this->extractFieldFromCheckConstraintError($errorMessage);
            $message = $this->getFieldMessage($field, 'check_constraint', $fieldMessages, $dbMessages);
        }

        if ($message !== null) {
            $this->renderDatabaseError($message, $statusCode, $e, $field);
            return true;
        }

        return false;
    }

    /**
     * Get error message for a specific field and error type
     * Priority: field-specific message > generic type message > default message
     */
    private function getFieldMessage(?string $field, string $type, array $fieldMessages, array $dbMessages): string
    {
        // 1. Check for field-specific message: fields.email.unique
        if ($field && isset($fieldMessages[$field][$type])) {
            return $fieldMessages[$field][$type];
        }

        // 2. Check for generic type message with :field placeholder
        $genericMessage = $dbMessages[$type] ?? null;
        if ($genericMessage) {
            // Replace :field placeholder with the actual field name
            if ($field) {
                $fieldLabel = $this->formatFieldLabel($field);
                return str_replace(':field', $fieldLabel, $genericMessage);
            }
            // Remove :field placeholder if no field detected
            return str_replace([':field ', ' :field', ':field'], ['', '', 'This value'], $genericMessage);
        }

        // 3. Default messages
        $defaults = [
            'unique' => $field
                ? "The {$this->formatFieldLabel($field)} has already been taken."
                : 'A record with this data already exists. Please check for duplicate values.',
            'not_null' => $field
                ? "The {$this->formatFieldLabel($field)} field is required."
                : 'A required field is missing. Please fill in all required fields.',
            'foreign_key' => $field
                ? "Invalid {$this->formatFieldLabel($field)}. The referenced record does not exist."
                : 'Invalid reference. The related record does not exist or cannot be deleted.',
            'data_type' => $field
                ? "The {$this->formatFieldLabel($field)} has an invalid format."
                : 'One or more fields have invalid data types.',
            'truncation' => $field
                ? "The {$this->formatFieldLabel($field)} value is too long."
                : 'One or more values exceed the maximum allowed length.',
            'check_constraint' => $field
                ? "The {$this->formatFieldLabel($field)} value does not meet the required constraints."
                : 'One or more values do not meet the required constraints.',
        ];

        return $defaults[$type] ?? 'A database error occurred.';
    }

    /**
     * Get full exception message including previous exceptions
     * Doctrine often wraps the original SQL error in nested exceptions
     */
    private function getFullExceptionMessage(Throwable $e): string
    {
        $messages = [$e->getMessage()];

        // Walk through the exception chain to get all messages
        $previous = $e->getPrevious();
        while ($previous !== null) {
            $messages[] = $previous->getMessage();
            $previous = $previous->getPrevious();
        }

        // Combine all messages to search through
        $fullMessage = implode(' ', $messages);

        if ($this->debug && $this->logPath) {
            $this->logDebug("Full exception message chain: " . $fullMessage);
        }

        return $fullMessage;
    }

    /**
     * Format field name to human-readable label
     * Examples: user_id -> User ID, firstName -> First Name, email -> Email
     */
    private function formatFieldLabel(string $field): string
    {
        // Convert snake_case to spaces
        $label = str_replace('_', ' ', $field);

        // Convert camelCase to spaces
        $label = preg_replace('/([a-z])([A-Z])/', '$1 $2', $label);

        // Capitalize first letter of each word
        return ucwords(strtolower($label));
    }

    /**
     * Extract field name from unique constraint violation error
     *
     * Supports multiple naming conventions:
     * 1. Named constraints: 'tablename_fieldname_unique' (recommended, industry standard)
     * 2. MySQL 8.x/MariaDB: 'tablename.fieldname'
     * 3. PostgreSQL: "tablename_fieldname_key" or Key (fieldname)=(value)
     * 4. SQLite: UNIQUE constraint failed: tablename.fieldname
     * 5. Fallback: Detect field from duplicate value format (email, phone, etc.)
     */
    private function extractFieldFromUniqueError(string $message): ?string
    {
        // Log the actual message for debugging
        if ($this->debug && $this->logPath) {
            $this->logDebug("Parsing unique error: " . $message);
        }

        // Pattern 1: Named constraint format (RECOMMENDED - industry standard)
        // Format: for key 'tablename_fieldname_unique' or 'tablename_fieldname_key'
        // Example: for key 'users_email_unique'
        // This is the format generated by ZephyrPHP's EntityGenerator
        if (preg_match("/for key '[a-z_]+_([a-zA-Z_]+)_(?:unique|key|idx)'/i", $message, $matches)) {
            $field = $matches[1];
            if ($this->debug && $this->logPath) {
                $this->logDebug("Pattern 1 (named constraint) matched: field={$field}");
            }
            return $field;
        }

        // Pattern 2: MySQL 8.x/MariaDB - "for key 'tablename.fieldname'"
        // Example: Duplicate entry 'value' for key 'users.email'
        if (preg_match("/for key '([^']+)\.([^']+)'/i", $message, $matches)) {
            $field = $matches[2];
            if ($this->debug && $this->logPath) {
                $this->logDebug("Pattern 2 (MySQL table.field) matched: field={$field}");
            }
            return $field;
        }

        // Pattern 3: PostgreSQL - unique constraint "tablename_fieldname_key"
        if (preg_match('/unique constraint "[a-z_]+_([a-zA-Z_]+)_(?:key|unique|idx)"/i', $message, $matches)) {
            $field = $matches[1];
            if ($this->debug && $this->logPath) {
                $this->logDebug("Pattern 3 (PostgreSQL constraint) matched: field={$field}");
            }
            return $field;
        }

        // Pattern 4: PostgreSQL - "Key (fieldname)=(value)"
        if (preg_match('/Key \(([a-zA-Z_]+)\)=/i', $message, $matches)) {
            if ($this->debug && $this->logPath) {
                $this->logDebug("Pattern 4 (PostgreSQL Key) matched: field={$matches[1]}");
            }
            return $matches[1];
        }

        // Pattern 5: SQLite - "UNIQUE constraint failed: tablename.fieldname"
        if (preg_match('/UNIQUE constraint failed: [a-zA-Z_]+\.([a-zA-Z_]+)/i', $message, $matches)) {
            if ($this->debug && $this->logPath) {
                $this->logDebug("Pattern 5 (SQLite) matched: field={$matches[1]}");
            }
            return $matches[1];
        }

        // Pattern 6: Fallback for Doctrine auto-generated names like 'UNIQ_1483A5E9E7927C74'
        // Try to detect field from the duplicate value format
        if (preg_match("/for key '(UNIQ|IDX)_[A-F0-9]+'/i", $message)) {
            if (preg_match("/Duplicate entry '([^']+)'/i", $message, $valueMatch)) {
                $duplicateValue = $valueMatch[1];

                // Email detection
                if (filter_var($duplicateValue, FILTER_VALIDATE_EMAIL)) {
                    if ($this->debug && $this->logPath) {
                        $this->logDebug("Pattern 6 (fallback) detected email: {$duplicateValue}");
                    }
                    return 'email';
                }

                // Phone detection
                if (preg_match('/^[\d\s\-\+\(\)]{7,20}$/', $duplicateValue)) {
                    if ($this->debug && $this->logPath) {
                        $this->logDebug("Pattern 6 (fallback) detected phone: {$duplicateValue}");
                    }
                    return 'phone';
                }
            }
        }

        // Pattern 7: Simple key name that might be the field name directly
        if (preg_match("/for key '([a-zA-Z_][a-zA-Z0-9_]*)'/i", $message, $matches)) {
            $key = $matches[1];
            // Skip PRIMARY and auto-generated names
            if (strtoupper($key) === 'PRIMARY' || preg_match('/^(UNIQ|IDX|FK)_[A-F0-9]+$/i', $key)) {
                if ($this->debug && $this->logPath) {
                    $this->logDebug("Pattern 7 skipped: {$key}");
                }
            } else {
                if ($this->debug && $this->logPath) {
                    $this->logDebug("Pattern 7 (simple key) matched: field={$key}");
                }
                return $key;
            }
        }

        if ($this->debug && $this->logPath) {
            $this->logDebug("No pattern matched for message");
        }

        return null;
    }

    /**
     * Log debug message
     */
    private function logDebug(string $message): void
    {
        if ($this->logPath === null) {
            return;
        }

        if (!is_dir($this->logPath)) {
            mkdir($this->logPath, 0755, true);
        }

        $logFile = $this->logPath . '/debug-' . date('Y-m-d') . '.log';
        $timestamp = date('Y-m-d H:i:s');
        file_put_contents($logFile, "[{$timestamp}] {$message}\n", FILE_APPEND | LOCK_EX);
    }

    /**
     * Extract field name from NOT NULL constraint violation error
     */
    private function extractFieldFromNotNullError(string $message): ?string
    {
        // MySQL: Column 'column_name' cannot be null
        if (preg_match("/Column '([a-zA-Z_]+)' cannot be null/i", $message, $matches)) {
            return $matches[1];
        }

        // MySQL: Field 'column_name' doesn't have a default value
        if (preg_match("/Field '([a-zA-Z_]+)' doesn't have a default value/i", $message, $matches)) {
            return $matches[1];
        }

        // PostgreSQL: null value in column "column_name" violates not-null constraint
        if (preg_match('/null value in column "([a-zA-Z_]+)"/i', $message, $matches)) {
            return $matches[1];
        }

        // SQLite: NOT NULL constraint failed: table.column
        if (preg_match('/NOT NULL constraint failed: [a-zA-Z_]+\.([a-zA-Z_]+)/i', $message, $matches)) {
            return $matches[1];
        }

        return null;
    }

    /**
     * Extract field name from foreign key constraint violation error
     *
     * Supports multiple naming conventions:
     * 1. Named FK constraints: 'tablename_fieldname_fk' (recommended, industry standard)
     * 2. MySQL standard: FOREIGN KEY (`column`)
     * 3. PostgreSQL: Key (column)=(value)
     */
    private function extractFieldFromForeignKeyError(string $message): ?string
    {
        // Log the actual message for debugging
        if ($this->debug && $this->logPath) {
            $this->logDebug("Parsing foreign key error: " . $message);
        }

        // Pattern 1: Named FK constraint format (RECOMMENDED - industry standard)
        // Format: CONSTRAINT `tablename_fieldname_fk` or 'tablename_fieldname_fk'
        // Example: CONSTRAINT `orders_user_id_fk` FOREIGN KEY
        if (preg_match("/CONSTRAINT [`'\"]?([a-z_]+)_([a-zA-Z_]+)_fk[`'\"]?/i", $message, $matches)) {
            $field = $matches[2];
            if ($this->debug && $this->logPath) {
                $this->logDebug("Pattern 1 (named FK constraint) matched: field={$field}");
            }
            return $field;
        }

        // Pattern 2: MySQL standard - FOREIGN KEY (`column`) REFERENCES
        // Example: FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
        if (preg_match('/FOREIGN KEY \(`([a-zA-Z_]+)`\)/i', $message, $matches)) {
            if ($this->debug && $this->logPath) {
                $this->logDebug("Pattern 2 (MySQL FK) matched: field={$matches[1]}");
            }
            return $matches[1];
        }

        // Pattern 3: PostgreSQL - Key (column)=(value) is not present
        // Example: Key (user_id)=(999) is not present in table "users"
        if (preg_match('/Key \(([a-zA-Z_]+)\)=/i', $message, $matches)) {
            if ($this->debug && $this->logPath) {
                $this->logDebug("Pattern 3 (PostgreSQL Key) matched: field={$matches[1]}");
            }
            return $matches[1];
        }

        // Pattern 4: PostgreSQL - foreign key constraint "constraint_name"
        if (preg_match('/foreign key constraint "[a-z_]+_([a-zA-Z_]+)_fk"/i', $message, $matches)) {
            if ($this->debug && $this->logPath) {
                $this->logDebug("Pattern 4 (PostgreSQL named FK) matched: field={$matches[1]}");
            }
            return $matches[1];
        }

        // SQLite: FOREIGN KEY constraint failed
        // SQLite doesn't provide column name in error, return null

        if ($this->debug && $this->logPath) {
            $this->logDebug("No FK pattern matched for message");
        }

        return null;
    }

    /**
     * Check if error is a data type mismatch
     */
    private function isDataTypeMismatchError(string $message): bool
    {
        $patterns = [
            '/Incorrect (integer|datetime|date|decimal|float|double) value/i',
            '/Data truncated for column/i',
            '/Out of range value/i',
            '/invalid input syntax for/i', // PostgreSQL
            '/cannot be cast to/i', // PostgreSQL
            '/datatype mismatch/i', // SQLite
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $message)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Extract field name from data type mismatch error
     */
    private function extractFieldFromDataTypeError(string $message): ?string
    {
        // MySQL: Incorrect integer value: 'abc' for column 'age'
        if (preg_match("/for column '([a-zA-Z_]+)'/i", $message, $matches)) {
            return $matches[1];
        }

        // MySQL: Data truncated for column 'status' at row 1
        if (preg_match("/for column '([a-zA-Z_]+)'/i", $message, $matches)) {
            return $matches[1];
        }

        // MySQL: Out of range value for column 'quantity'
        if (preg_match("/for column '([a-zA-Z_]+)'/i", $message, $matches)) {
            return $matches[1];
        }

        // PostgreSQL: invalid input syntax for type integer: "abc"
        // Note: PostgreSQL doesn't always include column name
        if (preg_match('/column "([a-zA-Z_]+)"/i', $message, $matches)) {
            return $matches[1];
        }

        return null;
    }

    /**
     * Check if error is a data truncation error
     */
    private function isDataTruncationError(string $message): bool
    {
        $patterns = [
            '/Data too long for column/i',
            '/String data, right truncated/i', // ODBC/PostgreSQL
            '/value too long for type/i', // PostgreSQL
            '/TEXT fields can not have a default value/i',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $message)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Extract field name from truncation error
     */
    private function extractFieldFromTruncationError(string $message): ?string
    {
        // MySQL: Data too long for column 'description' at row 1
        if (preg_match("/for column '([a-zA-Z_]+)'/i", $message, $matches)) {
            return $matches[1];
        }

        // PostgreSQL: value too long for type character varying(100)
        // Note: PostgreSQL doesn't include column name directly
        if (preg_match('/column "([a-zA-Z_]+)"/i', $message, $matches)) {
            return $matches[1];
        }

        return null;
    }

    /**
     * Check if error is a check constraint violation
     */
    private function isCheckConstraintError(string $exceptionClass, string $message): bool
    {
        if (str_contains($exceptionClass, 'CheckConstraintViolationException')) {
            return true;
        }

        $patterns = [
            '/Check constraint .* is violated/i', // MySQL 8.0.16+
            '/violates check constraint/i', // PostgreSQL
            '/CHECK constraint failed/i', // SQLite
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $message)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Extract field name from check constraint error
     *
     * Named check constraints should follow format: tablename_fieldname_check
     */
    private function extractFieldFromCheckConstraintError(string $message): ?string
    {
        // Pattern 1: Named check constraint format (RECOMMENDED)
        // Example: Check constraint 'orders_quantity_check' is violated
        if (preg_match("/[`'\"]?[a-z_]+_([a-zA-Z_]+)_check[`'\"]?/i", $message, $matches)) {
            return $matches[1];
        }

        // Pattern 2: PostgreSQL - violates check constraint "constraint_name"
        if (preg_match('/check constraint "([a-z_]+)_([a-zA-Z_]+)_check"/i', $message, $matches)) {
            return $matches[2];
        }

        // Pattern 3: SQLite - CHECK constraint failed: column_name
        if (preg_match('/CHECK constraint failed: ([a-zA-Z_]+)/i', $message, $matches)) {
            return $matches[1];
        }

        return null;
    }

    /**
     * Render database error as JSON or redirect with flash message
     */
    private function renderDatabaseError(string $message, int $statusCode, Throwable $e, ?string $field = null): void
    {
        // Log the actual error
        $this->logException($e);

        if ($this->isAjaxRequest() || $this->isApiRequest()) {
            if (!headers_sent()) {
                http_response_code($statusCode);
                header('Content-Type: application/json');
            }

            $response = [
                'error' => true,
                'message' => $message,
                'code' => $statusCode,
            ];

            // Include field-specific error for API consumers
            if ($field) {
                $response['field'] = $field;
                $response['errors'] = [$field => [$message]];
            }

            if ($this->debug) {
                $response['exception'] = get_class($e);
                $response['debug_message'] = $e->getMessage();
            }

            echo json_encode($response, JSON_PRETTY_PRINT);
            return;
        }

        // For web requests, try to redirect back with error
        if (!headers_sent() && isset($_SERVER['HTTP_REFERER'])) {
            // Store error using Flash class
            if ($field) {
                Flash::errors([$field => [$message]]);
            } else {
                Flash::error($message);
            }
            Flash::old($_POST ?? []);

            $referer = $_SERVER['HTTP_REFERER'] ?? '/';
            // Only allow same-origin redirects to prevent open redirect attacks
            if (!str_starts_with($referer, '/') || str_starts_with($referer, '//')) {
                $appUrl = rtrim($_ENV['APP_URL'] ?? '', '/');
                if ($appUrl && !str_starts_with($referer, $appUrl)) {
                    $referer = '/';
                }
            }

            http_response_code(303);
            header('Location: ' . $referer);
            return;
        }

        // Fall back to rendering error page
        if (!headers_sent()) {
            http_response_code($statusCode);
        }

        if ($this->debug) {
            $this->renderDebug($e, $statusCode);
        } else {
            $this->renderProduction($e, $statusCode);
        }
    }

    /**
     * Check if request is an API request
     */
    private function isApiRequest(): bool
    {
        $uri = $_SERVER['REQUEST_URI'] ?? '';
        return str_starts_with($uri, '/api/') || str_starts_with($uri, '/api');
    }

    /**
     * Register a custom exception handler
     *
     * @param string $exceptionClass The exception class to handle
     * @param callable $handler The handler function (receives exception, returns void or response)
     */
    public function registerHandler(string $exceptionClass, callable $handler): self
    {
        $this->exceptionHandlers[$exceptionClass] = $handler;
        return $this;
    }

    /**
     * Set custom messages for specific exceptions
     *
     * @param array $messages Array of exception type => message
     */
    public function setMessages(array $messages): self
    {
        $this->exceptionMessages = array_merge($this->exceptionMessages, $messages);
        return $this;
    }

    /**
     * Get the configured message for an exception type
     */
    public function getMessage(string $type, ?string $default = null): ?string
    {
        return $this->exceptionMessages[$type] ?? $default;
    }

    public function handleError(int $level, string $message, string $file = '', int $line = 0): bool
    {
        if (!(error_reporting() & $level)) {
            return false;
        }

        throw new \ErrorException($message, 0, $level, $file, $line);
    }

    public function handleShutdown(): void
    {
        $error = error_get_last();

        if ($error !== null && in_array($error['type'], [E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR, E_PARSE])) {
            $this->handleException(
                new \ErrorException($error['message'], 0, $error['type'], $error['file'], $error['line'])
            );
        }
    }

    public function report(Throwable $e): void
    {
        if ($this->shouldntReport($e)) {
            return;
        }

        $this->logException($e);
    }

    protected function shouldntReport(Throwable $e): bool
    {
        foreach ($this->dontReport as $type) {
            if ($e instanceof $type) {
                return true;
            }
        }
        return false;
    }

    protected function logException(Throwable $e): void
    {
        if ($this->logPath === null) {
            return;
        }

        if (!is_dir($this->logPath)) {
            mkdir($this->logPath, 0755, true);
        }

        $logFile = $this->logPath . '/error-' . date('Y-m-d') . '.log';
        $timestamp = date('Y-m-d H:i:s');

        $logMessage = sprintf(
            "[%s] %s: %s in %s:%d\nStack trace:\n%s\n\n",
            $timestamp,
            get_class($e),
            $e->getMessage(),
            $e->getFile(),
            $e->getLine(),
            $e->getTraceAsString()
        );

        file_put_contents($logFile, $logMessage, FILE_APPEND | LOCK_EX);
    }

    public function render(Throwable $e): void
    {
        // Prevent recursive rendering
        if ($this->rendering) {
            // Fallback to simple error output
            echo "Error: " . $e->getMessage();
            return;
        }

        $this->rendering = true;

        try {
            if ($this->customRenderer !== null) {
                call_user_func($this->customRenderer, $e);
                return;
            }

            $statusCode = $this->getStatusCode($e);

            if (!headers_sent()) {
                http_response_code($statusCode);
                header('Content-Type: text/html; charset=UTF-8');
            }

            if ($this->isAjaxRequest()) {
                $this->renderJson($e, $statusCode);
                return;
            }

            // For HttpException (abort), try user error template first even in debug mode
            if ($e instanceof HttpException) {
                $title = $this->getProductionTitle($statusCode);
                $message = $e->getMessage() ?: $this->getProductionMessage($statusCode);
                if ($this->renderErrorTemplate($statusCode, $title, $message)) {
                    return;
                }
            }

            if ($this->debug) {
                $this->renderDebug($e, $statusCode);
            } else {
                $this->renderProduction($e, $statusCode);
            }
        } finally {
            $this->rendering = false;
        }
    }

    protected function getStatusCode(Throwable $e): int
    {
        if ($e instanceof HttpException) {
            return $e->getStatusCode();
        }

        return 500;
    }

    protected function isAjaxRequest(): bool
    {
        // Traditional XHR check
        if (isset($_SERVER['HTTP_X_REQUESTED_WITH'])
            && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
            return true;
        }

        // Modern fetch() API — detect Accept: application/json header
        $accept = $_SERVER['HTTP_ACCEPT'] ?? '';
        if (str_contains($accept, 'application/json')) {
            return true;
        }

        // Check Content-Type for JSON requests (POST/PUT with JSON body)
        $contentType = $_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? '';
        if (str_contains($contentType, 'application/json')) {
            return true;
        }

        return false;
    }

    protected function renderJson(Throwable $e, int $statusCode): void
    {
        $response = [
            'error' => true,
            'message' => $this->debug ? $e->getMessage() : $this->getProductionMessage($statusCode),
            'code' => $statusCode
        ];

        if ($this->debug) {
            $response['exception'] = get_class($e);
            $response['file'] = $e->getFile();
            $response['line'] = $e->getLine();
            $response['trace'] = explode("\n", $e->getTraceAsString());
        }

        header('Content-Type: application/json');
        echo json_encode($response, JSON_PRETTY_PRINT);
    }

    protected function renderDebug(Throwable $e, int $statusCode): void
    {
        $title = get_class($e);
        $message = htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8');
        $file = htmlspecialchars($e->getFile(), ENT_QUOTES, 'UTF-8');
        $line = $e->getLine();
        $trace = htmlspecialchars($e->getTraceAsString(), ENT_QUOTES, 'UTF-8');
        $codeSnippet = $this->getCodeSnippet($e->getFile(), $e->getLine());

        echo <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{$statusCode} - {$title}</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: 'Segoe UI', system-ui, sans-serif; background: #1a1a1a; color: #e0e0e0; line-height: 1.5; }
        .container { max-width: 1200px; margin: 0 auto; padding: 24px; }
        .header { background: #dc2626; color: white; padding: 24px; margin-bottom: 24px; }
        .header h1 { font-size: 1.25rem; font-weight: 600; margin-bottom: 8px; }
        .header .message { font-size: 1.5rem; font-weight: 400; word-break: break-word; }
        .section { background: #2d2d2d; border-radius: 8px; margin-bottom: 16px; overflow: hidden; }
        .section-header { background: #3d3d3d; padding: 12px 16px; font-weight: 600; font-size: 0.9rem; color: #a0a0a0; }
        .section-content { padding: 16px; }
        .file-info { font-family: 'Consolas', monospace; font-size: 0.9rem; color: #60a5fa; }
        .code-snippet { background: #1e1e1e; border-radius: 4px; overflow-x: auto; margin-top: 12px; }
        .code-line { display: flex; font-family: 'Consolas', monospace; font-size: 0.85rem; }
        .code-line:hover { background: #333; }
        .code-line.highlight { background: #4a1d1d; }
        .line-number { width: 50px; padding: 4px 12px; text-align: right; color: #666; user-select: none; flex-shrink: 0; }
        .line-content { padding: 4px 12px; white-space: pre; flex: 1; }
        .trace { font-family: 'Consolas', monospace; font-size: 0.85rem; white-space: pre-wrap; word-break: break-all; color: #a0a0a0; }
        .meta { display: flex; gap: 24px; flex-wrap: wrap; }
        .meta-item { display: flex; flex-direction: column; gap: 4px; }
        .meta-label { font-size: 0.75rem; color: #666; text-transform: uppercase; }
        .meta-value { font-family: 'Consolas', monospace; font-size: 0.9rem; }
    </style>
</head>
<body>
    <div class="header">
        <div class="container">
            <h1>{$title}</h1>
            <div class="message">{$message}</div>
        </div>
    </div>
    <div class="container">
        <div class="section">
            <div class="section-header">Location</div>
            <div class="section-content">
                <div class="file-info">{$file}:{$line}</div>
                <div class="code-snippet">{$codeSnippet}</div>
            </div>
        </div>
        <div class="section">
            <div class="section-header">Stack Trace</div>
            <div class="section-content">
                <div class="trace">{$trace}</div>
            </div>
        </div>
        <div class="section">
            <div class="section-header">Environment</div>
            <div class="section-content">
                <div class="meta">
                    <div class="meta-item">
                        <span class="meta-label">PHP Version</span>
                        <span class="meta-value">{$this->getPhpVersion()}</span>
                    </div>
                    <div class="meta-item">
                        <span class="meta-label">ZephyrPHP</span>
                        <span class="meta-value">1.0.0</span>
                    </div>
                    <div class="meta-item">
                        <span class="meta-label">Environment</span>
                        <span class="meta-value">{$this->getEnvironment()}</span>
                    </div>
                </div>
            </div>
        </div>
    </div>
</body>
</html>
HTML;
    }

    protected function renderProduction(Throwable $e, int $statusCode): void
    {
        $title = $this->getProductionTitle($statusCode);
        $message = $this->getProductionMessage($statusCode);

        // Try user-defined error template first
        if ($this->renderErrorTemplate($statusCode, $title, $message)) {
            return;
        }

        // Fall back to built-in error page
        echo <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{$statusCode} - {$title}</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: 'Segoe UI', system-ui, sans-serif; background: #f5f5f5; color: #1a1a1a; min-height: 100vh; display: flex; align-items: center; justify-content: center; }
        .error-page { text-align: center; padding: 48px 24px; }
        .error-code { font-size: 6rem; font-weight: 700; color: #e0e0e0; line-height: 1; }
        .error-title { font-size: 1.5rem; font-weight: 600; margin: 16px 0 8px; }
        .error-message { color: #666; margin-bottom: 32px; }
        .error-link { display: inline-block; padding: 12px 24px; background: #0078d4; color: white; text-decoration: none; border-radius: 4px; font-weight: 600; }
        .error-link:hover { background: #106ebe; }
    </style>
</head>
<body>
    <div class="error-page">
        <div class="error-code">{$statusCode}</div>
        <h1 class="error-title">{$title}</h1>
        <p class="error-message">{$message}</p>
        <a href="/" class="error-link">Go Home</a>
    </div>
</body>
</html>
HTML;
    }

    /**
     * Try to render a user-defined error template (e.g., errors/404.twig)
     */
    protected function renderErrorTemplate(int $statusCode, string $title, string $message): bool
    {
        try {
            $viewsPath = $_ENV['VIEWS_PATH'] ?? 'pages';
            $basePath = defined('BASE_PATH') ? BASE_PATH : getcwd();
            $templateDir = $basePath . '/' . ltrim($viewsPath, '/');

            // Check for errors/{statusCode}.twig
            $templateFile = $templateDir . '/errors/' . $statusCode . '.twig';
            if (!file_exists($templateFile)) {
                return false;
            }

            // Use the View system if available, otherwise render directly with Twig
            if (function_exists('view')) {
                echo view('errors/' . $statusCode, [
                    'code' => $statusCode,
                    'title' => $title,
                    'message' => $message,
                ]);
                return true;
            }

            // Direct Twig rendering as fallback
            $loader = new \Twig\Loader\FilesystemLoader($templateDir);
            $twig = new \Twig\Environment($loader);
            echo $twig->render('errors/' . $statusCode . '.twig', [
                'code' => $statusCode,
                'title' => $title,
                'message' => $message,
            ]);
            return true;
        } catch (\Throwable $templateError) {
            // Template rendering failed, fall back to built-in page
            return false;
        }
    }

    protected function getCodeSnippet(string $file, int $line, int $padding = 8): string
    {
        if (!file_exists($file) || !is_readable($file)) {
            return '<div class="code-line"><span class="line-content">Unable to read source file</span></div>';
        }

        $lines = file($file);
        $start = max(0, $line - $padding - 1);
        $end = min(count($lines), $line + $padding);
        $output = '';

        for ($i = $start; $i < $end; $i++) {
            $lineNum = $i + 1;
            $isHighlight = $lineNum === $line;
            $highlightClass = $isHighlight ? ' highlight' : '';
            $content = htmlspecialchars($lines[$i] ?? '', ENT_QUOTES, 'UTF-8');
            $content = rtrim($content);

            $output .= "<div class=\"code-line{$highlightClass}\">";
            $output .= "<span class=\"line-number\">{$lineNum}</span>";
            $output .= "<span class=\"line-content\">{$content}</span>";
            $output .= "</div>";
        }

        return $output;
    }

    protected function getProductionTitle(int $statusCode): string
    {
        $titles = [
            400 => 'Bad Request',
            401 => 'Unauthorized',
            403 => 'Forbidden',
            404 => 'Page Not Found',
            405 => 'Method Not Allowed',
            419 => 'Page Expired',
            422 => 'Unprocessable Entity',
            429 => 'Too Many Requests',
            500 => 'Server Error',
            503 => 'Service Unavailable',
        ];

        return $titles[$statusCode] ?? 'Error';
    }

    protected function getProductionMessage(int $statusCode): string
    {
        $messages = [
            400 => 'The request could not be understood by the server.',
            401 => 'Authentication is required to access this resource.',
            403 => 'You do not have permission to access this resource.',
            404 => 'The page you are looking for could not be found.',
            405 => 'The request method is not supported for this resource.',
            419 => 'Your session has expired. Please refresh and try again.',
            422 => 'The submitted data could not be processed.',
            429 => 'You have made too many requests. Please try again later.',
            500 => 'Something went wrong on our end. Please try again later.',
            503 => 'The service is temporarily unavailable. Please try again later.',
        ];

        return $messages[$statusCode] ?? 'An unexpected error occurred.';
    }

    protected function getPhpVersion(): string
    {
        return PHP_VERSION;
    }

    protected function getEnvironment(): string
    {
        return $_ENV['ENV'] ?? 'dev';
    }

    public function setDebug(bool $debug): self
    {
        $this->debug = $debug;
        return $this;
    }

    public function setLogPath(string $path): self
    {
        $this->logPath = $path;
        return $this;
    }

    public function dontReport(array $exceptions): self
    {
        $this->dontReport = $exceptions;
        return $this;
    }

    public function setCustomRenderer(callable $renderer): self
    {
        $this->customRenderer = $renderer;
        return $this;
    }
}

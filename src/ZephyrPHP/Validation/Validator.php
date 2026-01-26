<?php

namespace ZephyrPHP\Validation;

class Validator
{
    private array $data = [];
    private array $rules = [];
    private array $errors = [];
    private array $customMessages = [];

    private const RULE_MESSAGES = [
        'required' => 'The :field field is required.',
        'email' => 'The :field must be a valid email address.',
        'min' => 'The :field must be at least :param.',
        'max' => 'The :field must not exceed :param.',
        'between' => 'The :field must be between :param.',
        'numeric' => 'The :field must be a number.',
        'integer' => 'The :field must be an integer.',
        'alpha' => 'The :field may only contain letters.',
        'alphanumeric' => 'The :field may only contain letters and numbers.',
        'alphanum' => 'The :field may only contain letters and numbers.',
        'url' => 'The :field must be a valid URL.',
        'phone' => 'The :field must be a valid phone number.',
        'date' => 'The :field must be a valid date.',
        'confirmed' => 'The :field confirmation does not match.',
        'in' => 'The selected :field is invalid.',
        'not_in' => 'The selected :field is invalid.',
        'regex' => 'The :field format is invalid.',
        'unique' => 'The :field has already been taken.',
        'exists' => 'The selected :field is invalid.',
        'file' => 'The :field must be a file.',
        'image' => 'The :field must be an image.',
        'mimes' => 'The :field must be a file of type: :param.',
        'size' => 'The :field must be :param kilobytes.',
        'min_size' => 'The :field must be at least :param kilobytes.',
        'max_size' => 'The :field must not exceed :param kilobytes.',
        'slug' => 'The :field must be a valid URL slug.',
        'ip' => 'The :field must be a valid IP address.',
        'json' => 'The :field must be valid JSON.',
        'positive' => 'The :field must be a positive number.',
        'negative' => 'The :field must be a negative number.',
        'past' => 'The :field must be a past date.',
        'future' => 'The :field must be a future date.',
        'after_today' => 'The :field must be today or later.',
    ];

    public function __construct(array $data = [], array $rules = [], array $messages = [])
    {
        $this->data = $data;
        $this->rules = $rules;
        $this->customMessages = $messages;
    }

    public static function make(array $data, array $rules, array $messages = []): self
    {
        return new self($data, $rules, $messages);
    }

    public function validate(): bool
    {
        $this->errors = [];

        foreach ($this->rules as $field => $fieldRules) {
            $rules = is_string($fieldRules) ? explode('|', $fieldRules) : $fieldRules;
            $value = $this->getValue($field);

            foreach ($rules as $rule) {
                $this->applyRule($field, $value, $rule);
            }
        }

        return empty($this->errors);
    }

    public function fails(): bool
    {
        return !$this->validate();
    }

    public function passes(): bool
    {
        return $this->validate();
    }

    private function applyRule(string $field, $value, string $rule): void
    {
        $params = [];
        if (str_contains($rule, ':')) {
            [$rule, $paramStr] = explode(':', $rule, 2);
            $params = explode(',', $paramStr);
        }

        $method = 'validate' . str_replace('_', '', ucwords($rule, '_'));

        if (method_exists($this, $method)) {
            if (!$this->$method($field, $value, $params)) {
                $this->addError($field, $rule, $params);
            }
        }
    }

    private function getValue(string $field)
    {
        $keys = explode('.', $field);
        $value = $this->data;

        foreach ($keys as $key) {
            if (is_array($value) && array_key_exists($key, $value)) {
                $value = $value[$key];
            } else {
                return null;
            }
        }

        return $value;
    }

    private function addError(string $field, string $rule, array $params = []): void
    {
        $messageKey = "$field.$rule";
        $message = $this->customMessages[$messageKey]
            ?? $this->customMessages[$field]
            ?? self::RULE_MESSAGES[$rule]
            ?? "The $field field is invalid.";

        $message = str_replace(':field', str_replace('_', ' ', $field), $message);
        $message = str_replace(':param', implode(', ', $params), $message);

        if (!isset($this->errors[$field])) {
            $this->errors[$field] = [];
        }
        $this->errors[$field][] = $message;
    }

    // Validation Rules

    private function validateRequired(string $field, $value, array $params): bool
    {
        if (is_null($value)) return false;
        if (is_string($value) && trim($value) === '') return false;
        if (is_array($value) && count($value) < 1) return false;
        return true;
    }

    private function validateEmail(string $field, $value, array $params): bool
    {
        if (empty($value)) return true;
        return filter_var($value, FILTER_VALIDATE_EMAIL) !== false;
    }

    private function validateMin(string $field, $value, array $params): bool
    {
        if ($value === null || $value === '') return true;
        $min = (float)($params[0] ?? 0);
        // If value is numeric (including numeric strings from forms), compare as numbers
        if (is_numeric($value)) {
            return (float)$value >= $min;
        }
        // Otherwise compare string length
        return mb_strlen((string)$value) >= $min;
    }

    private function validateMax(string $field, $value, array $params): bool
    {
        if ($value === null || $value === '') return true;
        $max = (float)($params[0] ?? PHP_INT_MAX);
        // If value is numeric (including numeric strings from forms), compare as numbers
        if (is_numeric($value)) {
            return (float)$value <= $max;
        }
        // Otherwise compare string length
        return mb_strlen((string)$value) <= $max;
    }

    private function validateBetween(string $field, $value, array $params): bool
    {
        if (empty($value)) return true;
        $min = (int)($params[0] ?? 0);
        $max = (int)($params[1] ?? PHP_INT_MAX);
        $length = is_string($value) ? mb_strlen($value) : $value;
        return $length >= $min && $length <= $max;
    }

    private function validateNumeric(string $field, $value, array $params): bool
    {
        if (empty($value)) return true;
        return is_numeric($value);
    }

    private function validateInteger(string $field, $value, array $params): bool
    {
        if (empty($value)) return true;
        return filter_var($value, FILTER_VALIDATE_INT) !== false;
    }

    private function validateAlpha(string $field, $value, array $params): bool
    {
        if (empty($value)) return true;
        return preg_match('/^[\pL\pM]+$/u', $value);
    }

    private function validateAlphanumeric(string $field, $value, array $params): bool
    {
        if (empty($value)) return true;
        return preg_match('/^[\pL\pM\pN]+$/u', $value);
    }

    private function validateUrl(string $field, $value, array $params): bool
    {
        if (empty($value)) return true;
        return filter_var($value, FILTER_VALIDATE_URL) !== false;
    }

    private function validateDate(string $field, $value, array $params): bool
    {
        if (empty($value)) return true;
        $format = $params[0] ?? 'Y-m-d';
        $d = \DateTime::createFromFormat($format, $value);
        return $d && $d->format($format) === $value;
    }

    private function validateConfirmed(string $field, $value, array $params): bool
    {
        $confirmField = $field . '_confirmation';
        return $value === $this->getValue($confirmField);
    }

    private function validateIn(string $field, $value, array $params): bool
    {
        if (empty($value)) return true;
        return in_array($value, $params);
    }

    private function validateNotIn(string $field, $value, array $params): bool
    {
        if (empty($value)) return true;
        return !in_array($value, $params);
    }

    private function validateRegex(string $field, $value, array $params): bool
    {
        if (empty($value)) return true;
        return preg_match($params[0] ?? '', $value);
    }

    private function validateNullable(string $field, $value, array $params): bool
    {
        return true; // Allows null values
    }

    private function validateSame(string $field, $value, array $params): bool
    {
        $otherField = $params[0] ?? '';
        return $value === $this->getValue($otherField);
    }

    private function validateDifferent(string $field, $value, array $params): bool
    {
        $otherField = $params[0] ?? '';
        return $value !== $this->getValue($otherField);
    }

    private function validatePhone(string $field, $value, array $params): bool
    {
        if ($value === null || $value === '') return true;
        // Allows: +, digits, spaces, dashes, parentheses, dots (7-20 chars)
        return (bool)preg_match('/^[+]?[0-9\s\-().]{7,20}$/', (string)$value);
    }

    private function validateAlphanum(string $field, $value, array $params): bool
    {
        if ($value === null || $value === '') return true;
        return (bool)preg_match('/^[\pL\pM\pN]+$/u', (string)$value);
    }

    private function validateSlug(string $field, $value, array $params): bool
    {
        if ($value === null || $value === '') return true;
        return (bool)preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', (string)$value);
    }

    private function validateIp(string $field, $value, array $params): bool
    {
        if ($value === null || $value === '') return true;
        return filter_var($value, FILTER_VALIDATE_IP) !== false;
    }

    private function validateJson(string $field, $value, array $params): bool
    {
        if ($value === null || $value === '') return true;
        json_decode((string)$value);
        return json_last_error() === JSON_ERROR_NONE;
    }

    private function validatePositive(string $field, $value, array $params): bool
    {
        if ($value === null || $value === '') return true;
        return is_numeric($value) && (float)$value > 0;
    }

    private function validateNegative(string $field, $value, array $params): bool
    {
        if ($value === null || $value === '') return true;
        return is_numeric($value) && (float)$value < 0;
    }

    private function validatePast(string $field, $value, array $params): bool
    {
        if ($value === null || $value === '') return true;
        $date = $value instanceof \DateTimeInterface ? $value : new \DateTime((string)$value);
        return $date < new \DateTime();
    }

    private function validateFuture(string $field, $value, array $params): bool
    {
        if ($value === null || $value === '') return true;
        $date = $value instanceof \DateTimeInterface ? $value : new \DateTime((string)$value);
        return $date > new \DateTime();
    }

    private function validateAfterToday(string $field, $value, array $params): bool
    {
        if ($value === null || $value === '') return true;
        $date = $value instanceof \DateTimeInterface ? $value : new \DateTime((string)$value);
        return $date >= new \DateTime('today');
    }

    // Getters

    public function errors(): array
    {
        return $this->errors;
    }

    public function getErrors(): array
    {
        return $this->errors;
    }

    public function firstError(?string $field = null): ?string
    {
        if ($field) {
            return $this->errors[$field][0] ?? null;
        }
        foreach ($this->errors as $fieldErrors) {
            return $fieldErrors[0] ?? null;
        }
        return null;
    }

    public function hasError(string $field): bool
    {
        return isset($this->errors[$field]);
    }

    public function validated(): array
    {
        $validated = [];
        foreach (array_keys($this->rules) as $field) {
            $value = $this->getValue($field);
            if ($value !== null) {
                $validated[$field] = $value;
            }
        }
        return $validated;
    }
}

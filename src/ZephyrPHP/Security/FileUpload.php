<?php

namespace ZephyrPHP\Security;

class FileUpload
{
    private array $allowedMimeTypes = [];
    private array $allowedExtensions = [];
    private int $maxFileSize = 5242880; // 5MB default
    private string $uploadDir = '';
    private array $errors = [];

    // Dangerous extensions that should never be allowed
    private const DANGEROUS_EXTENSIONS = [
        'php', 'php3', 'php4', 'php5', 'php7', 'php8', 'phtml', 'phar',
        'exe', 'sh', 'bat', 'cmd', 'com', 'scr', 'msi', 'vbs', 'js',
        'jar', 'cgi', 'pl', 'py', 'asp', 'aspx', 'jsp', 'htaccess'
    ];

    // Common safe MIME types
    public const IMAGE_MIMES = ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/svg+xml'];
    public const DOCUMENT_MIMES = ['application/pdf', 'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'];
    public const ARCHIVE_MIMES = ['application/zip', 'application/x-rar-compressed', 'application/x-7z-compressed'];

    public function __construct(string $uploadDir = '')
    {
        $this->uploadDir = $uploadDir ?: (defined('BASE_PATH') ? BASE_PATH . '/storage/uploads' : sys_get_temp_dir());
    }

    public function setAllowedMimeTypes(array $mimeTypes): self
    {
        $this->allowedMimeTypes = $mimeTypes;
        return $this;
    }

    public function setAllowedExtensions(array $extensions): self
    {
        // Filter out dangerous extensions
        $this->allowedExtensions = array_diff(
            array_map('strtolower', $extensions),
            self::DANGEROUS_EXTENSIONS
        );
        return $this;
    }

    public function setMaxFileSize(int $bytes): self
    {
        $this->maxFileSize = $bytes;
        return $this;
    }

    public function setUploadDir(string $dir): self
    {
        $this->uploadDir = $dir;
        return $this;
    }

    public function validate(array $file): bool
    {
        $this->errors = [];

        // Check for upload errors
        if (!isset($file['error']) || $file['error'] !== UPLOAD_ERR_OK) {
            $this->errors[] = $this->getUploadError($file['error'] ?? UPLOAD_ERR_NO_FILE);
            return false;
        }

        // Check if file was actually uploaded
        if (!is_uploaded_file($file['tmp_name'])) {
            $this->errors[] = 'File was not uploaded via HTTP POST';
            return false;
        }

        // Check file size
        if ($file['size'] > $this->maxFileSize) {
            $this->errors[] = 'File size exceeds maximum allowed size of ' . $this->formatBytes($this->maxFileSize);
            return false;
        }

        // Check extension
        $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (in_array($extension, self::DANGEROUS_EXTENSIONS)) {
            $this->errors[] = 'File type not allowed for security reasons';
            return false;
        }

        if (!empty($this->allowedExtensions) && !in_array($extension, $this->allowedExtensions)) {
            $this->errors[] = 'File extension not allowed. Allowed: ' . implode(', ', $this->allowedExtensions);
            return false;
        }

        // Check MIME type using finfo (more reliable than $_FILES['type'])
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mimeType = $finfo->file($file['tmp_name']);

        if (!empty($this->allowedMimeTypes) && !in_array($mimeType, $this->allowedMimeTypes)) {
            $this->errors[] = 'File MIME type not allowed: ' . $mimeType;
            return false;
        }

        // Additional check: verify image files are actually images
        if (str_starts_with($mimeType, 'image/') && $mimeType !== 'image/svg+xml') {
            if (@getimagesize($file['tmp_name']) === false) {
                $this->errors[] = 'File appears to be corrupted or not a valid image';
                return false;
            }
        }

        // Check for PHP code in file content (basic check)
        $content = file_get_contents($file['tmp_name'], false, null, 0, 1024);
        if (preg_match('/<\?php|<\?=|<script\s+language\s*=\s*["\']?php/i', $content)) {
            $this->errors[] = 'File contains potentially malicious content';
            return false;
        }

        return true;
    }

    public function upload(array $file, ?string $customName = null): ?string
    {
        if (!$this->validate($file)) {
            return null;
        }

        // Ensure upload directory exists
        if (!is_dir($this->uploadDir)) {
            if (!mkdir($this->uploadDir, 0755, true)) {
                $this->errors[] = 'Failed to create upload directory';
                return null;
            }
        }

        // Generate safe filename
        $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if ($customName) {
            $filename = Sanitizer::filename($customName) . '.' . $extension;
        } else {
            $filename = bin2hex(random_bytes(16)) . '.' . $extension;
        }

        $destination = $this->uploadDir . '/' . $filename;

        // Prevent overwriting
        $counter = 1;
        while (file_exists($destination)) {
            $filename = pathinfo($filename, PATHINFO_FILENAME) . '_' . $counter . '.' . $extension;
            $destination = $this->uploadDir . '/' . $filename;
            $counter++;
        }

        if (!move_uploaded_file($file['tmp_name'], $destination)) {
            $this->errors[] = 'Failed to move uploaded file';
            return null;
        }

        // Set safe permissions
        chmod($destination, 0644);

        return $filename;
    }

    public function getErrors(): array
    {
        return $this->errors;
    }

    public function getLastError(): ?string
    {
        return $this->errors[0] ?? null;
    }

    private function getUploadError(int $code): string
    {
        return match ($code) {
            UPLOAD_ERR_INI_SIZE => 'File exceeds upload_max_filesize directive',
            UPLOAD_ERR_FORM_SIZE => 'File exceeds MAX_FILE_SIZE directive',
            UPLOAD_ERR_PARTIAL => 'File was only partially uploaded',
            UPLOAD_ERR_NO_FILE => 'No file was uploaded',
            UPLOAD_ERR_NO_TMP_DIR => 'Missing temporary folder',
            UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk',
            UPLOAD_ERR_EXTENSION => 'Upload stopped by extension',
            default => 'Unknown upload error',
        };
    }

    private function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $i = 0;
        while ($bytes >= 1024 && $i < count($units) - 1) {
            $bytes /= 1024;
            $i++;
        }
        return round($bytes, 2) . ' ' . $units[$i];
    }

    // Convenience methods for common file types
    public static function forImages(): self
    {
        return (new self())
            ->setAllowedMimeTypes(self::IMAGE_MIMES)
            ->setAllowedExtensions(['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg']);
    }

    public static function forDocuments(): self
    {
        return (new self())
            ->setAllowedMimeTypes(self::DOCUMENT_MIMES)
            ->setAllowedExtensions(['pdf', 'doc', 'docx']);
    }
}

# ZephyrPHP Framework

*Light as a breeze. Fast as the wind.*

A lightweight, fast, and secure PHP framework for building modern web applications.

## Features

- **Lightweight** - Minimal footprint, only what you need
- **Fast** - Optimized for performance with template caching
- **Secure** - Built-in CSRF protection, input sanitization, security headers
- **MVC Architecture** - Clean separation of concerns
- **Twig Templating** - Powerful and secure template engine with auto-escaping
- **Dependency Injection** - Built-in PHP-DI container support
- **Routing** - Flexible routing with middleware support
- **Validation** - Fluent validation with custom rules
- **File Upload** - Secure file upload handling with MIME validation
- **CLI Tools** - Craftsman CLI for code generation

## Requirements

- PHP >= 8.2
- Composer

## Installation

```bash
composer require zephyrphp/framework
```

Or create a new project:

```bash
composer create-project zephyrphp/starter my-app
cd my-app
php craftsman serve
```

## Quick Start

### Define Routes

```php
// routes/web.php
use Zephyr\Router\Route;
use App\Controllers\HomeController;

Route::get('/', [new HomeController(), 'index']);
Route::get('/users/{id}', [new UserController(), 'show']);
Route::post('/users', [new UserController(), 'store']);

// Route groups with prefix
Route::group('/api', function() {
    Route::get('/users', [new ApiController(), 'users']);
});
```

### Create Controllers

```php
// app/Controllers/HomeController.php
namespace App\Controllers;

use Zephyr\Core\Controllers\Controller;

class HomeController extends Controller
{
    public function index()
    {
        return $this->render('home', [
            'title' => 'Welcome to ZephyrPHP'
        ]);
    }

    public function store()
    {
        // CSRF validation
        if (!$this->validateCSRF()) {
            return; // Returns 403 automatically
        }

        // Get sanitized input
        $email = $this->request->sanitized('email', 'email');
        $name = $this->request->sanitized('name', 'string');

        // Your logic here
        return $this->json(['success' => true]);
    }
}
```

### Validation

```php
use Zephyr\Validation\Validator;

$validator = Validator::make($request->all(), [
    'name' => 'required|min:3|max:255',
    'email' => 'required|email',
    'password' => 'required|min:8|confirmed',
    'age' => 'nullable|integer|between:18,120',
]);

if ($validator->fails()) {
    return $this->json(['errors' => $validator->errors()], 422);
}

$validated = $validator->validated();
```

### Secure File Upload

```php
use Zephyr\Security\FileUpload;

$uploader = FileUpload::forImages()
    ->setMaxFileSize(5 * 1024 * 1024) // 5MB
    ->setUploadDir(BASE_PATH . '/storage/uploads');

$file = $this->request->file('avatar');

if ($filename = $uploader->upload($file)) {
    // File uploaded successfully
} else {
    $error = $uploader->getLastError();
}
```

### CSRF Protection

```php
// In your Twig template
<form method="POST" action="/submit">
    {{ csrf_field() | raw }}
    <!-- or -->
    <input type="hidden" name="csrf_token" value="{{ csrf_token() }}">
</form>

// In controller
if (!$this->validateCSRF()) {
    return; // Automatically returns 403
}
```

### Input Sanitization

```php
use Zephyr\Security\Sanitizer;

$email = Sanitizer::email($input);
$slug = Sanitizer::slug($input);
$clean = Sanitizer::string($input);
$number = Sanitizer::int($input);

// Or via Request
$email = $this->request->sanitized('email', 'email');
```

## Security Features

### Built-in Security Headers

ZephyrPHP automatically applies security headers:

- `X-Content-Type-Options: nosniff`
- `X-Frame-Options: SAMEORIGIN`
- `X-XSS-Protection: 1; mode=block`
- `Referrer-Policy: strict-origin-when-cross-origin`
- `Permissions-Policy`
- `Content-Security-Policy` (customizable)

### Environment-based Configuration

```php
// Production mode disables:
// - Debug output
// - Error display
// - Twig debug extension

// And enables:
// - Template caching
// - Strict error logging
// - HSTS headers (with HTTPS)
```

## CLI Commands

```bash
# Start development server
php craftsman serve

# Start on custom host/port
php craftsman serve 0.0.0.0 3000

# Create a new controller
php craftsman make:controller UserController

# Create a new model
php craftsman make:model User

# Create a new middleware
php craftsman make:middleware AuthMiddleware

# Create a new migration
php craftsman make:migration create_users_table

# Run database migrations
php craftsman db:create

# List all routes
php craftsman route:list

# Clear cache
php craftsman cache:clear

# Generate application key
php craftsman key:generate
```

## Directory Structure

```
project/
├── app/
│   ├── Controllers/
│   ├── Models/
│   └── Views/
├── config/
├── pages/              # Twig templates
├── public/
│   ├── index.php       # Entry point
│   ├── .htaccess       # Security rules
│   └── assets/
│       └── .htaccess   # Blocks PHP execution
├── routes/
│   ├── web.php
│   └── api.php         # Optional
├── storage/
│   ├── compiled/       # Template cache
│   ├── logs/
│   ├── uploads/
│   └── .htaccess       # Blocks all access
├── .env
├── .env.example
├── composer.json
└── craftsman
```

## Configuration

Environment variables via `.env`:

```env
APP_NAME=ZephyrPHP
APP_DEBUG=false         # Disable in production!
ENV=production          # dev, staging, production
VIEWS_PATH=/pages
APP_KEY=                # Generate: php craftsman key:generate
```

## Documentation

Full documentation coming soon at [zephyrphp.com](https://zephyrphp.com)

## Contributing

Contributions are welcome! Please feel free to submit a Pull Request.

## License

The ZephyrPHP framework is open-sourced software licensed under the [MIT license](LICENSE).

## Author

**Techwonia**
- ZephyrPHP: [zephyrphp.com](https://zephyrphp.com)
- Company: [techwonia.com](https://techwonia.com)
- Email: opensource@techwonia.com
- GitHub: [@Techwonia](https://github.com/Techwonia)

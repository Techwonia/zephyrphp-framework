<?php

declare(strict_types=1);

namespace ZephyrPHP\Console\Commands;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'auth:setup',
    description: 'Configure authentication interactively'
)]
class AuthSetupCommand extends BaseCommand
{
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->initialize($input, $output);

        $this->title('Authentication Setup');

        // Auth type selection
        $authTypes = [
            'Session-based authentication (traditional)',
            'JWT Token authentication (API)',
            'Both (Session + JWT)',
        ];
        $authChoice = $this->choice('Select authentication type', $authTypes, $authTypes[0]);

        $useSession = str_contains($authChoice, 'Session');
        $useJwt = str_contains($authChoice, 'JWT') || str_contains($authChoice, 'Both');

        // User model
        $userModel = $this->ask('User model class', 'App\\Models\\User');

        // Session config
        if ($useSession) {
            $this->section('Session Configuration');
            $sessionDriver = $this->choice('Session driver', ['file', 'database', 'redis'], 'file');
            $sessionLifetime = $this->ask('Session lifetime (minutes)', '120');
        }

        // JWT config
        if ($useJwt) {
            $this->section('JWT Configuration');
            $jwtSecret = base64_encode(random_bytes(32));
            $this->line("Generated JWT secret: {$jwtSecret}");
            $jwtExpiry = $this->ask('JWT token expiry (minutes)', '60');
            $refreshExpiry = $this->ask('Refresh token expiry (days)', '30');
        }

        // Update .env file
        $config = [
            'AUTH_USER_MODEL' => $userModel,
        ];

        if ($useSession) {
            $config['SESSION_DRIVER'] = $sessionDriver ?? 'file';
            $config['SESSION_LIFETIME'] = $sessionLifetime ?? '120';
        }

        if ($useJwt) {
            $config['JWT_SECRET'] = $jwtSecret ?? base64_encode(random_bytes(32));
            $config['JWT_EXPIRY'] = $jwtExpiry ?? '60';
            $config['JWT_REFRESH_EXPIRY'] = $refreshExpiry ?? '30';
        }

        $this->updateEnvFile($config);

        $this->success('Authentication configuration saved to .env');

        // Create User model if needed
        if ($this->confirm('Create User model?', true)) {
            $this->createUserModel($useJwt);
        }

        // Create auth middleware
        if ($this->confirm('Create Auth middleware?', true)) {
            $this->createAuthMiddleware($useJwt);
        }

        $this->line('');
        $this->note('Next steps:');
        $this->line('  1. Run "php craftsman db:schema" to create the users table');
        $this->line('  2. Add authentication routes to routes/web.php');
        $this->line('  3. Use the Auth middleware to protect routes');

        return self::SUCCESS;
    }

    private function updateEnvFile(array $config): void
    {
        $envPath = $this->basePath('.env');

        if (!file_exists($envPath)) {
            file_put_contents($envPath, '');
        }

        $content = file_get_contents($envPath);

        foreach ($config as $key => $value) {
            if (preg_match("/^{$key}=/m", $content)) {
                $content = preg_replace("/^{$key}=.*/m", "{$key}={$value}", $content);
            } else {
                $content .= "\n{$key}={$value}";
            }
        }

        file_put_contents($envPath, $content);
    }

    private function createUserModel(bool $withJwt): void
    {
        $dir = $this->basePath('app/Models');
        $this->ensureDirectory($dir);

        $path = "{$dir}/User.php";

        if (file_exists($path)) {
            if (!$this->confirm('User model already exists. Overwrite?', false)) {
                return;
            }
        }

        $jwtInterface = $withJwt ? ' implements JWTSubject' : '';
        $jwtUse = $withJwt ? "\nuse ZephyrPHP\\Auth\\JWTSubject;" : '';
        $jwtMethods = $withJwt ? $this->getJwtMethods() : '';

        $content = <<<PHP
<?php

declare(strict_types=1);

namespace App\\Models;

use Doctrine\\ORM\\Mapping as ORM;{$jwtUse}

#[ORM\\Entity]
#[ORM\\Table(name: 'users')]
class User{$jwtInterface}
{
    #[ORM\\Id]
    #[ORM\\GeneratedValue]
    #[ORM\\Column(type: 'integer')]
    private ?int \$id = null;

    #[ORM\\Column(type: 'string', length: 255)]
    private string \$name;

    #[ORM\\Column(type: 'string', length: 255, unique: true)]
    private string \$email;

    #[ORM\\Column(type: 'string', length: 255)]
    private string \$password;

    #[ORM\\Column(type: 'datetime', nullable: true)]
    private ?\\DateTimeInterface \$emailVerifiedAt = null;

    #[ORM\\Column(type: 'string', length: 100, nullable: true)]
    private ?string \$rememberToken = null;

    #[ORM\\Column(type: 'datetime')]
    private \\DateTimeInterface \$createdAt;

    #[ORM\\Column(type: 'datetime')]
    private \\DateTimeInterface \$updatedAt;

    public function __construct()
    {
        \$this->createdAt = new \\DateTime();
        \$this->updatedAt = new \\DateTime();
    }

    public function getId(): ?int
    {
        return \$this->id;
    }

    public function getName(): string
    {
        return \$this->name;
    }

    public function setName(string \$name): self
    {
        \$this->name = \$name;
        return \$this;
    }

    public function getEmail(): string
    {
        return \$this->email;
    }

    public function setEmail(string \$email): self
    {
        \$this->email = \$email;
        return \$this;
    }

    public function getPassword(): string
    {
        return \$this->password;
    }

    public function setPassword(string \$password): self
    {
        \$this->password = password_hash(\$password, PASSWORD_DEFAULT);
        return \$this;
    }

    public function verifyPassword(string \$password): bool
    {
        return password_verify(\$password, \$this->password);
    }
{$jwtMethods}
}
PHP;

        file_put_contents($path, $content);
        $this->success('User model created: app/Models/User.php');
    }

    private function getJwtMethods(): string
    {
        return <<<'PHP'

    public function getJWTIdentifier(): mixed
    {
        return $this->id;
    }

    public function getJWTCustomClaims(): array
    {
        return [
            'email' => $this->email,
            'name' => $this->name,
        ];
    }
PHP;
    }

    private function createAuthMiddleware(bool $withJwt): void
    {
        $dir = $this->basePath('app/Middleware');
        $this->ensureDirectory($dir);

        $path = "{$dir}/AuthMiddleware.php";

        if (file_exists($path)) {
            if (!$this->confirm('Auth middleware already exists. Overwrite?', false)) {
                return;
            }
        }

        $jwtCheck = $withJwt ? $this->getJwtCheck() : '';

        $content = <<<PHP
<?php

declare(strict_types=1);

namespace App\\Middleware;

use Psr\\Http\\Message\\ResponseInterface;
use Psr\\Http\\Message\\ServerRequestInterface;
use Psr\\Http\\Server\\MiddlewareInterface;
use Psr\\Http\\Server\\RequestHandlerInterface;

class AuthMiddleware implements MiddlewareInterface
{
    public function process(ServerRequestInterface \$request, RequestHandlerInterface \$handler): ResponseInterface
    {
        // Check session authentication
        if (isset(\$_SESSION['user_id'])) {
            return \$handler->handle(\$request);
        }
{$jwtCheck}
        // Not authenticated - redirect to login
        return new \\ZephyrPHP\\Http\\Response\\RedirectResponse('/login');
    }
}
PHP;

        file_put_contents($path, $content);
        $this->success('Auth middleware created: app/Middleware/AuthMiddleware.php');
    }

    private function getJwtCheck(): string
    {
        return <<<'PHP'

        // Check JWT token
        $authHeader = $request->getHeaderLine('Authorization');
        if (str_starts_with($authHeader, 'Bearer ')) {
            $token = substr($authHeader, 7);
            // Validate JWT token here
            // If valid, continue with the request
        }
PHP;
    }
}

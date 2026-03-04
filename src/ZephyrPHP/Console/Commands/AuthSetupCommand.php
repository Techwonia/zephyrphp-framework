<?php

declare(strict_types=1);

namespace ZephyrPHP\Console\Commands;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'auth:setup',
    description: 'Scaffold a complete authentication system with login, register, dashboard, and settings'
)]
class AuthSetupCommand extends BaseCommand
{
    private string $namespace;
    private bool $hasAuthorization = false;
    private bool $useSession = true;
    private bool $useJwt = false;

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->initialize($input, $output);

        $this->title('Authentication System Setup');

        // Step 1: Verify prerequisites
        if (!$this->verifyPrerequisites()) {
            return self::FAILURE;
        }

        // Step 2: Detect environment
        $this->namespace = $this->getAppNamespace();
        $this->hasAuthorization = $this->isModuleEnabled('authorization');

        $this->line("  App namespace: <info>{$this->namespace}</info>");
        $this->line("  Authorization module: <info>" . ($this->hasAuthorization ? 'Enabled (roles support)' : 'Not installed') . "</info>");
        $this->line('');

        // Step 3: Auth type config
        $this->configureAuthType();

        // Step 4: Generate everything
        $this->section('Generating Files');

        $this->generateAuthConfig();
        $this->generateUserModel();
        if ($this->hasAuthorization) {
            $this->generateRoleModel();
            $this->generateSettingModel();
        }
        $this->generateLoginController();
        $this->generateRegisterController();
        $this->generateDashboardController();
        $this->generateSettingsController();
        if ($this->hasAuthorization) {
            $this->generateAdminController();
            $this->generateUserController();
            $this->generateRoleController();
        }
        $this->generateLoginView();
        $this->generateRegisterView();
        $this->generateDashboardLayout();
        $this->generateDashboardView();
        $this->generateSettingsView();
        if ($this->hasAuthorization) {
            $this->generateAdminSettingsView();
            $this->generateUserViews();
            $this->generateRoleViews();
        }
        $this->generateDashboardCss();
        $this->generateMigrations();
        $this->addAuthRoutes();

        // Done
        $this->line('');
        $this->success('Authentication system scaffolded successfully!');
        $this->line('');
        $this->note('Next steps:');
        $this->line('  1. Run <info>php craftsman db:schema</info> to create the database tables');
        $this->line('  2. Run <info>php craftsman serve</info> to start the development server');
        $this->line('  3. Visit <info>/register</info> to create your first account');
        if ($this->hasAuthorization) {
            $this->line('     <comment>The first registered user automatically gets the admin role.</comment>');
        }
        $this->line('  4. Visit <info>/v1/dashboard</info> to see the dashboard');
        $this->line('');

        return self::SUCCESS;
    }

    // =========================================================================
    // Prerequisites & Configuration
    // =========================================================================

    private function verifyPrerequisites(): bool
    {
        if (!$this->isModuleEnabled('database')) {
            $this->error('Database module is required but not enabled.');
            $this->line('  Run: php craftsman add database');
            return false;
        }

        if (!$this->isModuleEnabled('auth')) {
            $this->error('Auth module is required but not enabled.');
            $this->line('  Run: php craftsman add auth');
            return false;
        }

        return true;
    }

    private function isModuleEnabled(string $name): bool
    {
        $configPath = $this->basePath('config/modules.php');
        if (!file_exists($configPath)) {
            return false;
        }

        $modules = require $configPath;

        if (isset($modules[$name])) {
            return is_array($modules[$name]) ? ($modules[$name]['enabled'] ?? true) : (bool) $modules[$name];
        }

        return false;
    }

    private function configureAuthType(): void
    {
        $this->section('Authentication Type');

        $authTypes = [
            'Session-based authentication (traditional)',
            'JWT Token authentication (API)',
            'Both (Session + JWT)',
        ];
        $authChoice = $this->choice('Select authentication type', $authTypes, $authTypes[0]);

        $this->useSession = str_contains($authChoice, 'Session') || str_contains($authChoice, 'Both');
        $this->useJwt = str_contains($authChoice, 'JWT') || str_contains($authChoice, 'Both');

        // Build .env config
        $envConfig = ['AUTH_HOME' => '/v1/dashboard'];

        if ($this->useSession) {
            $envConfig['SESSION_DRIVER'] = 'file';
            $envConfig['SESSION_LIFETIME'] = '120';
        }

        if ($this->useJwt) {
            $envConfig['JWT_SECRET'] = base64_encode(random_bytes(32));
            $envConfig['JWT_EXPIRY'] = '60';
            $envConfig['JWT_REFRESH_EXPIRY'] = '30';
            $this->line("  Generated JWT secret.");
        }

        if (!empty($envConfig)) {
            $this->updateEnvFile($envConfig);
        }
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

    // =========================================================================
    // File Writing Helper
    // =========================================================================

    private function writeFile(string $relativePath, string $content, string $label = ''): bool
    {
        $fullPath = $this->basePath($relativePath);
        $dir = dirname($fullPath);
        $this->ensureDirectory($dir);

        if (file_exists($fullPath)) {
            if (!$this->confirm("  {$relativePath} already exists. Overwrite?", false)) {
                return false;
            }
        }

        file_put_contents($fullPath, $content);
        $displayLabel = $label ?: $relativePath;
        $this->line("  <info>Created:</info> {$displayLabel}");
        return true;
    }

    // =========================================================================
    // Auth Config Generation
    // =========================================================================

    private function generateAuthConfig(): void
    {
        $ns = $this->namespace;

        $content = <<<PHP
<?php

return [
    'providers' => [
        'users' => [
            'model' => {$ns}\\Models\\User::class,
        ],
    ],
];
PHP;

        $this->writeFile('config/auth.php', $content);
    }

    // =========================================================================
    // Model Generation
    // =========================================================================

    private function generateUserModel(): void
    {
        $ns = $this->namespace;
        $hasRolesUse = '';
        $hasRolesTrait = '';
        $rolesProperty = '';
        $rolesInit = '';
        $rolesImport = '';

        if ($this->hasAuthorization) {
            $hasRolesUse = "\nuse ZephyrPHP\\Authorization\\Traits\\HasRoles;";
            $hasRolesTrait = "\n    use HasRoles;";
            $rolesImport = "\nuse Doctrine\\Common\\Collections\\ArrayCollection;\nuse Doctrine\\Common\\Collections\\Collection;";
            $rolesProperty = <<<'PHP'

    #[ORM\ManyToMany(targetEntity: Role::class, inversedBy: 'users')]
    #[ORM\JoinTable(name: 'role_user')]
    private Collection $roles;
PHP;
            $rolesInit = "\n        \$this->initializeRoles();";
        }

        $content = <<<PHP
<?php

declare(strict_types=1);

namespace {$ns}\\Models;

use Doctrine\\ORM\\Mapping as ORM;
use ZephyrPHP\\Database\\Model;
use ZephyrPHP\\Auth\\AuthenticatableInterface;
use ZephyrPHP\\Auth\\Authenticatable;{$hasRolesUse}{$rolesImport}

#[ORM\\Entity]
#[ORM\\Table(name: 'users')]
#[ORM\\HasLifecycleCallbacks]
class User extends Model implements AuthenticatableInterface
{
    use Authenticatable;{$hasRolesTrait}

    #[ORM\\Column(type: 'string', length: 255)]
    protected string \$name = '';

    #[ORM\\Column(type: 'string', length: 180, unique: true)]
    protected string \$email = '';

    #[ORM\\Column(type: 'string', length: 255)]
    protected string \$password = '';

    #[ORM\\Column(type: 'string', length: 100, nullable: true)]
    protected ?string \$rememberToken = null;
{$rolesProperty}
    public function __construct()
    {
        \$this->createdAt = new \\DateTime();
        \$this->updatedAt = new \\DateTime();{$rolesInit}
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

    public function getAuthPassword(): string
    {
        return \$this->password;
    }

    public function setPassword(string \$password): self
    {
        \$this->password = \$password;
        return \$this;
    }

    public function getRememberToken(): ?string
    {
        return \$this->rememberToken;
    }

    public function setRememberToken(string \$token): void
    {
        \$this->rememberToken = \$token;
    }

    public function getRememberTokenName(): string
    {
        return 'rememberToken';
    }
}
PHP;

        $this->writeFile('app/Models/User.php', $content);
    }

    private function generateRoleModel(): void
    {
        $ns = $this->namespace;

        $content = <<<PHP
<?php

declare(strict_types=1);

namespace {$ns}\\Models;

use Doctrine\\ORM\\Mapping as ORM;
use Doctrine\\Common\\Collections\\ArrayCollection;
use Doctrine\\Common\\Collections\\Collection;
use ZephyrPHP\\Database\\Model;

#[ORM\\Entity]
#[ORM\\Table(name: 'roles')]
#[ORM\\HasLifecycleCallbacks]
class Role extends Model
{
    #[ORM\\Column(type: 'string', length: 100, unique: true)]
    protected string \$name = '';

    #[ORM\\Column(type: 'string', length: 100, unique: true)]
    protected string \$slug = '';

    #[ORM\\Column(type: 'text', nullable: true)]
    protected ?string \$description = null;

    #[ORM\\ManyToMany(targetEntity: User::class, mappedBy: 'roles')]
    private Collection \$users;

    public function __construct()
    {
        \$this->createdAt = new \\DateTime();
        \$this->updatedAt = new \\DateTime();
        \$this->users = new ArrayCollection();
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

    public function getSlug(): string
    {
        return \$this->slug;
    }

    public function setSlug(string \$slug): self
    {
        \$this->slug = \$slug;
        return \$this;
    }

    public function getDescription(): ?string
    {
        return \$this->description;
    }

    public function setDescription(?string \$description): self
    {
        \$this->description = \$description;
        return \$this;
    }

    public function getUsers(): Collection
    {
        return \$this->users;
    }
}
PHP;

        $this->writeFile('app/Models/Role.php', $content);
    }

    private function generateSettingModel(): void
    {
        $ns = $this->namespace;

        $content = <<<PHP
<?php

declare(strict_types=1);

namespace {$ns}\\Models;

use Doctrine\\ORM\\Mapping as ORM;
use ZephyrPHP\\Database\\Model;

#[ORM\\Entity]
#[ORM\\Table(name: 'app_settings')]
#[ORM\\HasLifecycleCallbacks]
class Setting extends Model
{
    #[ORM\\Column(name: 'setting_key', type: 'string', length: 100, unique: true)]
    protected string \$settingKey = '';

    #[ORM\\Column(name: 'setting_value', type: 'text', nullable: true)]
    protected ?string \$settingValue = null;

    public function __construct()
    {
        \$this->createdAt = new \\DateTime();
        \$this->updatedAt = new \\DateTime();
    }

    public function getSettingKey(): string
    {
        return \$this->settingKey;
    }

    public function setSettingKey(string \$settingKey): self
    {
        \$this->settingKey = \$settingKey;
        return \$this;
    }

    public function getSettingValue(): ?string
    {
        return \$this->settingValue;
    }

    public function setSettingValue(?string \$settingValue): self
    {
        \$this->settingValue = \$settingValue;
        return \$this;
    }

    public static function get(string \$key, mixed \$default = null): mixed
    {
        \$setting = self::findOneBy(['settingKey' => \$key]);
        return \$setting ? \$setting->getSettingValue() : \$default;
    }

    public static function set(string \$key, mixed \$value): void
    {
        \$setting = self::findOneBy(['settingKey' => \$key]);
        if (!\$setting) {
            \$setting = new self();
            \$setting->setSettingKey(\$key);
        }
        \$setting->setSettingValue((string) \$value);
        \$setting->save();
    }
}
PHP;

        $this->writeFile('app/Models/Setting.php', $content);
    }

    // =========================================================================
    // Controller Generation
    // =========================================================================

    private function generateLoginController(): void
    {
        $ns = $this->namespace;

        $content = <<<PHP
<?php

declare(strict_types=1);

namespace {$ns}\\Controllers\\Auth;

use ZephyrPHP\\Core\\Controllers\\Controller;
use ZephyrPHP\\Auth\\Auth;

class LoginController extends Controller
{
    public function showLoginForm(): string
    {
        return \$this->render('auth/login');
    }

    public function login(): void
    {
        \$email = \$this->input('email', '');
        \$password = \$this->input('password', '');
        \$remember = \$this->boolean('remember');

        \$errors = [];

        if (empty(\$email)) {
            \$errors['email'] = 'Email is required.';
        }
        if (empty(\$password)) {
            \$errors['password'] = 'Password is required.';
        }

        if (!empty(\$errors)) {
            \$this->flash('errors', \$errors);
            \$this->flash('_old_input', ['email' => \$email]);
            \$this->back();
            return;
        }

        if (Auth::attempt(['email' => \$email, 'password' => \$password], \$remember)) {
            \$intended = \$this->session->get('url_intended', '/v1/dashboard');
            \$this->session->remove('url_intended');
            \$this->redirect(\$intended);
            return;
        }

        \$this->flash('errors', ['email' => 'These credentials do not match our records.']);
        \$this->flash('_old_input', ['email' => \$email]);
        \$this->back();
    }

    public function logout(): void
    {
        Auth::logout();
        \$this->redirect('/login');
    }
}
PHP;

        $this->writeFile('app/Controllers/Auth/LoginController.php', $content);
    }

    private function generateRegisterController(): void
    {
        $ns = $this->namespace;

        $settingImport = '';
        $roleImport = '';
        $registrationCheck = '';
        $firstUserAdmin = '';

        if ($this->hasAuthorization) {
            $settingImport = "\nuse {$ns}\\Models\\Setting;";
            $roleImport = "\nuse {$ns}\\Models\\Role;";

            $registrationCheck = <<<'PHP'

        // Check if public registration is enabled
        if (Setting::get('registration_enabled', 'true') !== 'true') {
            $this->flash('errors', ['registration' => 'Public registration is currently disabled.']);
            $this->redirect('/login');
            return;
        }
PHP;

            $firstUserAdmin = <<<'PHP'

        // Auto-assign admin role to the first registered user
        if (User::count() === 1) {
            $adminRole = Role::findOneBy(['slug' => 'admin']);
            if (!$adminRole) {
                $adminRole = new Role();
                $adminRole->setName('Admin');
                $adminRole->setSlug('admin');
                $adminRole->setDescription('Full system administrator');
                $adminRole->save();
            }
            $user->assignRole($adminRole);
            $user->save();
        } else {
            // Assign the default registration role configured by admin
            $defaultRoleId = Setting::get('default_registration_role', '');
            if ($defaultRoleId !== '') {
                $defaultRole = Role::find((int) $defaultRoleId);
                if ($defaultRole) {
                    $user->assignRole($defaultRole);
                    $user->save();
                }
            }
        }
PHP;
        }

        // showRegisterForm needs a slightly different check (returns string)
        $showFormCheck = '';
        if ($this->hasAuthorization) {
            $showFormCheck = <<<'PHP'

        // Check if public registration is enabled
        if (Setting::get('registration_enabled', 'true') !== 'true') {
            $this->flash('errors', ['registration' => 'Public registration is currently disabled.']);
            $this->redirect('/login');
            return '';
        }
PHP;
        }

        $content = <<<PHP
<?php

declare(strict_types=1);

namespace {$ns}\\Controllers\\Auth;

use ZephyrPHP\\Core\\Controllers\\Controller;
use ZephyrPHP\\Auth\\Auth;
use ZephyrPHP\\Security\\Hash;
use {$ns}\\Models\\User;{$settingImport}{$roleImport}

class RegisterController extends Controller
{
    public function showRegisterForm(): string
    {{$showFormCheck}
        return \$this->render('auth/register');
    }

    public function store(): void
    {{$registrationCheck}
        \$name = \$this->input('name', '');
        \$email = \$this->input('email', '');
        \$password = \$this->input('password', '');
        \$passwordConfirmation = \$this->input('password_confirmation', '');

        \$errors = [];

        if (empty(\$name)) {
            \$errors['name'] = 'Name is required.';
        }
        if (empty(\$email) || !filter_var(\$email, FILTER_VALIDATE_EMAIL)) {
            \$errors['email'] = 'A valid email is required.';
        }
        if (strlen(\$password) < 8) {
            \$errors['password'] = 'Password must be at least 8 characters.';
        }
        if (\$password !== \$passwordConfirmation) {
            \$errors['password_confirmation'] = 'Passwords do not match.';
        }

        // Check if email already exists
        if (empty(\$errors['email'])) {
            \$existing = User::findOneBy(['email' => \$email]);
            if (\$existing) {
                \$errors['email'] = 'This email is already registered.';
            }
        }

        if (!empty(\$errors)) {
            \$this->flash('errors', \$errors);
            \$this->flash('_old_input', ['name' => \$name, 'email' => \$email]);
            \$this->back();
            return;
        }

        \$user = new User();
        \$user->setName(\$name);
        \$user->setEmail(\$email);
        \$user->setPassword(Hash::make(\$password));
        \$user->save();
{$firstUserAdmin}
        Auth::login(\$user);

        \$this->redirect('/v1/dashboard');
    }
}
PHP;

        $this->writeFile('app/Controllers/Auth/RegisterController.php', $content);
    }

    private function generateDashboardController(): void
    {
        $ns = $this->namespace;

        $content = <<<PHP
<?php

declare(strict_types=1);

namespace {$ns}\\Controllers\\Dashboard;

use ZephyrPHP\\Core\\Controllers\\Controller;
use ZephyrPHP\\Auth\\Auth;

class DashboardController extends Controller
{
    public function index(): string
    {
        return \$this->render('dashboard/index', [
            'user' => Auth::user(),
        ]);
    }
}
PHP;

        $this->writeFile('app/Controllers/Dashboard/DashboardController.php', $content);
    }

    private function generateSettingsController(): void
    {
        $ns = $this->namespace;

        $content = <<<PHP
<?php

declare(strict_types=1);

namespace {$ns}\\Controllers\\Dashboard;

use ZephyrPHP\\Core\\Controllers\\Controller;
use ZephyrPHP\\Auth\\Auth;
use ZephyrPHP\\Security\\Hash;

class SettingsController extends Controller
{
    public function index(): string
    {
        return \$this->render('dashboard/settings', [
            'user' => Auth::user(),
        ]);
    }

    public function updateProfile(): void
    {
        \$user = Auth::user();
        \$name = \$this->input('name', '');
        \$email = \$this->input('email', '');

        \$errors = [];

        if (empty(\$name)) {
            \$errors['name'] = 'Name is required.';
        }
        if (empty(\$email) || !filter_var(\$email, FILTER_VALIDATE_EMAIL)) {
            \$errors['email'] = 'A valid email is required.';
        }

        if (!empty(\$errors)) {
            \$this->flash('errors', \$errors);
            \$this->back();
            return;
        }

        \$user->setName(\$name);
        \$user->setEmail(\$email);
        \$user->save();

        \$this->flash('success', 'Profile updated successfully.');
        \$this->back();
    }

    public function updatePassword(): void
    {
        \$user = Auth::user();
        \$currentPassword = \$this->input('current_password', '');
        \$newPassword = \$this->input('new_password', '');
        \$confirmPassword = \$this->input('new_password_confirmation', '');

        \$errors = [];

        if (!Hash::check(\$currentPassword, \$user->getAuthPassword())) {
            \$errors['current_password'] = 'Current password is incorrect.';
        }
        if (strlen(\$newPassword) < 8) {
            \$errors['new_password'] = 'New password must be at least 8 characters.';
        }
        if (\$newPassword !== \$confirmPassword) {
            \$errors['new_password_confirmation'] = 'Passwords do not match.';
        }

        if (!empty(\$errors)) {
            \$this->flash('errors', \$errors);
            \$this->back();
            return;
        }

        \$user->setPassword(Hash::make(\$newPassword));
        \$user->save();

        \$this->flash('success', 'Password changed successfully.');
        \$this->back();
    }
}
PHP;

        $this->writeFile('app/Controllers/Dashboard/SettingsController.php', $content);
    }

    private function generateAdminController(): void
    {
        $ns = $this->namespace;

        $content = <<<PHP
<?php

declare(strict_types=1);

namespace {$ns}\\Controllers\\Dashboard;

use ZephyrPHP\\Core\\Controllers\\Controller;
use ZephyrPHP\\Auth\\Auth;
use {$ns}\\Models\\Setting;
use {$ns}\\Models\\Role;

class AdminController extends Controller
{
    private function requireAdmin(): void
    {
        if (!Auth::check() || !Auth::user()->hasRole('admin')) {
            \$this->flash('errors', ['auth' => 'Access denied. Admin role required.']);
            \$this->redirect('/v1/dashboard');
        }
    }

    public function settings(): string
    {
        \$this->requireAdmin();

        \$roles = Role::findAll();
        \$defaultRoleId = Setting::get('default_registration_role', '');

        return \$this->render('dashboard/admin/settings', [
            'user' => Auth::user(),
            'registration_enabled' => Setting::get('registration_enabled', 'true') === 'true',
            'roles' => \$roles,
            'default_role_id' => \$defaultRoleId,
        ]);
    }

    public function updateSettings(): void
    {
        \$this->requireAdmin();

        \$registrationEnabled = \$this->boolean('registration_enabled');
        Setting::set('registration_enabled', \$registrationEnabled ? 'true' : 'false');

        \$defaultRoleId = \$this->input('default_registration_role', '');
        Setting::set('default_registration_role', \$defaultRoleId);

        \$this->flash('success', 'Admin settings updated successfully.');
        \$this->back();
    }
}
PHP;

        $this->writeFile('app/Controllers/Dashboard/AdminController.php', $content);
    }

    private function generateUserController(): void
    {
        $ns = $this->namespace;

        $content = <<<PHP
<?php

declare(strict_types=1);

namespace {$ns}\\Controllers\\Dashboard;

use ZephyrPHP\\Core\\Controllers\\Controller;
use ZephyrPHP\\Auth\\Auth;
use ZephyrPHP\\Security\\Hash;
use {$ns}\\Models\\User;
use {$ns}\\Models\\Role;

class UserController extends Controller
{
    private function requireAdmin(): void
    {
        if (!Auth::check() || !Auth::user()->hasRole('admin')) {
            \$this->flash('errors', ['auth' => 'Access denied. Admin role required.']);
            \$this->redirect('/v1/dashboard');
        }
    }

    public function index(): string
    {
        \$this->requireAdmin();

        return \$this->render('dashboard/users/index', [
            'user' => Auth::user(),
            'users' => User::findAll(),
        ]);
    }

    public function create(): string
    {
        \$this->requireAdmin();

        return \$this->render('dashboard/users/create', [
            'user' => Auth::user(),
            'roles' => Role::findAll(),
        ]);
    }

    public function store(): void
    {
        \$this->requireAdmin();

        \$name = \$this->input('name', '');
        \$email = \$this->input('email', '');
        \$password = \$this->input('password', '');
        \$passwordConfirmation = \$this->input('password_confirmation', '');
        \$selectedRoleIds = \$this->input('roles', []);

        \$errors = [];

        if (empty(\$name)) {
            \$errors['name'] = 'Name is required.';
        }
        if (empty(\$email) || !filter_var(\$email, FILTER_VALIDATE_EMAIL)) {
            \$errors['email'] = 'A valid email is required.';
        }
        if (strlen(\$password) < 8) {
            \$errors['password'] = 'Password must be at least 8 characters.';
        }
        if (\$password !== \$passwordConfirmation) {
            \$errors['password_confirmation'] = 'Passwords do not match.';
        }

        if (empty(\$errors['email'])) {
            \$existing = User::findOneBy(['email' => \$email]);
            if (\$existing) {
                \$errors['email'] = 'This email is already taken.';
            }
        }

        if (!empty(\$errors)) {
            \$this->flash('errors', \$errors);
            \$this->flash('_old_input', ['name' => \$name, 'email' => \$email]);
            \$this->back();
            return;
        }

        \$newUser = new User();
        \$newUser->setName(\$name);
        \$newUser->setEmail(\$email);
        \$newUser->setPassword(Hash::make(\$password));
        \$newUser->save();

        // Assign roles
        if (is_array(\$selectedRoleIds) && !empty(\$selectedRoleIds)) {
            \$allRoles = Role::findAll();
            \$rolesToAssign = [];
            foreach (\$allRoles as \$role) {
                if (in_array((string) \$role->getId(), \$selectedRoleIds, true)) {
                    \$rolesToAssign[] = \$role;
                }
            }
            \$newUser->syncRoles(\$rolesToAssign);
            \$newUser->save();
        }

        \$this->flash('success', 'User created successfully.');
        \$this->redirect('/v1/dashboard/users');
    }

    public function edit(int \$id): string
    {
        \$this->requireAdmin();

        \$editUser = User::find(\$id);
        if (!\$editUser) {
            \$this->flash('errors', ['user' => 'User not found.']);
            \$this->redirect('/v1/dashboard/users');
            return '';
        }

        return \$this->render('dashboard/users/edit', [
            'user' => Auth::user(),
            'editUser' => \$editUser,
            'roles' => Role::findAll(),
        ]);
    }

    public function update(int \$id): void
    {
        \$this->requireAdmin();

        \$editUser = User::find(\$id);
        if (!\$editUser) {
            \$this->flash('errors', ['user' => 'User not found.']);
            \$this->redirect('/v1/dashboard/users');
            return;
        }

        \$name = \$this->input('name', '');
        \$email = \$this->input('email', '');
        \$password = \$this->input('password', '');
        \$selectedRoleIds = \$this->input('roles', []);

        \$errors = [];

        if (empty(\$name)) {
            \$errors['name'] = 'Name is required.';
        }
        if (empty(\$email) || !filter_var(\$email, FILTER_VALIDATE_EMAIL)) {
            \$errors['email'] = 'A valid email is required.';
        }

        // Check email uniqueness (excluding current user)
        if (empty(\$errors['email'])) {
            \$existing = User::findOneBy(['email' => \$email]);
            if (\$existing && \$existing->getId() !== \$editUser->getId()) {
                \$errors['email'] = 'This email is already taken.';
            }
        }

        if (!empty(\$password) && strlen(\$password) < 8) {
            \$errors['password'] = 'Password must be at least 8 characters.';
        }

        if (!empty(\$errors)) {
            \$this->flash('errors', \$errors);
            \$this->back();
            return;
        }

        \$editUser->setName(\$name);
        \$editUser->setEmail(\$email);

        if (!empty(\$password)) {
            \$editUser->setPassword(Hash::make(\$password));
        }

        // Sync roles
        if (!is_array(\$selectedRoleIds)) {
            \$selectedRoleIds = [];
        }
        \$allRoles = Role::findAll();
        \$rolesToAssign = [];
        foreach (\$allRoles as \$role) {
            if (in_array((string) \$role->getId(), \$selectedRoleIds, true)) {
                \$rolesToAssign[] = \$role;
            }
        }
        \$editUser->syncRoles(\$rolesToAssign);
        \$editUser->save();

        \$this->flash('success', 'User updated successfully.');
        \$this->redirect('/v1/dashboard/users');
    }

    public function destroy(int \$id): void
    {
        \$this->requireAdmin();

        \$editUser = User::find(\$id);
        if (!\$editUser) {
            \$this->flash('errors', ['user' => 'User not found.']);
            \$this->redirect('/v1/dashboard/users');
            return;
        }

        if (\$editUser->getId() === Auth::user()->getId()) {
            \$this->flash('errors', ['delete' => 'You cannot delete your own account.']);
            \$this->back();
            return;
        }

        \$editUser->delete();

        \$this->flash('success', 'User deleted successfully.');
        \$this->redirect('/v1/dashboard/users');
    }
}
PHP;

        $this->writeFile('app/Controllers/Dashboard/UserController.php', $content);
    }

    private function generateRoleController(): void
    {
        $ns = $this->namespace;

        $content = <<<PHP
<?php

declare(strict_types=1);

namespace {$ns}\\Controllers\\Dashboard;

use ZephyrPHP\\Core\\Controllers\\Controller;
use ZephyrPHP\\Auth\\Auth;
use {$ns}\\Models\\Role;

class RoleController extends Controller
{
    private function requireAdmin(): void
    {
        if (!Auth::check() || !Auth::user()->hasRole('admin')) {
            \$this->flash('errors', ['auth' => 'Access denied. Admin role required.']);
            \$this->redirect('/v1/dashboard');
        }
    }

    public function index(): string
    {
        \$this->requireAdmin();

        return \$this->render('dashboard/roles/index', [
            'user' => Auth::user(),
            'roles' => Role::findAll(),
        ]);
    }

    public function create(): string
    {
        \$this->requireAdmin();

        return \$this->render('dashboard/roles/create', [
            'user' => Auth::user(),
        ]);
    }

    public function store(): void
    {
        \$this->requireAdmin();

        \$name = \$this->input('name', '');
        \$slug = \$this->input('slug', '');
        \$description = \$this->input('description', '');

        if (empty(\$slug)) {
            \$slug = strtolower(trim(preg_replace('/[^A-Za-z0-9-]+/', '-', \$name), '-'));
        }

        \$errors = [];

        if (empty(\$name)) {
            \$errors['name'] = 'Role name is required.';
        }
        if (empty(\$slug)) {
            \$errors['slug'] = 'Role slug is required.';
        }

        if (empty(\$errors['name'])) {
            \$existing = Role::findOneBy(['name' => \$name]);
            if (\$existing) {
                \$errors['name'] = 'A role with this name already exists.';
            }
        }

        if (empty(\$errors['slug'])) {
            \$existing = Role::findOneBy(['slug' => \$slug]);
            if (\$existing) {
                \$errors['slug'] = 'A role with this slug already exists.';
            }
        }

        if (!empty(\$errors)) {
            \$this->flash('errors', \$errors);
            \$this->flash('_old_input', ['name' => \$name, 'slug' => \$slug, 'description' => \$description]);
            \$this->back();
            return;
        }

        \$role = new Role();
        \$role->setName(\$name);
        \$role->setSlug(\$slug);
        \$role->setDescription(\$description ?: null);
        \$role->save();

        \$this->flash('success', 'Role created successfully.');
        \$this->redirect('/v1/dashboard/roles');
    }

    public function edit(int \$id): string
    {
        \$this->requireAdmin();

        \$editRole = Role::find(\$id);
        if (!\$editRole) {
            \$this->flash('errors', ['role' => 'Role not found.']);
            \$this->redirect('/v1/dashboard/roles');
            return '';
        }

        return \$this->render('dashboard/roles/edit', [
            'user' => Auth::user(),
            'editRole' => \$editRole,
        ]);
    }

    public function update(int \$id): void
    {
        \$this->requireAdmin();

        \$editRole = Role::find(\$id);
        if (!\$editRole) {
            \$this->flash('errors', ['role' => 'Role not found.']);
            \$this->redirect('/v1/dashboard/roles');
            return;
        }

        \$name = \$this->input('name', '');
        \$slug = \$this->input('slug', '');
        \$description = \$this->input('description', '');

        \$errors = [];

        if (empty(\$name)) {
            \$errors['name'] = 'Role name is required.';
        }
        if (empty(\$slug)) {
            \$errors['slug'] = 'Role slug is required.';
        }

        if (empty(\$errors['name'])) {
            \$existing = Role::findOneBy(['name' => \$name]);
            if (\$existing && \$existing->getId() !== \$editRole->getId()) {
                \$errors['name'] = 'A role with this name already exists.';
            }
        }

        if (empty(\$errors['slug'])) {
            \$existing = Role::findOneBy(['slug' => \$slug]);
            if (\$existing && \$existing->getId() !== \$editRole->getId()) {
                \$errors['slug'] = 'A role with this slug already exists.';
            }
        }

        if (!empty(\$errors)) {
            \$this->flash('errors', \$errors);
            \$this->back();
            return;
        }

        \$editRole->setName(\$name);
        \$editRole->setSlug(\$slug);
        \$editRole->setDescription(\$description ?: null);
        \$editRole->save();

        \$this->flash('success', 'Role updated successfully.');
        \$this->redirect('/v1/dashboard/roles');
    }

    public function destroy(int \$id): void
    {
        \$this->requireAdmin();

        \$editRole = Role::find(\$id);
        if (!\$editRole) {
            \$this->flash('errors', ['role' => 'Role not found.']);
            \$this->redirect('/v1/dashboard/roles');
            return;
        }

        if (\$editRole->getSlug() === 'admin') {
            \$this->flash('errors', ['delete' => 'The admin role cannot be deleted.']);
            \$this->back();
            return;
        }

        \$editRole->delete();

        \$this->flash('success', 'Role deleted successfully.');
        \$this->redirect('/v1/dashboard/roles');
    }
}
PHP;

        $this->writeFile('app/Controllers/Dashboard/RoleController.php', $content);
    }

    // =========================================================================
    // Twig Template Generation
    // =========================================================================

    private function generateLoginView(): void
    {
        $content = <<<'TWIG'
{% extends "layouts/base.twig" %}

{% block title %}Login{% endblock %}

{% block styles %}
<link rel="stylesheet" href="/assets/css/dashboard.css">
{% endblock %}

{% block content %}
<div class="auth-container">
    <div class="auth-card">
        <h2 class="auth-title">Welcome Back</h2>
        <p class="auth-subtitle">Sign in to your account</p>

        {% if flash('errors') is iterable %}
        <div class="alert alert-error">
            {% for error in flash('errors') %}
                <p>{{ error }}</p>
            {% endfor %}
        </div>
        {% endif %}

        <form method="POST" action="/login" class="auth-form">
            {{ csrf_field() | raw }}

            <div class="form-group">
                <label for="email">Email</label>
                <input type="email" id="email" name="email" value="{{ old('email') }}"
                       placeholder="you@example.com" required autofocus>
            </div>

            <div class="form-group">
                <label for="password">Password</label>
                <input type="password" id="password" name="password"
                       placeholder="Your password" required>
            </div>

            <div class="form-group form-check">
                <label class="checkbox-label">
                    <input type="checkbox" name="remember" value="1">
                    <span>Remember me</span>
                </label>
            </div>

            <button type="submit" class="btn btn-primary btn-full">Sign In</button>
        </form>

        <p class="auth-footer">
            Don't have an account? <a href="/register">Create one</a>
        </p>
    </div>
</div>
{% endblock %}
TWIG;

        $this->writeFile('pages/auth/login.twig', $content);
    }

    private function generateRegisterView(): void
    {
        $content = <<<'TWIG'
{% extends "layouts/base.twig" %}

{% block title %}Register{% endblock %}

{% block styles %}
<link rel="stylesheet" href="/assets/css/dashboard.css">
{% endblock %}

{% block content %}
<div class="auth-container">
    <div class="auth-card">
        <h2 class="auth-title">Create Account</h2>
        <p class="auth-subtitle">Join us today</p>

        {% if flash('errors') is iterable %}
        <div class="alert alert-error">
            {% for error in flash('errors') %}
                <p>{{ error }}</p>
            {% endfor %}
        </div>
        {% endif %}

        <form method="POST" action="/register" class="auth-form">
            {{ csrf_field() | raw }}

            <div class="form-group">
                <label for="name">Name</label>
                <input type="text" id="name" name="name" value="{{ old('name') }}"
                       placeholder="Your full name" required autofocus>
            </div>

            <div class="form-group">
                <label for="email">Email</label>
                <input type="email" id="email" name="email" value="{{ old('email') }}"
                       placeholder="you@example.com" required>
            </div>

            <div class="form-group">
                <label for="password">Password</label>
                <input type="password" id="password" name="password"
                       placeholder="Min. 8 characters" required>
            </div>

            <div class="form-group">
                <label for="password_confirmation">Confirm Password</label>
                <input type="password" id="password_confirmation" name="password_confirmation"
                       placeholder="Repeat your password" required>
            </div>

            <button type="submit" class="btn btn-primary btn-full">Create Account</button>
        </form>

        <p class="auth-footer">
            Already have an account? <a href="/login">Sign in</a>
        </p>
    </div>
</div>
{% endblock %}
TWIG;

        $this->writeFile('pages/auth/register.twig', $content);
    }

    private function generateDashboardLayout(): void
    {
        $adminLinks = '';
        if ($this->hasAuthorization) {
            $adminLinks = <<<'TWIG'

                <li>
                    <a href="/v1/dashboard/settings" class="{% if request().path() starts with '/v1/dashboard/settings' %}active{% endif %}">
                        <span class="nav-icon">&#9881;</span> Settings
                    </a>
                </li>

                {% if auth().user().hasRole('admin') %}
                <li class="nav-divider"></li>
                <li class="nav-heading">Admin</li>
                <li>
                    <a href="/v1/dashboard/users" class="{% if request().path() starts with '/v1/dashboard/users' %}active{% endif %}">
                        <span class="nav-icon">&#128101;</span> Users
                    </a>
                </li>
                <li>
                    <a href="/v1/dashboard/roles" class="{% if request().path() starts with '/v1/dashboard/roles' %}active{% endif %}">
                        <span class="nav-icon">&#128274;</span> Roles
                    </a>
                </li>
                <li>
                    <a href="/v1/dashboard/admin/settings" class="{% if request().path() starts with '/v1/dashboard/admin' %}active{% endif %}">
                        <span class="nav-icon">&#9998;</span> Admin Settings
                    </a>
                </li>
                {% endif %}
TWIG;
        } else {
            $adminLinks = <<<'TWIG'

                <li>
                    <a href="/v1/dashboard/settings" class="{% if request().path() starts with '/v1/dashboard/settings' %}active{% endif %}">
                        <span class="nav-icon">&#9881;</span> Settings
                    </a>
                </li>
TWIG;
        }

        $content = <<<TWIG
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{% block title %}Dashboard{% endblock %} | {{ config('app.name') }}</title>
    <link rel="stylesheet" href="/assets/css/app.css">
    <link rel="stylesheet" href="/assets/css/dashboard.css">
    {% block styles %}{% endblock %}
</head>
<body class="dashboard-body">
    <aside class="sidebar" id="sidebar">
        <div class="sidebar-header">
            <a href="/" class="sidebar-brand">{{ config('app.name') }}</a>
            <button class="sidebar-close" id="sidebar-close">&times;</button>
        </div>

        <nav class="sidebar-nav">
            <ul>
                <li>
                    <a href="/v1/dashboard" class="{% if request().path() == '/v1/dashboard' %}active{% endif %}">
                        <span class="nav-icon">&#9632;</span> Dashboard
                    </a>
                </li>{$adminLinks}
            </ul>
        </nav>

        <div class="sidebar-footer">
            <div class="sidebar-user">
                <span class="user-name">{{ auth().user().getName() }}</span>
                <span class="user-email">{{ auth().user().getEmail() }}</span>
            </div>
            <form method="POST" action="/logout">
                {{ csrf_field() | raw }}
                <button type="submit" class="btn-logout">Logout</button>
            </form>
        </div>
    </aside>

    <main class="dashboard-main">
        <header class="dashboard-header">
            <button class="sidebar-toggle" id="sidebar-toggle">&#9776;</button>
            <div class="header-right">
                <span class="header-user">{{ auth().user().getName() }}</span>
            </div>
        </header>

        <div class="dashboard-content">
            {% if flash('success') %}
            <div class="alert alert-success">
                {{ flash('success') }}
            </div>
            {% endif %}

            {% if flash('errors') is iterable %}
            <div class="alert alert-error">
                {% for error in flash('errors') %}
                    <p>{{ error }}</p>
                {% endfor %}
            </div>
            {% endif %}

            {% block content %}{% endblock %}
        </div>
    </main>

    <script>
    document.addEventListener('DOMContentLoaded', function() {
        const toggle = document.getElementById('sidebar-toggle');
        const close = document.getElementById('sidebar-close');
        const sidebar = document.getElementById('sidebar');

        if (toggle) toggle.addEventListener('click', () => sidebar.classList.toggle('open'));
        if (close) close.addEventListener('click', () => sidebar.classList.remove('open'));
    });
    </script>
    {% block scripts %}{% endblock %}
</body>
</html>
TWIG;

        $this->writeFile('pages/layouts/dashboard.twig', $content);
    }

    private function generateDashboardView(): void
    {
        $content = <<<'TWIG'
{% extends "layouts/dashboard.twig" %}

{% block title %}Dashboard{% endblock %}

{% block content %}
<div class="page-header">
    <h1>Dashboard</h1>
    <p class="text-muted">Welcome back, {{ user.getName() }}!</p>
</div>

<div class="stats-grid">
    <div class="stat-card">
        <div class="stat-icon">&#128100;</div>
        <div class="stat-info">
            <span class="stat-label">Account</span>
            <span class="stat-value">Active</span>
        </div>
    </div>

    <div class="stat-card">
        <div class="stat-icon">&#128231;</div>
        <div class="stat-info">
            <span class="stat-label">Email</span>
            <span class="stat-value">{{ user.getEmail() }}</span>
        </div>
    </div>

    <div class="stat-card">
        <div class="stat-icon">&#128197;</div>
        <div class="stat-info">
            <span class="stat-label">Member Since</span>
            <span class="stat-value">{{ user.getCreatedAt() | date('M d, Y') }}</span>
        </div>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <h3>Quick Actions</h3>
    </div>
    <div class="card-body">
        <a href="/v1/dashboard/settings" class="btn btn-outline">Edit Profile</a>
    </div>
</div>
{% endblock %}
TWIG;

        $this->writeFile('pages/dashboard/index.twig', $content);
    }

    private function generateSettingsView(): void
    {
        $content = <<<'TWIG'
{% extends "layouts/dashboard.twig" %}

{% block title %}Settings{% endblock %}

{% block content %}
<div class="page-header">
    <h1>Settings</h1>
    <p class="text-muted">Manage your account settings</p>
</div>

<div class="card">
    <div class="tabs">
        <div class="tab-nav">
            <button class="tab-btn active" data-tab="profile">Profile</button>
            <button class="tab-btn" data-tab="password">Password</button>
        </div>

        <div class="tab-panel active" id="tab-profile">
            <h3>Profile Information</h3>
            <form method="POST" action="/v1/dashboard/settings/profile">
                {{ csrf_field() | raw }}

                <div class="form-group">
                    <label for="name">Name</label>
                    <input type="text" id="name" name="name"
                           value="{{ user.getName() }}" required>
                </div>

                <div class="form-group">
                    <label for="email">Email</label>
                    <input type="email" id="email" name="email"
                           value="{{ user.getEmail() }}" required>
                </div>

                <button type="submit" class="btn btn-primary">Save Changes</button>
            </form>
        </div>

        <div class="tab-panel" id="tab-password">
            <h3>Change Password</h3>
            <form method="POST" action="/v1/dashboard/settings/password">
                {{ csrf_field() | raw }}

                <div class="form-group">
                    <label for="current_password">Current Password</label>
                    <input type="password" id="current_password" name="current_password" required>
                </div>

                <div class="form-group">
                    <label for="new_password">New Password</label>
                    <input type="password" id="new_password" name="new_password"
                           placeholder="Min. 8 characters" required>
                </div>

                <div class="form-group">
                    <label for="new_password_confirmation">Confirm New Password</label>
                    <input type="password" id="new_password_confirmation" name="new_password_confirmation" required>
                </div>

                <button type="submit" class="btn btn-primary">Change Password</button>
            </form>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('.tab-btn').forEach(function(btn) {
        btn.addEventListener('click', function() {
            document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
            document.querySelectorAll('.tab-panel').forEach(p => p.classList.remove('active'));
            btn.classList.add('active');
            document.getElementById('tab-' + btn.dataset.tab).classList.add('active');
        });
    });
});
</script>
{% endblock %}
TWIG;

        $this->writeFile('pages/dashboard/settings.twig', $content);
    }

    private function generateAdminSettingsView(): void
    {
        $content = <<<'TWIG'
{% extends "layouts/dashboard.twig" %}

{% block title %}Admin Settings{% endblock %}

{% block content %}
<div class="page-header">
    <h1>Admin Settings</h1>
    <p class="text-muted">Configure system-wide settings</p>
</div>

<div class="card">
    <div class="card-header">
        <h3>Registration</h3>
    </div>
    <div class="card-body">
        <form method="POST" action="/v1/dashboard/admin/settings">
            {{ csrf_field() | raw }}

            <div class="form-group">
                <div class="toggle-label">
                    <span>Allow Public Registration</span>
                    <label class="toggle-switch">
                        <input type="checkbox" name="registration_enabled" value="1"
                               {% if registration_enabled %}checked{% endif %}>
                        <span class="toggle-slider"></span>
                    </label>
                </div>
                <p class="text-muted" style="margin-top: 0.5rem; font-size: 0.8rem;">
                    When disabled, only admins can create new users from the Users management page.
                </p>
            </div>

            <div class="form-group" style="margin-top: 1.5rem;">
                <label for="default_registration_role">Default Role for New Registrations</label>
                <select name="default_registration_role" id="default_registration_role" class="form-control">
                    <option value="">— No role —</option>
                    {% for role in roles %}
                        <option value="{{ role.getId() }}"
                                {% if default_role_id == role.getId() ~ '' %}selected{% endif %}>
                            {{ role.getName() }}
                        </option>
                    {% endfor %}
                </select>
                <p class="text-muted" style="margin-top: 0.5rem; font-size: 0.8rem;">
                    Users who register via the public registration form will automatically be assigned this role.
                </p>
            </div>

            <button type="submit" class="btn btn-primary">Save Settings</button>
        </form>
    </div>
</div>
{% endblock %}
TWIG;

        $this->writeFile('pages/dashboard/admin/settings.twig', $content);
    }

    private function generateUserViews(): void
    {
        // Users index
        $indexContent = <<<'TWIG'
{% extends "layouts/dashboard.twig" %}

{% block title %}Users{% endblock %}

{% block content %}
<div class="page-header page-header-with-action">
    <div>
        <h1>Users</h1>
        <p class="text-muted">Manage user accounts</p>
    </div>
    <a href="/v1/dashboard/users/create" class="btn btn-primary">Add User</a>
</div>

<div class="card">
    <div class="table-responsive">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Name</th>
                    <th>Email</th>
                    <th>Roles</th>
                    <th>Created</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                {% for u in users %}
                <tr>
                    <td>{{ u.getName() }}</td>
                    <td>{{ u.getEmail() }}</td>
                    <td>
                        {% for role in u.getRoleNames() %}
                            <span class="badge">{{ role }}</span>
                        {% endfor %}
                    </td>
                    <td>{{ u.getCreatedAt() | date('M d, Y') }}</td>
                    <td class="actions-cell">
                        <a href="/v1/dashboard/users/{{ u.getId() }}/edit" class="btn btn-sm btn-outline">Edit</a>
                        {% if u.getId() != user.getId() %}
                        <form method="POST" action="/v1/dashboard/users/{{ u.getId() }}/delete"
                              onsubmit="return confirm('Delete user {{ u.getName() }}? This cannot be undone.')">
                            {{ csrf_field() | raw }}
                            <button type="submit" class="btn btn-sm btn-danger">Delete</button>
                        </form>
                        {% endif %}
                    </td>
                </tr>
                {% else %}
                <tr>
                    <td colspan="5" class="empty-state">No users found.</td>
                </tr>
                {% endfor %}
            </tbody>
        </table>
    </div>
</div>
{% endblock %}
TWIG;

        $this->writeFile('pages/dashboard/users/index.twig', $indexContent);

        // Users create
        $createContent = <<<'TWIG'
{% extends "layouts/dashboard.twig" %}

{% block title %}Create User{% endblock %}

{% block content %}
<div class="page-header">
    <h1>Create User</h1>
    <p class="text-muted">Add a new user account</p>
</div>

<div class="card">
    <div class="card-body">
        <form method="POST" action="/v1/dashboard/users">
            {{ csrf_field() | raw }}

            <div class="form-group">
                <label for="name">Name</label>
                <input type="text" id="name" name="name" value="{{ old('name') }}"
                       placeholder="Full name" required>
            </div>

            <div class="form-group">
                <label for="email">Email</label>
                <input type="email" id="email" name="email" value="{{ old('email') }}"
                       placeholder="user@example.com" required>
            </div>

            <div class="form-group">
                <label for="password">Password</label>
                <input type="password" id="password" name="password"
                       placeholder="Min. 8 characters" required>
            </div>

            <div class="form-group">
                <label for="password_confirmation">Confirm Password</label>
                <input type="password" id="password_confirmation" name="password_confirmation"
                       placeholder="Repeat password" required>
            </div>

            {% if roles is defined and roles|length > 0 %}
            <div class="form-group">
                <label>Roles</label>
                <div class="roles-grid">
                    {% for role in roles %}
                    <label class="role-checkbox">
                        <input type="checkbox" name="roles[]" value="{{ role.getId() }}">
                        <span class="role-name">{{ role.getName() }}</span>
                        {% if role.getDescription() %}
                        <span class="role-description">{{ role.getDescription() }}</span>
                        {% endif %}
                    </label>
                    {% endfor %}
                </div>
            </div>
            {% endif %}

            <div class="form-actions">
                <button type="submit" class="btn btn-primary">Create User</button>
                <a href="/v1/dashboard/users" class="btn btn-outline">Cancel</a>
            </div>
        </form>
    </div>
</div>
{% endblock %}
TWIG;

        $this->writeFile('pages/dashboard/users/create.twig', $createContent);

        // Users edit
        $editContent = <<<'TWIG'
{% extends "layouts/dashboard.twig" %}

{% block title %}Edit User{% endblock %}

{% block content %}
<div class="page-header">
    <h1>Edit User</h1>
    <p class="text-muted">Update user account: {{ editUser.getName() }}</p>
</div>

<div class="card">
    <div class="card-body">
        <form method="POST" action="/v1/dashboard/users/{{ editUser.getId() }}">
            {{ csrf_field() | raw }}

            <div class="form-group">
                <label for="name">Name</label>
                <input type="text" id="name" name="name"
                       value="{{ editUser.getName() }}" required>
            </div>

            <div class="form-group">
                <label for="email">Email</label>
                <input type="email" id="email" name="email"
                       value="{{ editUser.getEmail() }}" required>
            </div>

            <div class="form-group">
                <label for="password">New Password <span class="text-muted">(leave blank to keep current)</span></label>
                <input type="password" id="password" name="password"
                       placeholder="Min. 8 characters">
            </div>

            {% if roles is defined and roles|length > 0 %}
            <div class="form-group">
                <label>Roles</label>
                <div class="roles-grid">
                    {% for role in roles %}
                    <label class="role-checkbox">
                        <input type="checkbox" name="roles[]" value="{{ role.getId() }}"
                               {% if editUser.hasRole(role.getName()) %}checked{% endif %}>
                        <span class="role-name">{{ role.getName() }}</span>
                        {% if role.getDescription() %}
                        <span class="role-description">{{ role.getDescription() }}</span>
                        {% endif %}
                    </label>
                    {% endfor %}
                </div>
            </div>
            {% endif %}

            <div class="form-actions">
                <button type="submit" class="btn btn-primary">Save Changes</button>
                <a href="/v1/dashboard/users" class="btn btn-outline">Cancel</a>
            </div>
        </form>
    </div>
</div>
{% endblock %}
TWIG;

        $this->writeFile('pages/dashboard/users/edit.twig', $editContent);
    }

    private function generateRoleViews(): void
    {
        // Roles index
        $indexContent = <<<'TWIG'
{% extends "layouts/dashboard.twig" %}

{% block title %}Roles{% endblock %}

{% block content %}
<div class="page-header page-header-with-action">
    <div>
        <h1>Roles</h1>
        <p class="text-muted">Manage user roles</p>
    </div>
    <a href="/v1/dashboard/roles/create" class="btn btn-primary">Add Role</a>
</div>

<div class="card">
    <div class="table-responsive">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Name</th>
                    <th>Slug</th>
                    <th>Description</th>
                    <th>Created</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                {% for role in roles %}
                <tr>
                    <td><span class="badge">{{ role.getName() }}</span></td>
                    <td>{{ role.getSlug() }}</td>
                    <td>{{ role.getDescription() ?: '-' }}</td>
                    <td>{{ role.getCreatedAt() | date('M d, Y') }}</td>
                    <td class="actions-cell">
                        <a href="/v1/dashboard/roles/{{ role.getId() }}/edit" class="btn btn-sm btn-outline">Edit</a>
                        {% if role.getSlug() != 'admin' %}
                        <form method="POST" action="/v1/dashboard/roles/{{ role.getId() }}/delete"
                              onsubmit="return confirm('Delete role {{ role.getName() }}? This cannot be undone.')">
                            {{ csrf_field() | raw }}
                            <button type="submit" class="btn btn-sm btn-danger">Delete</button>
                        </form>
                        {% endif %}
                    </td>
                </tr>
                {% else %}
                <tr>
                    <td colspan="5" class="empty-state">No roles defined yet.</td>
                </tr>
                {% endfor %}
            </tbody>
        </table>
    </div>
</div>
{% endblock %}
TWIG;

        $this->writeFile('pages/dashboard/roles/index.twig', $indexContent);

        // Roles create
        $createContent = <<<'TWIG'
{% extends "layouts/dashboard.twig" %}

{% block title %}Create Role{% endblock %}

{% block content %}
<div class="page-header">
    <h1>Create Role</h1>
    <p class="text-muted">Add a new role</p>
</div>

<div class="card">
    <div class="card-body">
        <form method="POST" action="/v1/dashboard/roles">
            {{ csrf_field() | raw }}

            <div class="form-group">
                <label for="name">Role Name</label>
                <input type="text" id="name" name="name" value="{{ old('name') }}"
                       placeholder="e.g. Editor" required>
            </div>

            <div class="form-group">
                <label for="slug">Slug <span class="text-muted">(auto-generated if left blank)</span></label>
                <input type="text" id="slug" name="slug" value="{{ old('slug') }}"
                       placeholder="e.g. editor">
            </div>

            <div class="form-group">
                <label for="description">Description</label>
                <textarea id="description" name="description" rows="3"
                          placeholder="Optional description of this role">{{ old('description') }}</textarea>
            </div>

            <div class="form-actions">
                <button type="submit" class="btn btn-primary">Create Role</button>
                <a href="/v1/dashboard/roles" class="btn btn-outline">Cancel</a>
            </div>
        </form>
    </div>
</div>
{% endblock %}
TWIG;

        $this->writeFile('pages/dashboard/roles/create.twig', $createContent);

        // Roles edit
        $editContent = <<<'TWIG'
{% extends "layouts/dashboard.twig" %}

{% block title %}Edit Role{% endblock %}

{% block content %}
<div class="page-header">
    <h1>Edit Role</h1>
    <p class="text-muted">Update role: {{ editRole.getName() }}</p>
</div>

<div class="card">
    <div class="card-body">
        <form method="POST" action="/v1/dashboard/roles/{{ editRole.getId() }}">
            {{ csrf_field() | raw }}

            <div class="form-group">
                <label for="name">Role Name</label>
                <input type="text" id="name" name="name"
                       value="{{ editRole.getName() }}" required>
            </div>

            <div class="form-group">
                <label for="slug">Slug</label>
                <input type="text" id="slug" name="slug"
                       value="{{ editRole.getSlug() }}"
                       {% if editRole.getSlug() == 'admin' %}readonly{% endif %}
                       required>
            </div>

            <div class="form-group">
                <label for="description">Description</label>
                <textarea id="description" name="description" rows="3"
                          placeholder="Optional description">{{ editRole.getDescription() }}</textarea>
            </div>

            <div class="form-actions">
                <button type="submit" class="btn btn-primary">Save Changes</button>
                <a href="/v1/dashboard/roles" class="btn btn-outline">Cancel</a>
            </div>
        </form>
    </div>
</div>
{% endblock %}
TWIG;

        $this->writeFile('pages/dashboard/roles/edit.twig', $editContent);
    }

    // =========================================================================
    // CSS Generation
    // =========================================================================

    private function generateDashboardCss(): void
    {
        $content = <<<'CSS'
/**
 * ZephyrPHP Dashboard & Auth Styles
 * Extends the base app.css variables
 */

/* ============================================
   Auth Pages
   ============================================ */
.auth-container {
    display: flex;
    justify-content: center;
    align-items: center;
    min-height: 80vh;
    padding: var(--spacing-lg);
}

.auth-card {
    background: var(--dark-bg-secondary);
    border: 1px solid rgba(255, 255, 255, 0.08);
    border-radius: var(--radius-lg);
    padding: var(--spacing-xl);
    width: 100%;
    max-width: 420px;
}

.auth-title {
    font-size: 1.75rem;
    font-weight: 700;
    margin-bottom: var(--spacing-xs);
    background: linear-gradient(135deg, var(--primary-color), var(--accent-color));
    -webkit-background-clip: text;
    -webkit-text-fill-color: transparent;
    background-clip: text;
}

.auth-subtitle {
    color: var(--text-secondary);
    margin-bottom: var(--spacing-lg);
}

.auth-form .form-group {
    margin-bottom: var(--spacing-md);
}

.auth-footer {
    text-align: center;
    margin-top: var(--spacing-lg);
    color: var(--text-secondary);
}

.auth-footer a {
    color: var(--primary-color);
    text-decoration: none;
}

.auth-footer a:hover {
    text-decoration: underline;
}

/* ============================================
   Forms
   ============================================ */
.form-group {
    margin-bottom: var(--spacing-md);
}

.form-group label {
    display: block;
    margin-bottom: var(--spacing-xs);
    font-weight: 500;
    color: var(--text-secondary);
    font-size: 0.875rem;
}

.form-group input[type="text"],
.form-group input[type="email"],
.form-group input[type="password"] {
    width: 100%;
    padding: 0.75rem 1rem;
    background: var(--dark-bg);
    border: 1px solid rgba(255, 255, 255, 0.1);
    border-radius: var(--radius-md);
    color: var(--text-primary);
    font-size: 1rem;
    font-family: var(--font-family);
    transition: border-color var(--transition-fast);
}

.form-group input:focus {
    outline: none;
    border-color: var(--primary-color);
    box-shadow: 0 0 0 3px rgba(0, 217, 255, 0.1);
}

.form-group input::placeholder {
    color: var(--text-muted);
}

.form-check {
    display: flex;
    align-items: center;
}

.checkbox-label {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    cursor: pointer;
    color: var(--text-secondary);
    font-size: 0.875rem;
}

.checkbox-label input[type="checkbox"] {
    accent-color: var(--primary-color);
}

/* ============================================
   Buttons
   ============================================ */
.btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    padding: 0.75rem 1.5rem;
    font-size: 0.875rem;
    font-weight: 600;
    border-radius: var(--radius-md);
    border: none;
    cursor: pointer;
    transition: all var(--transition-fast);
    text-decoration: none;
    font-family: var(--font-family);
}

.btn-primary {
    background: linear-gradient(135deg, var(--primary-color), var(--accent-color));
    color: var(--dark-bg);
}

.btn-primary:hover {
    transform: translateY(-1px);
    box-shadow: 0 4px 15px rgba(0, 217, 255, 0.3);
}

.btn-outline {
    background: transparent;
    border: 1px solid var(--primary-color);
    color: var(--primary-color);
}

.btn-outline:hover {
    background: rgba(0, 217, 255, 0.1);
}

.btn-full {
    width: 100%;
}

/* ============================================
   Alerts
   ============================================ */
.alert {
    padding: var(--spacing-sm) var(--spacing-md);
    border-radius: var(--radius-md);
    margin-bottom: var(--spacing-md);
    font-size: 0.875rem;
}

.alert p {
    margin: 0;
}

.alert p + p {
    margin-top: 0.25rem;
}

.alert-error {
    background: rgba(255, 82, 82, 0.1);
    border: 1px solid rgba(255, 82, 82, 0.3);
    color: #ff5252;
}

.alert-success {
    background: rgba(0, 255, 136, 0.1);
    border: 1px solid rgba(0, 255, 136, 0.3);
    color: var(--secondary-color);
}

/* ============================================
   Dashboard Layout
   ============================================ */
.dashboard-body {
    display: flex;
    min-height: 100vh;
    background: var(--dark-bg);
}

.sidebar {
    width: 260px;
    background: var(--dark-bg-secondary);
    border-right: 1px solid rgba(255, 255, 255, 0.06);
    display: flex;
    flex-direction: column;
    position: fixed;
    top: 0;
    left: 0;
    bottom: 0;
    z-index: 100;
    transition: transform var(--transition-base);
}

.sidebar-header {
    padding: var(--spacing-md) var(--spacing-lg);
    border-bottom: 1px solid rgba(255, 255, 255, 0.06);
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.sidebar-brand {
    font-size: 1.25rem;
    font-weight: 700;
    color: var(--primary-color);
    text-decoration: none;
}

.sidebar-close {
    display: none;
    background: none;
    border: none;
    color: var(--text-secondary);
    font-size: 1.5rem;
    cursor: pointer;
}

.sidebar-nav {
    flex: 1;
    padding: var(--spacing-sm) 0;
    overflow-y: auto;
}

.sidebar-nav ul {
    list-style: none;
    padding: 0;
    margin: 0;
}

.sidebar-nav a {
    display: flex;
    align-items: center;
    gap: 0.75rem;
    padding: 0.75rem var(--spacing-lg);
    color: var(--text-secondary);
    text-decoration: none;
    font-size: 0.9rem;
    transition: all var(--transition-fast);
    border-left: 3px solid transparent;
}

.sidebar-nav a:hover {
    background: rgba(255, 255, 255, 0.03);
    color: var(--text-primary);
}

.sidebar-nav a.active {
    background: rgba(0, 217, 255, 0.05);
    color: var(--primary-color);
    border-left-color: var(--primary-color);
}

.nav-icon {
    font-size: 1.1rem;
    width: 1.5rem;
    text-align: center;
}

.sidebar-footer {
    padding: var(--spacing-md) var(--spacing-lg);
    border-top: 1px solid rgba(255, 255, 255, 0.06);
}

.sidebar-user {
    display: flex;
    flex-direction: column;
    margin-bottom: var(--spacing-sm);
}

.user-name {
    font-weight: 600;
    font-size: 0.875rem;
}

.user-email {
    color: var(--text-muted);
    font-size: 0.75rem;
}

.btn-logout {
    width: 100%;
    padding: 0.5rem;
    background: rgba(255, 82, 82, 0.1);
    border: 1px solid rgba(255, 82, 82, 0.3);
    border-radius: var(--radius-sm);
    color: #ff5252;
    cursor: pointer;
    font-size: 0.8rem;
    font-family: var(--font-family);
    transition: all var(--transition-fast);
}

.btn-logout:hover {
    background: rgba(255, 82, 82, 0.2);
}

/* ============================================
   Dashboard Content
   ============================================ */
.dashboard-main {
    flex: 1;
    margin-left: 260px;
    min-height: 100vh;
}

.dashboard-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: var(--spacing-sm) var(--spacing-lg);
    background: var(--dark-bg-secondary);
    border-bottom: 1px solid rgba(255, 255, 255, 0.06);
    position: sticky;
    top: 0;
    z-index: 50;
}

.sidebar-toggle {
    display: none;
    background: none;
    border: none;
    color: var(--text-primary);
    font-size: 1.5rem;
    cursor: pointer;
}

.header-user {
    color: var(--text-secondary);
    font-size: 0.875rem;
}

.dashboard-content {
    padding: var(--spacing-lg);
    max-width: 1200px;
}

/* ============================================
   Page Header
   ============================================ */
.page-header {
    margin-bottom: var(--spacing-lg);
}

.page-header h1 {
    font-size: 1.75rem;
    font-weight: 700;
    margin-bottom: 0.25rem;
}

.text-muted {
    color: var(--text-muted);
    font-size: 0.875rem;
}

/* ============================================
   Stats Grid
   ============================================ */
.stats-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
    gap: var(--spacing-md);
    margin-bottom: var(--spacing-lg);
}

.stat-card {
    display: flex;
    align-items: center;
    gap: var(--spacing-md);
    background: var(--dark-bg-secondary);
    border: 1px solid rgba(255, 255, 255, 0.06);
    border-radius: var(--radius-lg);
    padding: var(--spacing-md) var(--spacing-lg);
}

.stat-icon {
    font-size: 2rem;
}

.stat-info {
    display: flex;
    flex-direction: column;
}

.stat-label {
    font-size: 0.75rem;
    color: var(--text-muted);
    text-transform: uppercase;
    letter-spacing: 0.05em;
}

.stat-value {
    font-size: 1rem;
    font-weight: 600;
}

/* ============================================
   Cards
   ============================================ */
.card {
    background: var(--dark-bg-secondary);
    border: 1px solid rgba(255, 255, 255, 0.06);
    border-radius: var(--radius-lg);
    overflow: hidden;
}

.card-header {
    padding: var(--spacing-md) var(--spacing-lg);
    border-bottom: 1px solid rgba(255, 255, 255, 0.06);
}

.card-header h3 {
    font-size: 1rem;
    font-weight: 600;
    margin: 0;
}

.card-body {
    padding: var(--spacing-lg);
}

/* ============================================
   Tabs
   ============================================ */
.tab-nav {
    display: flex;
    border-bottom: 1px solid rgba(255, 255, 255, 0.06);
    padding: 0 var(--spacing-lg);
}

.tab-btn {
    padding: var(--spacing-sm) var(--spacing-md);
    background: none;
    border: none;
    border-bottom: 2px solid transparent;
    color: var(--text-secondary);
    cursor: pointer;
    font-size: 0.875rem;
    font-family: var(--font-family);
    transition: all var(--transition-fast);
}

.tab-btn:hover {
    color: var(--text-primary);
}

.tab-btn.active {
    color: var(--primary-color);
    border-bottom-color: var(--primary-color);
}

.tab-panel {
    display: none;
    padding: var(--spacing-lg);
}

.tab-panel.active {
    display: block;
}

.tab-panel h3 {
    font-size: 1.1rem;
    margin-bottom: var(--spacing-md);
}

/* ============================================
   Roles
   ============================================ */
.roles-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(250px, 1fr));
    gap: var(--spacing-sm);
    margin-bottom: var(--spacing-lg);
}

.role-checkbox {
    display: flex;
    flex-direction: column;
    padding: var(--spacing-sm) var(--spacing-md);
    background: var(--dark-bg);
    border: 1px solid rgba(255, 255, 255, 0.08);
    border-radius: var(--radius-md);
    cursor: pointer;
    transition: border-color var(--transition-fast);
}

.role-checkbox:hover {
    border-color: var(--primary-color);
}

.role-checkbox input[type="checkbox"] {
    accent-color: var(--primary-color);
    margin-bottom: 0.25rem;
}

.role-name {
    font-weight: 600;
    font-size: 0.9rem;
}

.role-description {
    font-size: 0.75rem;
    color: var(--text-muted);
}

/* ============================================
   Responsive
   ============================================ */
@media (max-width: 768px) {
    .sidebar {
        transform: translateX(-100%);
    }

    .sidebar.open {
        transform: translateX(0);
    }

    .sidebar-close {
        display: block;
    }

    .sidebar-toggle {
        display: block;
    }

    .dashboard-main {
        margin-left: 0;
    }

    .stats-grid {
        grid-template-columns: 1fr;
    }

    .tab-nav {
        overflow-x: auto;
    }

    .page-header-with-action {
        flex-direction: column;
        gap: var(--spacing-sm);
    }
}

/* ============================================
   Navigation Sections
   ============================================ */
.nav-divider {
    height: 1px;
    background: rgba(255, 255, 255, 0.06);
    margin: var(--spacing-sm) var(--spacing-lg);
    list-style: none;
}

.nav-heading {
    padding: var(--spacing-xs) var(--spacing-lg);
    font-size: 0.7rem;
    text-transform: uppercase;
    letter-spacing: 0.1em;
    color: var(--text-muted);
    font-weight: 600;
    list-style: none;
}

/* ============================================
   Page Header with Action
   ============================================ */
.page-header-with-action {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
}

/* ============================================
   Data Tables
   ============================================ */
.table-responsive {
    overflow-x: auto;
}

.data-table {
    width: 100%;
    border-collapse: collapse;
}

.data-table thead th {
    padding: var(--spacing-sm) var(--spacing-md);
    text-align: left;
    font-size: 0.75rem;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    color: var(--text-muted);
    border-bottom: 1px solid rgba(255, 255, 255, 0.06);
    font-weight: 600;
}

.data-table tbody td {
    padding: var(--spacing-sm) var(--spacing-md);
    border-bottom: 1px solid rgba(255, 255, 255, 0.04);
    font-size: 0.875rem;
    vertical-align: middle;
}

.data-table tbody tr:hover {
    background: rgba(255, 255, 255, 0.02);
}

.actions-cell {
    display: flex;
    gap: 0.5rem;
    align-items: center;
}

.empty-state {
    text-align: center;
    color: var(--text-muted);
    padding: var(--spacing-xl) !important;
}

/* ============================================
   Badges
   ============================================ */
.badge {
    display: inline-block;
    padding: 0.2rem 0.6rem;
    font-size: 0.7rem;
    font-weight: 600;
    border-radius: 9999px;
    background: rgba(0, 217, 255, 0.1);
    color: var(--primary-color);
    border: 1px solid rgba(0, 217, 255, 0.2);
    margin-right: 0.25rem;
}

/* ============================================
   Button Variants
   ============================================ */
.btn-sm {
    padding: 0.4rem 0.8rem;
    font-size: 0.75rem;
}

.btn-danger {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    padding: 0.4rem 0.8rem;
    font-size: 0.75rem;
    font-weight: 600;
    border-radius: var(--radius-md);
    cursor: pointer;
    font-family: var(--font-family);
    background: rgba(255, 82, 82, 0.15);
    color: #ff5252;
    border: 1px solid rgba(255, 82, 82, 0.3);
    transition: all var(--transition-fast);
}

.btn-danger:hover {
    background: rgba(255, 82, 82, 0.25);
}

/* ============================================
   Toggle Switch
   ============================================ */
.toggle-label {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: var(--spacing-md);
    font-weight: 500;
}

.toggle-switch {
    position: relative;
    display: inline-block;
    width: 48px;
    height: 26px;
    flex-shrink: 0;
}

.toggle-switch input {
    opacity: 0;
    width: 0;
    height: 0;
}

.toggle-slider {
    position: absolute;
    cursor: pointer;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background-color: rgba(255, 255, 255, 0.1);
    border-radius: 26px;
    transition: 0.3s;
}

.toggle-slider:before {
    position: absolute;
    content: "";
    height: 20px;
    width: 20px;
    left: 3px;
    bottom: 3px;
    background-color: var(--text-secondary);
    border-radius: 50%;
    transition: 0.3s;
}

.toggle-switch input:checked + .toggle-slider {
    background: linear-gradient(135deg, var(--primary-color), var(--accent-color));
}

.toggle-switch input:checked + .toggle-slider:before {
    transform: translateX(22px);
    background-color: var(--dark-bg);
}

/* ============================================
   Form Actions
   ============================================ */
.form-actions {
    display: flex;
    gap: var(--spacing-sm);
    margin-top: var(--spacing-lg);
}

/* ============================================
   Textarea
   ============================================ */
.form-group textarea {
    width: 100%;
    padding: 0.75rem 1rem;
    background: var(--dark-bg);
    border: 1px solid rgba(255, 255, 255, 0.1);
    border-radius: var(--radius-md);
    color: var(--text-primary);
    font-size: 1rem;
    font-family: var(--font-family);
    resize: vertical;
    transition: border-color var(--transition-fast);
}

.form-group textarea:focus {
    outline: none;
    border-color: var(--primary-color);
    box-shadow: 0 0 0 3px rgba(0, 217, 255, 0.1);
}
CSS;

        $this->writeFile('public/assets/css/dashboard.css', $content);
    }

    // =========================================================================
    // Migration Generation
    // =========================================================================

    private function generateMigrations(): void
    {
        $timestamp = date('Y_m_d_His');

        // Users table migration
        $usersContent = <<<'PHP'
<?php

declare(strict_types=1);

use ZephyrPHP\Database\Migration;

class CreateUsersTable extends Migration
{
    public function up(): void
    {
        $this->createTable('users', function ($table) {
            $table->addColumn('id', 'integer', ['autoincrement' => true, 'unsigned' => true]);
            $table->addColumn('name', 'string', ['length' => 255]);
            $table->addColumn('email', 'string', ['length' => 180]);
            $table->addColumn('password', 'string', ['length' => 255]);
            $table->addColumn('remember_token', 'string', ['length' => 100, 'notnull' => false]);
            $table->addColumn('created_at', 'datetime', ['notnull' => false]);
            $table->addColumn('updated_at', 'datetime', ['notnull' => false]);

            $table->setPrimaryKey(['id']);
            $table->addUniqueIndex(['email'], 'users_email_unique');
        });
    }

    public function down(): void
    {
        $this->dropTable('users');
    }
}
PHP;

        $this->writeFile("database/migrations/{$timestamp}_create_users_table.php", $usersContent);

        // Roles tables migration (only if authorization module)
        if ($this->hasAuthorization) {
            $rolesTimestamp = date('Y_m_d_His', strtotime('+1 second'));

            $rolesContent = <<<'PHP'
<?php

declare(strict_types=1);

use ZephyrPHP\Database\Migration;

class CreateRolesTables extends Migration
{
    public function up(): void
    {
        $this->createTable('roles', function ($table) {
            $table->addColumn('id', 'integer', ['autoincrement' => true, 'unsigned' => true]);
            $table->addColumn('name', 'string', ['length' => 100]);
            $table->addColumn('slug', 'string', ['length' => 100]);
            $table->addColumn('description', 'text', ['notnull' => false]);
            $table->addColumn('created_at', 'datetime', ['notnull' => false]);
            $table->addColumn('updated_at', 'datetime', ['notnull' => false]);

            $table->setPrimaryKey(['id']);
            $table->addUniqueIndex(['name'], 'roles_name_unique');
            $table->addUniqueIndex(['slug'], 'roles_slug_unique');
        });

        $this->createTable('role_user', function ($table) {
            $table->addColumn('user_id', 'integer', ['unsigned' => true]);
            $table->addColumn('role_id', 'integer', ['unsigned' => true]);

            $table->setPrimaryKey(['user_id', 'role_id']);
            $table->addForeignKeyConstraint('users', ['user_id'], ['id'], ['onDelete' => 'CASCADE'], 'fk_role_user_user');
            $table->addForeignKeyConstraint('roles', ['role_id'], ['id'], ['onDelete' => 'CASCADE'], 'fk_role_user_role');
        });
    }

    public function down(): void
    {
        $this->dropTable('role_user');
        $this->dropTable('roles');
    }
}
PHP;

            $this->writeFile("database/migrations/{$rolesTimestamp}_create_roles_tables.php", $rolesContent);

            // App settings table
            $settingsTimestamp = date('Y_m_d_His', strtotime('+2 seconds'));

            $settingsContent = <<<'PHP'
<?php

declare(strict_types=1);

use ZephyrPHP\Database\Migration;

class CreateAppSettingsTable extends Migration
{
    public function up(): void
    {
        $this->createTable('app_settings', function ($table) {
            $table->addColumn('id', 'integer', ['autoincrement' => true, 'unsigned' => true]);
            $table->addColumn('setting_key', 'string', ['length' => 100]);
            $table->addColumn('setting_value', 'text', ['notnull' => false]);
            $table->addColumn('created_at', 'datetime', ['notnull' => false]);
            $table->addColumn('updated_at', 'datetime', ['notnull' => false]);

            $table->setPrimaryKey(['id']);
            $table->addUniqueIndex(['setting_key'], 'app_settings_key_unique');
        });
    }

    public function down(): void
    {
        $this->dropTable('app_settings');
    }
}
PHP;

            $this->writeFile("database/migrations/{$settingsTimestamp}_create_app_settings_table.php", $settingsContent);
        }
    }

    // =========================================================================
    // Route Generation
    // =========================================================================

    private function addAuthRoutes(): void
    {
        $routesFile = $this->basePath('routes/web.php');

        if (!file_exists($routesFile)) {
            $this->warning('Routes file not found: routes/web.php');
            $this->line('  Add routes manually.');
            return;
        }

        $content = file_get_contents($routesFile);

        // Check if auth routes already exist
        if (str_contains($content, 'LoginController') || str_contains($content, '/v1/dashboard')) {
            $this->warning('Auth routes already exist in routes/web.php. Skipping.');
            return;
        }

        $ns = $this->namespace;

        $adminImports = '';
        $adminRoutes = '';
        if ($this->hasAuthorization) {
            $adminImports = <<<PHP

use {$ns}\\Controllers\\Dashboard\\AdminController;
use {$ns}\\Controllers\\Dashboard\\UserController;
use {$ns}\\Controllers\\Dashboard\\RoleController;
PHP;

            $adminRoutes = <<<'PHP'

    // Admin settings
    Route::get('/admin/settings', [AdminController::class, 'settings']);
    Route::post('/admin/settings', [AdminController::class, 'updateSettings']);

    // User management
    Route::get('/users', [UserController::class, 'index']);
    Route::get('/users/create', [UserController::class, 'create']);
    Route::post('/users', [UserController::class, 'store']);
    Route::get('/users/{id}/edit', [UserController::class, 'edit']);
    Route::post('/users/{id}', [UserController::class, 'update']);
    Route::post('/users/{id}/delete', [UserController::class, 'destroy']);

    // Role management
    Route::get('/roles', [RoleController::class, 'index']);
    Route::get('/roles/create', [RoleController::class, 'create']);
    Route::post('/roles', [RoleController::class, 'store']);
    Route::get('/roles/{id}/edit', [RoleController::class, 'edit']);
    Route::post('/roles/{id}', [RoleController::class, 'update']);
    Route::post('/roles/{id}/delete', [RoleController::class, 'destroy']);
PHP;
        }

        $routesCode = <<<PHP


// =============================================
// Authentication Routes
// =============================================
use {$ns}\\Controllers\\Auth\\LoginController;
use {$ns}\\Controllers\\Auth\\RegisterController;
use {$ns}\\Controllers\\Dashboard\\DashboardController;
use {$ns}\\Controllers\\Dashboard\\SettingsController;
use ZephyrPHP\\Middleware\\AuthMiddleware;
use ZephyrPHP\\Middleware\\GuestMiddleware;{$adminImports}

// Guest routes (login, register)
Route::group(['middleware' => [GuestMiddleware::class]], function () {
    Route::get('/login', [LoginController::class, 'showLoginForm']);
    Route::post('/login', [LoginController::class, 'login']);
    Route::get('/register', [RegisterController::class, 'showRegisterForm']);
    Route::post('/register', [RegisterController::class, 'store']);
});
Route::post('/logout', [LoginController::class, 'logout']);

// Dashboard routes (authenticated)
Route::group(['prefix' => '/v1/dashboard', 'middleware' => [AuthMiddleware::class]], function () {
    Route::get('/', [DashboardController::class, 'index']);
    Route::get('/settings', [SettingsController::class, 'index']);
    Route::post('/settings/profile', [SettingsController::class, 'updateProfile']);
    Route::post('/settings/password', [SettingsController::class, 'updatePassword']);{$adminRoutes}
});
PHP;

        file_put_contents($routesFile, $content . $routesCode);
        $this->line('  <info>Updated:</info> routes/web.php');
    }
}

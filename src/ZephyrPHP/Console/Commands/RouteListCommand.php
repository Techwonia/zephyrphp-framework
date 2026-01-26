<?php

declare(strict_types=1);

namespace ZephyrPHP\Console\Commands;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'route:list',
    description: 'List all registered routes'
)]
class RouteListCommand extends BaseCommand
{
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->initialize($input, $output);

        $routesFile = $this->basePath('routes/web.php');

        if (!file_exists($routesFile)) {
            $this->error('Routes file not found: routes/web.php');
            return self::FAILURE;
        }

        $this->title('Registered Routes');

        // Parse routes from file
        $content = file_get_contents($routesFile);
        $routes = $this->parseRoutes($content);

        if (empty($routes)) {
            $this->info('No routes found.');
            return self::SUCCESS;
        }

        $this->table(['Method', 'URI', 'Action'], $routes);

        return self::SUCCESS;
    }

    private function parseRoutes(string $content): array
    {
        $routes = [];
        $pattern = '/\$router->(get|post|put|patch|delete|any)\s*\(\s*[\'"]([^\'"]+)[\'"]\s*,\s*(.+?)\s*\)/i';

        if (preg_match_all($pattern, $content, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $method = strtoupper($match[1]);
                $uri = $match[2];
                $action = trim($match[3]);

                // Clean up action
                if (str_contains($action, '::class')) {
                    $action = preg_replace('/\[([^,]+)::class\s*,\s*[\'"]([^\'"]+)[\'"]\]/', '$1@$2', $action);
                } elseif (str_contains($action, 'function')) {
                    $action = 'Closure';
                }

                $routes[] = [$method, $uri, $action];
            }
        }

        return $routes;
    }
}

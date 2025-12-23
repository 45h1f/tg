<?php

namespace Ashiful\Tg\Commands;

use Illuminate\Console\Command;

class GeneratePestTestsCommand extends Command
{
    protected $signature = 'tg:generate {--force} {--dry-run}';
    protected $description = 'Automatically generate Pest test cases for routes, controllers, and models.';

    public function handle()
    {
        $this->info('Starting Pest test generation...');

        $routes = \Illuminate\Support\Facades\Route::getRoutes();
        $filteredRoutes = $this->filterRoutes($routes);

        $this->info('Found ' . count($filteredRoutes) . ' routes to test.');

        $generator = new \Ashiful\Tg\Generators\TestGenerator($this);
        $generator->generate($filteredRoutes);
        
        $this->info('Generating Model tests...');
        $modelGenerator = new \Ashiful\Tg\Generators\ModelTestGenerator($this);
        $modelGenerator->generate();
        
        $this->info('Pest test generation complete.');
    }

    protected function filterRoutes($routes)
    {
        $config = config('tg');
        $allowedMethods = $config['http_methods'] ?? ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'];
        $excludePrefixes = $config['exclude_prefixes'] ?? [];
        $excludeRoutes = $config['exclude_routes'] ?? [];

        return collect($routes)->filter(function ($route) use ($allowedMethods, $excludePrefixes, $excludeRoutes) {
            // Check HTTP Method
            if (!array_intersect($route->methods(), $allowedMethods)) {
                return false;
            }

            // Check Excluded Prefixes
            $uri = $route->uri();
            foreach ($excludePrefixes as $prefix) {
                if (str_starts_with($uri, $prefix)) {
                    return false;
                }
            }

            // Check Excluded Route Names
            $name = $route->getName();
            if ($name) {
                foreach ($excludeRoutes as $pattern) {
                    if (\Illuminate\Support\Str::is($pattern, $name)) {
                        return false;
                    }
                }
            }

            // Exclude closure routes (optional, but usually good for generated tests)
            if (!is_string($route->getAction('uses'))) {
                return false;
            }

            return true;
        })->values();
    }
}

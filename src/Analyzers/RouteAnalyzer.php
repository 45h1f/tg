<?php

namespace Ashiful\Pg\Analyzers;

use Illuminate\Support\Facades\Route;

class RouteAnalyzer
{
    public static function analyze(array $config): array
    {
        // Extract all routes, filter by config
        $routes = collect(Route::getRoutes())
            ->filter(function ($route) use ($config) {
                // Exclude by name or prefix
                $name = $route->getName();
                $uri = $route->uri();
                foreach ($config['exclude_routes'] as $pattern) {
                    if ($name && fnmatch($pattern, $name)) return false;
                }
                foreach ($config['exclude_prefixes'] as $prefix) {
                    if (str_starts_with($uri, $prefix)) return false;
                }
                return in_array(strtoupper($route->methods()[0]), $config['http_methods']);
            })
            ->map(function ($route) {
                return [
                    'name' => $route->getName(),
                    'uri' => $route->uri(),
                    'methods' => $route->methods(),
                    'action' => $route->getActionName(),
                    'middleware' => $route->gatherMiddleware(),
                    'parameters' => $route->parameterNames(),
                ];
            })
            ->values()
            ->all();
        return $routes;
    }
}

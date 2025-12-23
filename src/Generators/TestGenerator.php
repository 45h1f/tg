<?php

namespace Ashiful\Tg\Generators;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use ReflectionClass;
use ReflectionMethod;
use Illuminate\Support\Arr;

class TestGenerator
{
    protected $config;
    protected $console;

    public function __construct($console = null)
    {
        $this->config = config('tg');
        $this->console = $console;
    }

    public function generate($routes)
    {
        $groupedRoutes = $this->groupRoutes($routes);

        foreach ($groupedRoutes as $key => $group) {
            $this->generateTestFile($key, $group);
        }
    }

    protected function groupRoutes($routes)
    {
        if (($this->config['organization'] ?? 'grouped') === 'grouped') {
            return $routes->groupBy(function ($route) {
                $action = $route->getAction('uses');
                if (is_string($action) && str_contains($action, '@')) {
                    [$controller, $method] = explode('@', $action);
                    return $controller;
                }
                return 'Closures';
            });
        }

        return $routes->mapWithKeys(function ($route) {
             $action = $route->getAction('uses');
             return [$action => collect([$route])];
        });
    }

    protected function generateTestFile($groupKey, $routes)
    {
        if ($groupKey === 'Closures') return;

        $className = ltrim($groupKey, '\\');
        $baseNamespace = 'App\\Http\\Controllers\\';
        
        $relativePath = '';
        if (str_starts_with($className, $baseNamespace)) {
            // It is an App Controller -> Keep relative naming (e.g. Auth/LoginController)
            $subPath = substr($className, strlen($baseNamespace));
            $relativePath = str_replace('\\', '/', $subPath);
        } else {
             // It is a Vendor/External Controller -> Use Full Namespace (e.g. Laravel/Fortify/Http/...)
             $relativePath = str_replace('\\', '/', $className);
        }

        $testName = class_basename($className) . 'Test';
        $outputPath = base_path($this->config['output_path'] ?? 'tests/Feature/Generated');
        
        $dir = dirname($relativePath);
        if ($dir !== '.' && $dir !== '') {
             $outputPath .= '/' . $dir;
        }

        if (!File::exists($outputPath)) {
            File::makeDirectory($outputPath, 0755, true);
        }
        
        $filePath = $outputPath . '/' . $testName . '.php';

        if (($this->config['skip_existing'] ?? true) && File::exists($filePath)) {
            if ($this->console) {
                $this->console->warn("Skipping existing file: " . $filePath);
            }
            return;
        }

        $content = $this->buildFileContent($groupKey, $routes);
        
        File::put($filePath, $content);
    }

    protected function getTestName($className)
    {
        if (str_ends_with($className, 'Controller')) {
            return $className . 'Test';
        }
        return $className . 'Test';
    }

    protected function buildFileContent($controllerClass, $routes)
    {
        $content = "<?php\n\n";
        $content .= "use " . $controllerClass . ";\n";
        $content .= "use App\Models\User;\n";
        if ($this->config['use_refresh_database'] ?? true) {
            $content .= "use Illuminate\Foundation\Testing\RefreshDatabase;\n";
        }
        $content .= "\n";
        
        // Add Uses RefreshDatabase if configured, but usually it's in Pest.php or uses() call. 
        // In Pest, we often use `uses(RefreshDatabase::class);` or the trait in the file.
        // Let's use the `uses()` function at the top level if needed, or inside describe.
        // Standard Pest pattern is just `uses(RefreshDatabase::class);` at top.
        
        if ($this->config['use_refresh_database'] ?? true) {
             $content .= "uses(RefreshDatabase::class);\n\n";
        }

        foreach ($routes as $route) {
            $content .= $this->generateTestMethod($route);
        }

        return $content;
    }

    protected function generateTestMethod($route)
    {
        $statements = [];
        
        $action = $route->getAction();
        $uses = $action['uses'] ?? null;
        if (!is_string($uses)) return ""; 
        
        [$controllerClass, $methodName] = explode('@', $uses);
        
        $middlewares = $route->gatherMiddleware();
        $isAuth = in_array('auth', $middlewares) || in_array('auth:sanctum', $middlewares);
        
        // 1. Success Test
        $statements[] = $this->buildSuccessTest($route, $isAuth, $methodName, $controllerClass);
        
        // 2. Guest Test
        if ($isAuth && ($this->config['generate_authorization_tests'] ?? true)) {
             $statements[] = $this->buildGuestTest($route, $methodName);
        }
        
        // 3. Validation Test
        if ($this->config['generate_validation_tests'] ?? true) {
             $validationTest = $this->buildValidationTest($controllerClass, $methodName, $route, $isAuth);
             if ($validationTest) $statements[] = $validationTest;
        }

        return implode("\n", $statements);
    }

    protected function buildSuccessTest($route, $isAuth, $methodName, $controllerClass)
    {
        $httpMethod = $route->methods()[0];
        $routeName = $route->getName();
        $uri = $route->uri();
        
        $testName = $isAuth ? "authenticated user can {$methodName}" : "user can {$methodName}";
        
        $prefix = $this->config['templates']['test_method_prefix'] ?? 'test';
        $code = "$prefix('$testName', function () {\n";
        
        if ($isAuth) {
            $code .= "    \$user = User::factory()->create();\n";
        }

        $routeCall = $this->buildRouteCall($route);

        $isUnsafe = in_array($httpMethod, ['POST', 'PUT', 'PATCH', 'DELETE']);
        $isSensitive = str_contains($controllerClass, 'Auth') || 
                       str_contains($controllerClass, 'TwoFactor') || 
                       str_contains($controllerClass, 'Verify') ||
                       str_contains($controllerClass, 'Verification') ||
                       str_contains($controllerClass, 'Confirmable') ||
                       str_contains($controllerClass, 'Confirmed') ||
                       str_contains($controllerClass, 'Recovery') ||
                       str_contains($controllerClass, 'Redirect');

        if ($isUnsafe || $isSensitive) {
            if ($isAuth) {
                $code .= "    \$response = \$this->actingAs(\$user)->{$this->lowerMethod($httpMethod)}($routeCall);\n";
            } else {
                $code .= "    \$response = \$this->{$this->lowerMethod($httpMethod)}($routeCall);\n";
            }
            $code .= "    \$response->assertStatus(200);\n";
            $code .= "})->todo();\n";
        } else {
            if ($isAuth) {
                $code .= "    \$response = \$this->actingAs(\$user)->{$this->lowerMethod($httpMethod)}($routeCall);\n";
            } else {
                $code .= "    \$response = \$this->{$this->lowerMethod($httpMethod)}($routeCall);\n";
            }
            $code .= "    \$response->assertStatus(200);\n";
        
            // Blade/Inertia Assertions
            $assertion = $this->detectViewAssertion($controllerClass, $methodName);
            if ($assertion) {
                $code .= "    $assertion\n";
            }
            $code .= "});\n";
        }
        
        return $code;
    }

    protected function detectViewAssertion($controllerClass, $methodName)
    {
        try {
            $reflection = new ReflectionMethod($controllerClass, $methodName);
            $startLine = $reflection->getStartLine();
            $endLine = $reflection->getEndLine();
            $file = $reflection->getFileName();
            
            if (!$file || !File::exists($file)) return null;
            
            $content = File::get($file);
            $lines = explode("\n", $content);
            $methodContent = implode("\n", array_slice($lines, $startLine - 1, $endLine - $startLine + 1));
            
            if (($this->config['inertia_assertions'] ?? true) && str_contains($methodContent, 'Inertia::render')) {
                // Try to extract component name
                if (preg_match('/Inertia::render\([\'"]([^\'"]+)[\'"]/', $methodContent, $matches)) {
                    $component = $matches[1];
                    return "\$response->assertInertia(fn(\$page) => \$page->component('$component'));";
                }
                return "\$response->assertInertia();";
            }
            
            if (($this->config['blade_assertions'] ?? true) && (str_contains($methodContent, 'view(') || str_contains($methodContent, 'View::make'))) {
                 if (preg_match('/view\([\'"]([^\'"]+)[\'"]/', $methodContent, $matches)) {
                    $view = $matches[1];
                     return "\$response->assertViewIs('$view');";
                 }
                 return "\$response->assertViewIs('TODO: fill view name');";
            }
            
        } catch (\Exception $e) {
            // ignore
        }
        return null;
    }

    protected function buildGuestTest($route, $methodName)
    {
        $httpMethod = $route->methods()[0];
        $routeCall = $this->buildRouteCall($route);
        
        $testName = "guest cannot {$methodName}";
        
        $prefix = $this->config['templates']['test_method_prefix'] ?? 'test';
        $code = "$prefix('$testName', function () {\n";
        $code .= "    \$response = \$this->{$this->lowerMethod($httpMethod)}($routeCall);\n";
        $code .= "    \$response->assertRedirect(route('login'));\n"; 
        $code .= "});\n";

        return $code;
    }

    protected function buildValidationTest($controllerClass, $methodName, $route, $isAuth)
    {
        $httpMethod = $route->methods()[0];
        if (in_array($httpMethod, ['GET', 'HEAD', 'OPTIONS'])) {
            return null;
        }

        try {
            $reflection = new ReflectionMethod($controllerClass, $methodName);
            $params = $reflection->getParameters();
            foreach ($params as $param) {
                $type = $param->getType();
                if ($type && !$type->isBuiltin()) {
                    $typeName = $type->getName();
                    if (is_subclass_of($typeName, 'Illuminate\Foundation\Http\FormRequest')) {
                        return $this->generateValidationTestCode($route, $isAuth, $methodName);
                    }
                }
            }
        } catch (\Exception $e) {
        }
        return null;
    }

    protected function generateValidationTestCode($route, $isAuth, $methodName)
    {
        $httpMethod = $route->methods()[0];
        $routeCall = $this->buildRouteCall($route);
        
        $testName = "{$methodName} validates required fields";
        
        $prefix = $this->config['templates']['test_method_prefix'] ?? 'test';
        $code = "$prefix('$testName', function () {\n";
        if ($isAuth) {
            $code .= "    \$user = User::factory()->create();\n";
            $code .= "    \$response = \$this->actingAs(\$user)->{$this->lowerMethod($httpMethod)}($routeCall, []);\n";
        } else {
            $code .= "    \$response = \$this->{$this->lowerMethod($httpMethod)}($routeCall, []);\n";
        }
        $code .= "    \$response->assertSessionHasErrors();\n";
        $code .= "});\n";
        
        return $code;
    }

    protected function buildRouteCall($route)
    {
        $routeName = $route->getName();
        $uri = $route->uri();
        $params = $route->parameterNames(); 

        if ($routeName) {
            if (empty($params)) {
                return "route('{$routeName}')";
            } else {
                $paramStr = [];
                foreach($params as $param) {
                     // Try to match param to model
                     $modelClass = "App\\Models\\" . Str::studly($param);
                     if (class_exists($modelClass)) {
                         // We can't easily create the factory inline safely without knowing if it's imported
                         // So we'll use a placeholder or assume IDs. 
                         // Better: return route('name', ['param' => 1])
                         $paramStr[] = "'$param' => 1";
                     } else {
                         $paramStr[] = "'$param' => 'value'";
                     }
                }
                $paramsArg = implode(", ", $paramStr);
                return "route('{$routeName}', [{$paramsArg}])";
            }
        }
        
        // Handle URI params for non-named routes?
        return "'/{$uri}'";
    }

    protected function lowerMethod($method) {
        return strtolower($method);
    }
}

<?php

namespace Ashiful\Tg\Generators;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use ReflectionClass;

class ModelTestGenerator
{
    protected $config;
    protected $console;

    public function __construct($console = null)
    {
        $this->config = config('tg');
        $this->console = $console;
    }

    public function generate()
    {
        if (!($this->config['model_tests']['enabled'] ?? false)) {
            return;
        }

        $models = $this->getModels();
        
        foreach ($models as $model) {
            $this->generateModelTest($model);
        }
    }

    protected function getModels()
    {
        $modelsPath = app_path('Models');
        if (!File::exists($modelsPath)) return [];

        $files = File::allFiles($modelsPath);
        $models = [];

        foreach ($files as $file) {
            $className = 'App\\Models\\' . $file->getFilenameWithoutExtension();
            if (class_exists($className)) {
                $models[] = $className;
            }
        }
        
        return $models;
    }

    protected function generateModelTest($modelClass)
    {
        $className = class_basename($modelClass);
        $testName = $className . 'Test';
        $outputPath = base_path($this->config['model_tests']['output_path'] ?? 'tests/Unit/Models');
        
        if (!File::exists($outputPath)) {
            File::makeDirectory($outputPath, 0755, true);
        }
        
        $filePath = $outputPath . '/' . $testName . '.php';

        if (($this->config['skip_existing'] ?? true) && File::exists($filePath)) {
            if ($this->console) {
                $this->console->warn("Skipping existing file: " . $testName . '.php');
            }
            return;
        }

        $content = $this->buildModelTestContent($modelClass, $className);
        
        File::put($filePath, $content);
    }

    protected function buildModelTestContent($modelClass, $className)
    {
        $content = "<?php\n\n";
        $content .= "use $modelClass;\n";
        $content .= "use Illuminate\Foundation\Testing\RefreshDatabase;\n";
        $content .= "use Tests\TestCase;\n\n";
        $content .= "uses(TestCase::class);\n";
        $content .= "uses(RefreshDatabase::class);\n\n";
        
        $prefix = $this->config['templates']['test_method_prefix'] ?? 'test';
        
        // Factory Test
        if ($this->config['model_tests']['test_factories'] ?? true) {
             // Check if model has factory
             if (method_exists($modelClass, 'factory')) {
                 $content .= "$prefix('$className has valid factory', function () {\n";
                 $content .= "    \$model = $className::factory()->create();\n";
                 $content .= "    expect(\$model)->toBeInstanceOf($className::class);\n";
                 $content .= "});\n\n";
             }
        }

        // Fillable/Casts Test (Basic)
        if ($this->config['model_tests']['test_casts'] ?? true) {
             $content .= "$prefix('$className has expected fillables/casts', function () {\n";
             $content .= "    \$model = new $className();\n";
             $content .= "    // Verify fillables or casts here if inspection needed\n";
             $content .= "    expect(\$model)->toBeInstanceOf($className::class);\n";
             $content .= "});\n\n";
        }
        
        return $content;
    }
}

<?php

namespace Ashiful\Pg\Analyzers;

class ControllerAnalyzer
{
    public static function analyze(string $controllerClass): array
    {
        // TODO: Use nikic/php-parser to analyze controller methods, validation, response type
        return [
            'class' => $controllerClass,
            'methods' => [], // To be filled with method analysis
        ];
    }
}

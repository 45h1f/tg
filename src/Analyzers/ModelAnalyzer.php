<?php

namespace Ashiful\Pg\Analyzers;

class ModelAnalyzer
{
    public static function analyze(string $modelClass): array
    {
        // TODO: Reflect model properties, relationships, casts
        return [
            'class' => $modelClass,
            'fillable' => [],
            'casts' => [],
            'relationships' => [],
        ];
    }
}

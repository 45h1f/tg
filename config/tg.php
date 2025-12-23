<?php

return [
    'output_path' => 'tests/Feature/Generated',
    'naming_convention' => 'controller',
    'organization' => 'grouped',
    'exclude_routes' => [
        'sanctum.*',
        'ignition.*',
        '_debugbar.*',
    ],
    'exclude_prefixes' => [
        'telescope',
        'horizon',
        'nova',
    ],
    'http_methods' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'],
    'test_middleware' => true,
    'generate_validation_tests' => true,
    'generate_authorization_tests' => true,
    'inertia_assertions' => true,
    'blade_assertions' => true,
    'use_refresh_database' => true,
    'use_factories' => true,
    'skip_existing' => true,
    'templates' => [
        'test_method_prefix' => 'test',
        'use_type_hints' => true,
        'use_strict_types' => true,
    ],
    'model_tests' => [
        'enabled' => true,
        'test_factories' => true,
        'test_relationships' => true,
        'test_casts' => true,
        'output_path' => 'tests/Unit/Models',
    ],
];

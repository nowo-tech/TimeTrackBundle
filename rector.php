<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;
use Rector\Php55\Rector\String_\StringClassNameToClassConstantRector;

return RectorConfig::configure()
    ->withPaths([__DIR__ . '/src', __DIR__ . '/tests'])
    ->withSkip([
        __DIR__ . '/demo',
        __DIR__ . '/tests/App/var',
        // Keep example FQCN as string — App\Entity\User is a host class, not in this package.
        StringClassNameToClassConstantRector::class => [
            __DIR__ . '/src/DependencyInjection/Configuration.php',
        ],
    ])
    ->withPhpSets(php82: true)
    ->withPreparedSets(
        deadCode: true,
        codeQuality: true,
        typeDeclarations: true,
    );

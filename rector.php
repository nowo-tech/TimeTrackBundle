<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;
use Rector\DeadCode\Rector\ClassMethod\RemoveEmptyClassMethodRector;
use Rector\DeadCode\Rector\ClassMethod\RemoveUnusedConstructorParamRector;
use Rector\DeadCode\Rector\ClassMethod\RemoveUnusedPromotedPropertyRector;
use Rector\DeadCode\Rector\ClassMethod\RemoveUnusedPublicMethodParameterRector;
use Rector\DeadCode\Rector\Property\RemoveUnusedPrivatePropertyRector;
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
        // 1.27.0 (Beacon spec 239): public signatures kept for compatibility (no BC break in a minor) although the
        // registry is no longer used (a closed manager is replaced at the unit-of-work boundary, never mid-request).
        RemoveUnusedPublicMethodParameterRector::class => [__DIR__ . '/src/Doctrine/RecoveringFlusher.php'],
        RemoveEmptyClassMethodRector::class            => [__DIR__ . '/src/Doctrine/RecoveringFlusher.php'],
        RemoveUnusedConstructorParamRector::class      => $kept = [
            __DIR__ . '/src/Repository/DoctrineOrmMonitoringAcknowledgementStore.php',
            __DIR__ . '/src/Repository/DoctrineOrmMonitoringUploadIntentRepository.php',
        ],
        RemoveUnusedPromotedPropertyRector::class => $kept,
        RemoveUnusedPrivatePropertyRector::class  => $kept,
    ])
    ->withPhpSets(php82: true)
    ->withPreparedSets(
        deadCode: true,
        codeQuality: true,
        typeDeclarations: true,
    );

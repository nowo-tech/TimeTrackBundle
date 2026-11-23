<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;
use Rector\DeadCode\Rector\ClassMethod\RemoveEmptyClassMethodRector;
use Rector\DeadCode\Rector\ClassMethod\RemoveUnusedConstructorParamRector;
use Rector\DeadCode\Rector\ClassMethod\RemoveUnusedPromotedPropertyRector;
use Rector\DeadCode\Rector\ClassMethod\RemoveUnusedPublicMethodParameterRector;
use Rector\DeadCode\Rector\Property\RemoveUnusedPrivatePropertyRector;
use Rector\Php55\Rector\String_\StringClassNameToClassConstantRector;

$kept = [
    __DIR__ . '/src/Repository/DoctrineOrmMonitoringAcknowledgementStore.php',
    __DIR__ . '/src/Repository/DoctrineOrmMonitoringUploadIntentRepository.php',
];

return RectorConfig::configure()
    ->withPaths([__DIR__ . '/src', __DIR__ . '/tests'])
    ->withSkip([
        __DIR__ . '/demo',
        __DIR__ . '/tests/App/var',
        // 2.0 W1-1 fixtures: classes that implement the removed host port interfaces on purpose (they cannot be loaded).
        __DIR__ . '/tests/Fixtures/RemovedPorts',
        // Keep example FQCN as string — App\Entity\User is a host class, not in this package.
        StringClassNameToClassConstantRector::class => [
            __DIR__ . '/src/DependencyInjection/Configuration.php',
        ],
        // 1.27.0 (Beacon spec 239): public signatures kept for compatibility (no BC break in a minor) although the
        // registry is no longer used (a closed manager is replaced at the unit-of-work boundary, never mid-request).
        RemoveUnusedPublicMethodParameterRector::class => [__DIR__ . '/src/Doctrine/RecoveringFlusher.php'],
        RemoveEmptyClassMethodRector::class            => [__DIR__ . '/src/Doctrine/RecoveringFlusher.php'],
        RemoveUnusedConstructorParamRector::class      => [
            ...$kept,
            // 2.0: arguments of the removed 1.x modes kept in public signatures, ignored and deprecated (removed in 3.0).
            __DIR__ . '/src/Client/ClientResponseFactory.php',
            __DIR__ . '/src/Controller/TimeTrackClientApiController.php',
            __DIR__ . '/src/Repository/DoctrineOrmActiveTimerRepository.php',
            __DIR__ . '/src/Repository/DoctrineOrmBalanceLedgerRepository.php',
            __DIR__ . '/src/Repository/DoctrineOrmCalendarRepository.php',
            __DIR__ . '/src/Repository/DoctrineOrmClientTokenRepository.php',
            __DIR__ . '/src/Repository/DoctrineOrmDeviceAuthorizationRepository.php',
            __DIR__ . '/src/Repository/DoctrineOrmTimeEntryRepository.php',
            __DIR__ . '/src/Repository/DoctrineOrmTimesheetRepository.php',
            __DIR__ . '/src/Transaction/TimeTrackTransactionRunner.php',
            __DIR__ . '/src/Twig/TimeTrackTwigExtension.php',
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

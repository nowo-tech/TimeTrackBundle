<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Unit\DependencyInjection;

use App\Entity\User;
use Nowo\TimeTrackBundle\DependencyInjection\TimeTrackExtension;
use Nowo\TimeTrackBundle\Import\ImportedTimeEntryWriter;
use Nowo\TimeTrackBundle\Repository\ImportedTimeEntryRepositoryInterface;
use Nowo\TimeTrackBundle\Repository\ImportedTimeEntryRepositoryResolver;
use Nowo\TimeTrackBundle\Repository\TimeEntryRepositoryInterface;
use Nowo\TimeTrackBundle\Retention\TimeEntryRetentionPolicy;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\SecurityBundle;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Since 1.11: `imports` and `retention` configuration and the imported-entry services.
 */
final class ImportsExtensionTest extends TestCase
{
    public function testDefaults(): void
    {
        $container = $this->load([]);

        self::assertSame(2_678_400, $container->getParameter('nowo_time_track.imports.max_entry_seconds'));
        self::assertFalse($container->getParameter('nowo_time_track.retention.include_imported'));
        $imported = $container->getDefinition(ImportedTimeEntryRepositoryInterface::class);
        self::assertFalse($container->hasAlias(ImportedTimeEntryRepositoryInterface::class));
        self::assertSame([ImportedTimeEntryRepositoryResolver::class, 'resolve'], $imported->getFactory());
        self::assertSame(TimeEntryRepositoryInterface::class, (string) $imported->getArgument(0));
        self::assertSame(2_678_400, $container->getDefinition(ImportedTimeEntryWriter::class)->getArgument('$maxEntrySeconds'));
        self::assertFalse($container->getDefinition(TimeEntryRetentionPolicy::class)->getArgument('$includeImported'));
    }

    public function testConfiguredValues(): void
    {
        $container = $this->load(['imports' => ['max_entry_seconds' => 86_400], 'retention' => ['include_imported' => true]]);

        self::assertSame(86_400, $container->getDefinition(ImportedTimeEntryWriter::class)->getArgument('$maxEntrySeconds'));
        self::assertTrue($container->getDefinition(TimeEntryRetentionPolicy::class)->getArgument('$includeImported'));
    }

    /**
     * @param array<string, mixed> $config
     */
    private function load(array $config): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.environment', 'test');
        $container->setParameter('kernel.bundles', ['SecurityBundle' => SecurityBundle::class]);
        (new TimeTrackExtension())->load([['user_class' => User::class] + $config], $container);

        return $container;
    }
}

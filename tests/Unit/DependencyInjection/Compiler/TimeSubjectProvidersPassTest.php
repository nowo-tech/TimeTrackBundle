<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Unit\DependencyInjection\Compiler;

use App\TimeTrack\HostBadTypeSubjectProvider;
use App\TimeTrack\HostClientSubjectProvider;
use App\TimeTrack\HostOtherClientSubjectProvider;
use LogicException;
use Nowo\TimeTrackBundle\DependencyInjection\Compiler\TimeSubjectProvidersPass;
use Nowo\TimeTrackBundle\Subject\ActivityTypeProviderInterface;
use Nowo\TimeTrackBundle\Subject\EmptySubjectProviders;
use Nowo\TimeTrackBundle\Subject\ScopeTimeSubjectProvider;
use Nowo\TimeTrackBundle\Subject\TimeSubjectRegistry;
use PHPUnit\Framework\TestCase;
use stdClass;
use Symfony\Component\DependencyInjection\Argument\ServiceClosureArgument;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;

use function array_keys;

require_once __DIR__ . '/../../../Fixtures/OutsideNamespace/HostSubjectProviderBase.php';
require_once __DIR__ . '/../../../Fixtures/OutsideNamespace/HostClientSubjectProvider.php';
require_once __DIR__ . '/../../../Fixtures/OutsideNamespace/HostOtherClientSubjectProvider.php';
require_once __DIR__ . '/../../../Fixtures/OutsideNamespace/HostBadTypeSubjectProvider.php';

/**
 * Since 2.1.0 (Beacon spec 256): tagged subject providers become a locator and a constant `type => allowsTask` map.
 */
final class TimeSubjectProvidersPassTest extends TestCase
{
    public function testWithoutRegistryOnlyTheActivitySlotIsSet(): void
    {
        $container = new ContainerBuilder();
        $container->register('app.activity_types', stdClass::class);
        $container->setAlias(ActivityTypeProviderInterface::class, 'app.activity_types');
        $container->register('app.client', HostBadTypeSubjectProvider::class)->addTag(TimeSubjectProvidersPass::TAG);

        (new TimeSubjectProvidersPass())->process($container);

        self::assertSame(ActivityTypeProviderInterface::class, (string) $container->getAlias(TimeSubjectProvidersPass::ACTIVITY_TYPES_SLOT));
        self::assertFalse($container->getAlias(TimeSubjectProvidersPass::ACTIVITY_TYPES_SLOT)->isPublic());
    }

    public function testAnActivityDefinitionAlsoFillsTheSlot(): void
    {
        $container = new ContainerBuilder();
        $container->register(ActivityTypeProviderInterface::class, stdClass::class);

        (new TimeSubjectProvidersPass())->process($container);

        self::assertTrue($container->hasAlias(TimeSubjectProvidersPass::ACTIVITY_TYPES_SLOT));
    }

    public function testNoActivityProviderNoSlot(): void
    {
        $container = $this->container();

        (new TimeSubjectProvidersPass())->process($container);

        self::assertFalse($container->hasAlias(TimeSubjectProvidersPass::ACTIVITY_TYPES_SLOT));
        self::assertSame([], $container->getDefinition(TimeSubjectRegistry::class)->getArgument('$allowsTask'));
    }

    public function testTaggedProvidersBecomeALocatorAndATaskMap(): void
    {
        $container = $this->container();
        $container->setParameter('app.client_class', HostClientSubjectProvider::class);
        $container->register('app.client', '%app.client_class%')->addTag(TimeSubjectProvidersPass::TAG, ['type' => 'ignored_for_hosts']);
        $container->register(ScopeTimeSubjectProvider::class, ScopeTimeSubjectProvider::class)->addTag(TimeSubjectProvidersPass::TAG, ['type' => 'tenant']);

        (new TimeSubjectProvidersPass())->process($container);

        $registry = $container->getDefinition(TimeSubjectRegistry::class);
        self::assertSame(['client' => true, 'tenant' => false], $registry->getArgument('$allowsTask'));
        $locatorRef = $registry->getArgument('$providers');
        self::assertInstanceOf(Reference::class, $locatorRef);
        $locator = $container->getDefinition((string) $locatorRef);
        $map     = $locator->getArgument(0);
        self::assertIsArray($map);
        self::assertSame(['client', 'tenant'], array_keys($map));
        self::assertInstanceOf(ServiceClosureArgument::class, $map['client']);
        self::assertSame('app.client', (string) $map['client']->getValues()[0]);
    }

    public function testTheBuiltInProviderWithoutTypeUsesItsDefault(): void
    {
        $container = $this->container();
        $container->register('scope', ScopeTimeSubjectProvider::class)->addTag(TimeSubjectProvidersPass::TAG);

        (new TimeSubjectProvidersPass())->process($container);

        self::assertSame([ScopeTimeSubjectProvider::DEFAULT_TYPE => false], $container->getDefinition(TimeSubjectRegistry::class)->getArgument('$allowsTask'));
    }

    public function testAnUnknownClassFails(): void
    {
        $container = $this->container();
        $container->register('app.ghost', 'App\\TimeTrack\\DoesNotExist')->addTag(TimeSubjectProvidersPass::TAG);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('"app.ghost" must be a service of a known class');
        (new TimeSubjectProvidersPass())->process($container);
    }

    public function testAClassThatIsNotAProviderFails(): void
    {
        $container = $this->container();
        $container->register('app.not_provider', stdClass::class)->addTag(TimeSubjectProvidersPass::TAG);

        $this->expectException(LogicException::class);
        (new TimeSubjectProvidersPass())->process($container);
    }

    public function testAFactoryFails(): void
    {
        $container = $this->container();
        $container->register('app.factory', HostClientSubjectProvider::class)->setFactory([HostClientSubjectProvider::class, 'make'])->addTag(TimeSubjectProvidersPass::TAG);

        $this->expectException(LogicException::class);
        (new TimeSubjectProvidersPass())->process($container);
    }

    public function testAClasslessServiceUsesItsIdAsClass(): void
    {
        $container = $this->container();
        $container->setDefinition(HostClientSubjectProvider::class, new Definition())->addTag(TimeSubjectProvidersPass::TAG);

        (new TimeSubjectProvidersPass())->process($container);

        self::assertSame(['client' => true], $container->getDefinition(TimeSubjectRegistry::class)->getArgument('$allowsTask'));
    }

    public function testABadTypeFails(): void
    {
        $container = $this->container();
        $container->register('app.bad', HostBadTypeSubjectProvider::class)->addTag(TimeSubjectProvidersPass::TAG);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('answers for the type "Bad Type"');
        (new TimeSubjectProvidersPass())->process($container);
    }

    public function testABadTypeOnTheBuiltInTagFails(): void
    {
        $container = $this->container();
        $container->register('scope', ScopeTimeSubjectProvider::class)->addTag(TimeSubjectProvidersPass::TAG, ['type' => 'Bad']);

        $this->expectException(LogicException::class);
        (new TimeSubjectProvidersPass())->process($container);
    }

    public function testTwoProvidersForOneTypeFail(): void
    {
        $container = $this->container();
        $container->register('app.client', HostClientSubjectProvider::class)->addTag(TimeSubjectProvidersPass::TAG);
        $container->register('app.client2', HostOtherClientSubjectProvider::class)->addTag(TimeSubjectProvidersPass::TAG);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('two time subject providers answer for the type "client" ("app.client" and "app.client2")');
        (new TimeSubjectProvidersPass())->process($container);
    }

    public function testRequiredAllocationWithoutTaskAndNoProviderFails(): void
    {
        $container = $this->container(true, true);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('entries.require_allocation is true with entries.allow_without_task true');
        (new TimeSubjectProvidersPass())->process($container);
    }

    public function testRequiredAllocationIsFineWithAProviderOrWithTasksOnly(): void
    {
        $withProvider = $this->container(true, true);
        $withProvider->register('app.client', HostClientSubjectProvider::class)->addTag(TimeSubjectProvidersPass::TAG);
        (new TimeSubjectProvidersPass())->process($withProvider);

        $tasksOnly = $this->container(true, false);
        (new TimeSubjectProvidersPass())->process($tasksOnly);

        $notRequired = $this->container(false, true);
        (new TimeSubjectProvidersPass())->process($notRequired);

        self::assertSame(['client' => true], $withProvider->getDefinition(TimeSubjectRegistry::class)->getArgument('$allowsTask'));
        self::assertSame([], $tasksOnly->getDefinition(TimeSubjectRegistry::class)->getArgument('$allowsTask'));
        self::assertSame([], $notRequired->getDefinition(TimeSubjectRegistry::class)->getArgument('$allowsTask'));
    }

    /**
     * A container with the registry and, optionally, the entries parameters.
     */
    private function container(?bool $requireAllocation = null, ?bool $allowWithoutTask = null): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->register(TimeSubjectRegistry::class, TimeSubjectRegistry::class)->setArguments(['$providers' => new Definition(EmptySubjectProviders::class), '$allowsTask' => []]);
        if ($requireAllocation !== null) {
            $container->setParameter('nowo_time_track.entries.require_allocation', $requireAllocation);
        }
        if ($allowWithoutTask !== null) {
            $container->setParameter('nowo_time_track.entries.allow_without_task', $allowWithoutTask);
        }

        return $container;
    }
}

<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Unit\DependencyInjection\Compiler;

use LogicException;
use Nowo\TimeTrackBundle\Client\ConfigDeviceScopeFamilyProvider;
use Nowo\TimeTrackBundle\Client\OpaqueClientCredentialIssuer;
use Nowo\TimeTrackBundle\DependencyInjection\Compiler\ScopeFamilyMinterPass;
use Nowo\TimeTrackBundle\DependencyInjection\TimeTrackExtension;
use Nowo\TimeTrackBundle\Tests\Stub\RecordingFamilyCredentialMinter;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;

/**
 * Since 2.2.0 (audit ZBV-I1): a non-time scope family needs a family-aware credential minter, or the build fails.
 */
final class ScopeFamilyMinterPassTest extends TestCase
{
    public function testNothingToCheckWithoutTheDeviceFlowOrFamilies(): void
    {
        $this->process($this->container(false, [], null));
        $this->process($this->container(true, [], null));
        $container = new ContainerBuilder();
        $container->setParameter('nowo_time_track.client_api.device_flow.enabled', true);
        $this->process($container);
        self::addToAssertionCount(1);
    }

    public function testAFamilyAwareMinterPasses(): void
    {
        $container = $this->container(true, ['chat' => ['label' => 'Chat', 'scopes' => ['chat:read' => 'Read']]], 'app.minter');
        $container->setDefinition('app.minter', new Definition(RecordingFamilyCredentialMinter::class));
        $this->process($container);

        // A service whose class cannot be known is left to the runtime check.
        $container = $this->container(true, ['chat' => ['label' => 'Chat', 'scopes' => ['chat:read' => 'Read']]], 'app.unknown');
        $this->process($container);
        self::addToAssertionCount(1);
    }

    public function testTheOpaqueDefaultFailsWithAConfiguredFamily(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('client_api.credential_issuer is not set');
        $this->process($this->container(true, ['chat' => ['label' => 'Chat', 'scopes' => ['chat:read' => 'Read']]], null));
    }

    public function testAPlainMinterFailsWithATaggedProvider(): void
    {
        $container = $this->container(true, [], 'app.minter');
        $container->setDefinition('app.minter', new Definition(OpaqueClientCredentialIssuer::class));
        $container->setDefinition('app.families', (new Definition('App\\ChatFamilies'))->addTag(TimeTrackExtension::SCOPE_FAMILY_PROVIDER_TAG));

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('does not implement');
        $this->process($container);
    }

    /**
     * @param array<string, mixed> $families
     */
    private function container(bool $deviceFlow, array $families, ?string $issuer): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setParameter('nowo_time_track.client_api.device_flow.enabled', $deviceFlow);
        $container->setParameter('nowo_time_track.client_api.device_flow.scope_families', $families);
        $container->setParameter(ScopeFamilyMinterPass::ISSUER_PARAMETER, $issuer);
        $container->setDefinition(ConfigDeviceScopeFamilyProvider::class, (new Definition(ConfigDeviceScopeFamilyProvider::class))->addTag(TimeTrackExtension::SCOPE_FAMILY_PROVIDER_TAG));

        return $container;
    }

    private function process(ContainerBuilder $container): void
    {
        (new ScopeFamilyMinterPass())->process($container);
    }
}

<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Unit\DependencyInjection;

use Doctrine\Bundle\DoctrineBundle\DependencyInjection\DoctrineExtension;
use Nowo\TimeTrackBundle\DependencyInjection\TimeTrackExtension;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * 1.23.0 (REQ-ARCH-001 rule 2): `database.mapping_type` and `user_mapping` choose the Doctrine mappings the bundle
 * prepends; the defaults keep the 1.22 attribute mapping only.
 */
final class MappingOptionsTest extends TestCase
{
    public function testTheDefaultIsTheAttributeMappingOnly(): void
    {
        self::assertSame(['TimeTrackBundle' => ['type' => 'attribute', 'is_bundle' => true]], $this->mappings([]));
    }

    public function testXmlAndTheIdUserMapping(): void
    {
        $mappings = $this->mappings([['database' => ['mapping_type' => 'attribute']], ['database' => ['mapping_type' => 'xml'], 'user_mapping' => 'id']]);

        self::assertSame(['type' => 'xml', 'is_bundle' => true], $mappings['TimeTrackBundle'] ?? null);
        self::assertSame('xml', $mappings['TimeTrackHostUsers']['type'] ?? null);
        self::assertStringEndsWith('Resources/config/doctrine-host-users', (string) ($mappings['TimeTrackHostUsers']['dir'] ?? ''));
        self::assertSame('Nowo\\TimeTrackBundle\\UserRef\\Entity', $mappings['TimeTrackHostUsers']['prefix'] ?? null);

        $attribute = $this->mappings([['user_mapping' => 'id'], ['database' => ['mapping_type' => 'nonsense'], 'user_mapping' => 'nonsense']]);
        self::assertSame('attribute', $attribute['TimeTrackHostUsers']['type'] ?? null, 'unknown values keep the last valid one');
        self::assertStringEndsWith('UserRef/Entity', (string) ($attribute['TimeTrackHostUsers']['dir'] ?? ''));
    }

    /**
     * @param list<array<string, mixed>> $configs
     *
     * @return array<string, array<string, mixed>>
     */
    private function mappings(array $configs): array
    {
        $container = new ContainerBuilder();
        $container->registerExtension(new DoctrineExtension());
        $container->registerExtension(new TimeTrackExtension());
        foreach ($configs as $config) {
            $container->loadFromExtension('nowo_time_track', $config);
        }
        (new TimeTrackExtension())->prepend($container);

        $mappings = [];
        foreach ($container->getExtensionConfig('doctrine') as $config) {
            foreach ($config['orm']['mappings'] ?? [] as $name => $mapping) {
                $mappings[$name] = $mapping;
            }
        }

        return $mappings;
    }
}

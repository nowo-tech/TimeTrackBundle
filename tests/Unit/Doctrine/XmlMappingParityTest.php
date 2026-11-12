<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Unit\Doctrine;

use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Mapping\Driver\AttributeDriver;
use Doctrine\ORM\Mapping\Driver\SimplifiedXmlDriver;
use Doctrine\Persistence\Mapping\Driver\MappingDriver;
use Doctrine\Persistence\Mapping\RuntimeReflectionService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

use function basename;
use function dirname;
use function glob;
use function is_array;
use function is_bool;
use function is_scalar;
use function ksort;
use function sort;

/**
 * 1.23.0 (REQ-ARCH-001 rule 2, `database.mapping_type: xml`): the shipped XML mapping of every bundle entity equals its
 * attribute mapping (columns, types, lengths, options, nullability, enums, version, identifiers, indexes, unique
 * constraints, associations and join columns), and every entity has one.
 */
final class XmlMappingParityTest extends TestCase
{
    private const SETS = [
        'Nowo\\TimeTrackBundle\\Entity'          => ['src/Entity', 'src/Resources/config/doctrine'],
        'Nowo\\TimeTrackBundle\\UserRef\\Entity' => ['src/UserRef/Entity', 'src/Resources/config/doctrine-host-users'],
    ];

    /**
     * @return iterable<string, array{0: class-string, 1: string, 2: string}>
     */
    public static function entities(): iterable
    {
        $root = dirname(__DIR__, 3);
        foreach (self::SETS as $namespace => [$entities, $xml]) {
            foreach (glob($root . '/' . $entities . '/*.php') ?: [] as $file) {
                /** @var class-string $class */
                $class = $namespace . '\\' . basename($file, '.php');
                yield $class => [$class, $root . '/' . $entities, $root . '/' . $xml];
            }
        }
    }

    /**
     * @param class-string $class
     */
    #[DataProvider('entities')]
    public function testTheXmlMappingEqualsTheAttributeMapping(string $class, string $entityDir, string $xmlDir): void
    {
        $namespace = (new ReflectionClass($class))->getNamespaceName();
        $attribute = $this->load(new AttributeDriver([$entityDir], true), $class);
        $xml       = $this->load(new SimplifiedXmlDriver([$xmlDir => $namespace]), $class);

        self::assertSame($this->describe($attribute), $this->describe($xml));
    }

    public function testEveryEntityHasAnXmlMappingAndNoMore(): void
    {
        $root = dirname(__DIR__, 3);
        foreach (self::SETS as [$entities, $xml]) {
            $classes = [];
            foreach (glob($root . '/' . $entities . '/*.php') ?: [] as $file) {
                $classes[] = basename($file, '.php');
            }
            $files = [];
            foreach (glob($root . '/' . $xml . '/*.orm.xml') ?: [] as $file) {
                $files[] = basename($file, '.orm.xml');
            }
            sort($classes);
            sort($files);
            self::assertSame($classes, $files, $xml);
        }
    }

    /**
     * @param class-string $class
     *
     * @return ClassMetadata<object>
     */
    private function load(MappingDriver $driver, string $class): ClassMetadata
    {
        $metadata = new ClassMetadata($class);
        $metadata->initializeReflection(new RuntimeReflectionService());
        $driver->loadMetadataForClass($class, $metadata);

        return $metadata;
    }

    /**
     * @param ClassMetadata<object> $metadata
     *
     * @return array<string, mixed>
     */
    private function describe(ClassMetadata $metadata): array
    {
        $fields = [];
        foreach ($metadata->fieldMappings as $name => $field) {
            $row = (array) $field;
            // The XML driver leaves these unset where the attribute driver writes false: the same column.
            foreach (['nullable', 'unique', 'index'] as $flag) {
                $row[$flag] = (bool) ($row[$flag] ?? false);
            }
            $row['idPosition'] = ($row['id'] ?? null) === true ? (int) ($row['idPosition'] ?? 0) : null;
            // XML option values are strings ("0", "true"); the schema tool reads both the same way.
            $options = [];
            foreach (is_array($row['options'] ?? null) ? $row['options'] : [] as $key => $value) {
                $options[$key] = is_bool($value) ? ($key === 'default' ? ($value ? '1' : '0') : ($value ? 'true' : 'false')) : (is_scalar($value) ? (string) $value : $value);
            }
            $row['options'] = $options === [] ? null : $options;
            ksort($row);
            $fields[$name] = $row;
        }
        ksort($fields);
        $associations = [];
        foreach ($metadata->associationMappings as $name => $association) {
            $row = $association->toArray();
            foreach (is_array($row['joinColumns'] ?? null) ? $row['joinColumns'] : [] as $i => $column) {
                if (is_array($column)) {
                    $column['deferrable']   = (bool) ($column['deferrable'] ?? false);
                    $column['unique']       = (bool) ($column['unique'] ?? false);
                    $row['joinColumns'][$i] = $column;
                }
            }
            ksort($row);
            $associations[$name] = $row;
        }
        ksort($associations);

        return [
            'table'        => $metadata->table,
            'identifier'   => $metadata->identifier,
            'generator'    => $metadata->generatorType,
            'versioned'    => [$metadata->isVersioned, $metadata->versionField],
            'repository'   => $metadata->customRepositoryClassName,
            'callbacks'    => $metadata->lifecycleCallbacks,
            'fields'       => $fields,
            'associations' => $associations,
        ];
    }
}

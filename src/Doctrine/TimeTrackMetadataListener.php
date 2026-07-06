<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Doctrine;

use Doctrine\ORM\Event\LoadClassMetadataEventArgs;
use Doctrine\ORM\Mapping\AssociationMapping;
use Doctrine\ORM\Mapping\ClassMetadata;
use LogicException;
use Nowo\TimeTrackBundle\Entity\ActiveTimer;
use Nowo\TimeTrackBundle\Entity\ClientToken;
use Nowo\TimeTrackBundle\Entity\TimeEntry;

use function array_replace_recursive;
use function in_array;
use function ltrim;
use function sprintf;

/**
 * Applies configurable table prefix and user entity mapping to time track entities.
 */
final readonly class TimeTrackMetadataListener
{
    public function __construct(
        private string $entriesTableName,
        private string $activeTimersTableName,
        private string $clientTokensTableName,
        private string $userClass,
    ) {
    }

    public function loadClassMetadata(LoadClassMetadataEventArgs $args): void
    {
        $metadata = $args->getClassMetadata();
        $class    = $metadata->getName();

        match ($class) {
            TimeEntry::class   => $metadata->setPrimaryTable(array_merge($metadata->table, ['name' => $this->entriesTableName])),
            ActiveTimer::class => $metadata->setPrimaryTable(array_merge($metadata->table, ['name' => $this->activeTimersTableName])),
            ClientToken::class => $metadata->setPrimaryTable(array_merge($metadata->table, ['name' => $this->clientTokensTableName])),
            default            => null,
        };

        if (!in_array($class, [TimeEntry::class, ActiveTimer::class, ClientToken::class], true)) {
            return;
        }

        if (isset($metadata->associationMappings['user'])) {
            $this->remapUserAssociation($metadata, 'user');
        }
    }

    private function remapUserAssociation(ClassMetadata $metadata, string $fieldName): void
    {
        $mapping = $metadata->associationMappings[$fieldName] ?? null;
        if (!$mapping instanceof AssociationMapping) {
            return;
        }

        $userClass = ltrim($this->userClass, '\\');
        if (!class_exists($userClass)) {
            throw new LogicException(sprintf('Configured user_class "%s" does not exist.', $userClass));
        }

        $newMapping = array_replace_recursive(
            $mapping->toArray(),
            ['targetEntity' => $userClass],
        );
        $newMapping['fieldName'] = $mapping->fieldName;
        unset($metadata->associationMappings[$fieldName]);
        $metadata->mapManyToOne($newMapping);
    }
}

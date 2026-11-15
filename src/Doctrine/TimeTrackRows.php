<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Doctrine;

use BackedEnum;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;

use function array_key_first;
use function bin2hex;
use function is_object;
use function random_bytes;

/**
 * 1.27.0 (Beacon spec 239): writes the row of a new TimeTrack entity whose unique key another request may take at the
 * same moment (a monitoring acknowledgement, an upload intent, an imported entry, a balance-ledger line), through DBAL,
 * inside a savepoint when a transaction is open. When the unique key refuses it, only that savepoint is rolled back:
 * the caller's transaction, the entity manager and every entity the request holds are untouched (an ORM flush that hits
 * the key closes the entity manager instead, and up to 1.26 the bundle then reset the application's manager in the
 * middle of the request).
 *
 * The columns come from the mapping: fields (enums by value) and the join columns of the many-to-one associations (the
 * target's identifier). A version field starts at 1. With `$manage` the entity is then registered as managed and clean
 * (as a flush would leave it); otherwise it is never managed by this call.
 *
 * Doctrine's persist and flush events (`prePersist`, `postPersist`, `onFlush`) do not fire for a row written here; the
 * bundle registers none on these entities.
 *
 * Stateless.
 *
 * @internal
 */
final class TimeTrackRows
{
    /** Prefix of the savepoints: never a level of the private manager's unit of work. */
    private const SAVEPOINT = 'nowo_time_track_row_';

    /**
     * @param EntityManagerInterface $entityManager The manager of the TimeTrack rows
     * @param object $entity A new TimeTrack entity (not persisted) with an assigned identifier (every TimeTrack entity has one)
     * @param bool $manage Register the entity as managed and clean after the insert
     *
     * @return UniqueConstraintViolationException|null What refused the row (another request wrote it first); null when
     *                                                 this call wrote it
     */
    public static function insert(EntityManagerInterface $entityManager, object $entity, bool $manage = false): ?UniqueConstraintViolationException
    {
        $metadata = $entityManager->getClassMetadata($entity::class);
        if ($metadata->isVersioned && $metadata->versionField !== null) {
            // The TimeTrack versions are integers (Timesheet): a new row starts at 1, as the ORM's own insert does.
            $metadata->setFieldValue($entity, $metadata->versionField, 1);
        }
        [$row, $types, $data] = self::columns($entityManager, $metadata, $entity);

        $connection = $entityManager->getConnection();
        if ($connection->isTransactionActive()) {
            $savepoint = self::SAVEPOINT . bin2hex(random_bytes(4));
            $connection->createSavepoint($savepoint);
            try {
                $connection->insert($metadata->getTableName(), $row, $types);
            } catch (UniqueConstraintViolationException $lost) {
                $connection->rollbackSavepoint($savepoint);

                return $lost;
            }
            if ($connection->getDatabasePlatform()->supportsReleaseSavepoints()) {
                $connection->releaseSavepoint($savepoint);
            }
        } else {
            try {
                $connection->insert($metadata->getTableName(), $row, $types);
            } catch (UniqueConstraintViolationException $lost) {
                return $lost; // Autocommit: the refused statement left nothing behind.
            }
        }
        if ($entityManager instanceof TimeTrackEntityManager) {
            // The private manager's row was written beside its unit of work, at this transaction level.
            $entityManager->noteWrite();
        }
        if ($manage) {
            $entityManager->getUnitOfWork()->registerManaged($entity, $metadata->getIdentifierValues($entity), $data);
        }

        return null;
    }

    /**
     * The row (column => value), its types, and the entity data as the unit of work keeps it (field => value).
     *
     * @param ClassMetadata<object> $metadata
     *
     * @return array{0: array<string, mixed>, 1: array<string, mixed>, 2: array<string, mixed>}
     */
    private static function columns(EntityManagerInterface $entityManager, ClassMetadata $metadata, object $entity): array
    {
        $row   = [];
        $types = [];
        $data  = [];
        foreach ($metadata->fieldMappings as $field => $mapping) {
            $value                       = $metadata->getFieldValue($entity, $field);
            $data[$field]                = $value;
            $row[$mapping->columnName]   = $value instanceof BackedEnum ? $value->value : $value;
            $types[$mapping->columnName] = $mapping->type;
        }
        foreach ($metadata->associationMappings as $field => $mapping) {
            if (!$mapping->isToOneOwningSide()) {
                continue; // @codeCoverageIgnore
            }
            $target = $metadata->getFieldValue($entity, $field);
            if (is_object($target) && !$entityManager->contains($target)) {
                // An object of another unit of work (your user as your manager or your firewall loaded it): this
                // manager's reference to the same row, so it is never treated as new.
                $targetMetadata = $entityManager->getClassMetadata($target::class);
                $reference      = $entityManager->getReference($targetMetadata->getName(), $targetMetadata->getIdentifierValues($target));
                if ($reference !== null) {
                    $metadata->setFieldValue($entity, $field, $reference);
                    $target = $reference;
                }
            }
            $data[$field]   = $target;
            $targetMetadata = is_object($target) ? $entityManager->getClassMetadata($target::class) : $entityManager->getClassMetadata($mapping->targetEntity);
            $targetIds      = is_object($target) ? $targetMetadata->getIdentifierValues($target) : [];
            foreach ($mapping->joinColumns as $joinColumn) {
                $referenced = $targetMetadata->getFieldForColumn($joinColumn->referencedColumnName);
                $value      = $targetIds[$referenced] ?? null;
                if (is_object($value)) {
                    // @codeCoverageIgnoreStart
                    $value = $entityManager->getClassMetadata($value::class)->getIdentifierValues($value);
                    $value = $value === [] ? null : $value[array_key_first($value)];
                    // @codeCoverageIgnoreEnd
                }
                $row[$joinColumn->name]   = $value;
                $types[$joinColumn->name] = $targetMetadata->getTypeOfField($referenced);
            }
        }

        return [$row, $types, $data];
    }
}

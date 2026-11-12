<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Doctrine;

use Doctrine\Persistence\ManagerRegistry;
use Doctrine\Persistence\ObjectManager;
use Doctrine\Persistence\ObjectRepository;

use function str_starts_with;
use function strrpos;
use function substr;

/**
 * The Doctrine registry the TimeTrack repositories and stores see (1.23.0, REQ-ARCH-001 rule 6, private entity manager
 * only): the source manager's name and the TimeTrack entity classes resolve to {@see TimeTrackEntityManager}, everything
 * else to the application's registry unchanged. Resetting the source manager through it drops the private one; the
 * application's managers are never reset by the bundle through this registry.
 *
 * @internal
 */
final readonly class TimeTrackManagerRegistry implements ManagerRegistry
{
    public const ENTITY_NAMESPACE = 'Nowo\\TimeTrackBundle\\Entity';

    /** Since 1.23.0 (audit OA-L1): the host user rows of `user_mapping: id` are bundle entities too. */
    public const USER_REF_NAMESPACE = 'Nowo\\TimeTrackBundle\\UserRef\\Entity';

    /**
     * @param ManagerRegistry $registry The application's Doctrine registry
     * @param TimeTrackEntityManager $entityManager The bundle's private manager
     * @param string $managerName the source manager of the private one (the default manager)
     */
    public function __construct(
        private ManagerRegistry $registry,
        private TimeTrackEntityManager $entityManager,
        private string $managerName = 'default',
    ) {
    }

    /**
     * The application's default connection name.
     */
    public function getDefaultConnectionName(): string
    {
        return $this->registry->getDefaultConnectionName();
    }

    /**
     * The application's connection.
     */
    public function getConnection(?string $name = null): object
    {
        return $this->registry->getConnection($name);
    }

    /**
     * The application's connections.
     */
    public function getConnections(): array
    {
        return $this->registry->getConnections();
    }

    /**
     * The application's connection names.
     */
    public function getConnectionNames(): array
    {
        return $this->registry->getConnectionNames();
    }

    /**
     * The application's default manager name.
     */
    public function getDefaultManagerName(): string
    {
        return $this->registry->getDefaultManagerName();
    }

    /**
     * The private manager for the source manager's name, else the application's manager.
     */
    public function getManager(?string $name = null): ObjectManager
    {
        if ($this->isBundleManager($name)) {
            return $this->entityManager;
        }

        return $this->registry->getManager($name);
    }

    /**
     * The application's managers (the private one is not listed).
     */
    public function getManagers(): array
    {
        return $this->registry->getManagers();
    }

    /**
     * The source manager's name: drops the private manager (a fresh one is created on the next call). Any other name: resets
     * that application manager on the application's behalf only (the bundle itself never asks for it).
     */
    public function resetManager(?string $name = null): ObjectManager
    {
        if ($this->isBundleManager($name)) {
            $this->entityManager->reset();

            return $this->entityManager;
        }

        return $this->registry->resetManager($name);
    }

    /**
     * The application's manager names.
     */
    public function getManagerNames(): array
    {
        return $this->registry->getManagerNames();
    }

    /**
     * A TimeTrack entity's repository on the private manager, else the application's.
     */
    public function getRepository(string $persistentObject, ?string $persistentManagerName = null): ObjectRepository
    {
        if (($persistentManagerName === null || $this->isBundleManager($persistentManagerName)) && self::isBundleEntity($persistentObject)) {
            return $this->entityManager->getRepository($persistentObject);
        }

        return $this->registry->getRepository($persistentObject, $persistentManagerName);
    }

    /**
     * The private manager for a TimeTrack entity, else the application's manager for the class.
     */
    public function getManagerForClass(string $class): ?ObjectManager
    {
        if (self::isBundleEntity($class)) {
            return $this->entityManager;
        }

        return $this->registry->getManagerForClass($class);
    }

    private function isBundleManager(?string $name): bool
    {
        return ($name ?? $this->registry->getDefaultManagerName()) === $this->managerName;
    }

    /**
     * Whether a class (or a Doctrine proxy class of it, `…\__CG__\…`) is a TimeTrack entity (the host user rows included).
     */
    public static function isBundleEntity(string $class): bool
    {
        $real = self::realClass($class);

        return str_starts_with($real, self::ENTITY_NAMESPACE . '\\') || str_starts_with($real, self::USER_REF_NAMESPACE . '\\');
    }

    /**
     * The entity class of a Doctrine proxy class name (`…\__CG__\App\Entity\X` → `App\Entity\X`), or the class itself.
     */
    public static function realClass(string $class): string
    {
        $marker = strrpos($class, '\\__CG__\\');

        return $marker !== false ? substr($class, $marker + 8) : $class;
    }
}

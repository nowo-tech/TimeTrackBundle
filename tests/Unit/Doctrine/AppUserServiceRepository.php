<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Unit\Doctrine;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Nowo\TimeTrackBundle\Tests\Fixtures\FunctionalEntity\AppUser;

/**
 * A host service repository (DoctrineBundle), only named by the repository factory test (never built).
 *
 * @extends ServiceEntityRepository<AppUser>
 */
final class AppUserServiceRepository extends ServiceEntityRepository
{
}

<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Fixtures\RoutingEntity;

use Doctrine\ORM\Mapping as ORM;

/**
 * Minimal host user entity (`user_class`) of the routing test kernel: the bundle entities map their user relations
 * to it.
 */
#[ORM\Entity]
#[ORM\Table(name: 'host_user')]
class HostUser
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    public ?int $id = null;
}

<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Stub;

use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Event\PostFlushEventArgs;
use Nowo\TimeTrackBundle\Tests\Support\FunctionalKernel;

/**
 * A host's Doctrine listeners, off unless a test sets them: one that removes an entity inside `onFlush`, one that flushes
 * inside `postFlush`. Priorities are in {@see FunctionalKernel}.
 */
final class HostFlushListener
{
    public ?object $removeOnFlush = null;

    public bool $flushOnPostFlush = false;

    public function onFlush(OnFlushEventArgs $args): void
    {
        if ($this->removeOnFlush !== null) {
            $args->getObjectManager()->remove($this->removeOnFlush);
            $this->removeOnFlush = null;
        }
    }

    public function postFlush(PostFlushEventArgs $args): void
    {
        if ($this->flushOnPostFlush) {
            $this->flushOnPostFlush = false;
            $args->getObjectManager()->flush();
        }
    }
}

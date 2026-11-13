<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Stub;

use Closure;
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

    /** Since 1.25.0: runs once inside the next `onFlush` (e.g. a concurrent request's insert). */
    public ?Closure $onFlushOnce = null;

    public function onFlush(OnFlushEventArgs $args): void
    {
        if ($this->onFlushOnce instanceof Closure) {
            $hook              = $this->onFlushOnce;
            $this->onFlushOnce = null;
            $hook($args);
        }
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

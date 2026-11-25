<?php

declare(strict_types=1);

namespace App\TimeTrack;

use Nowo\TimeTrackBundle\Subject\TimeSubjectContext;
use Nowo\TimeTrackBundle\Subject\TimeSubjectLabels;
use Nowo\TimeTrackBundle\Subject\TimeSubjectProviderInterface;
use Nowo\TimeTrackBundle\Subject\TimeSubjectRef;
use Nowo\TimeTrackBundle\Subject\TimeSubjectSearch;

/**
 * 2.1.0 test fixture (loaded with require_once, outside the bundle namespace): an application subject provider that
 * denies everything; subclasses only set the type and the task rule.
 */
abstract class HostSubjectProviderBase implements TimeSubjectProviderInterface
{
    /**
     * Denied.
     */
    public function canLog(TimeSubjectContext $context, TimeSubjectRef $subject): bool
    {
        return false;
    }

    /**
     * Nothing readable.
     */
    public function describe(TimeSubjectContext $context, array $subjects): TimeSubjectLabels
    {
        return TimeSubjectLabels::none();
    }

    /**
     * Nothing found.
     */
    public function search(TimeSubjectContext $context, TimeSubjectSearch $search): array
    {
        return [];
    }
}

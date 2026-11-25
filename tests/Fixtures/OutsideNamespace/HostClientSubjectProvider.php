<?php

declare(strict_types=1);

namespace App\TimeTrack;

/**
 * 2.1.0 test fixture (loaded with require_once, outside the bundle namespace): customers (with tasks).
 */
final class HostClientSubjectProvider extends HostSubjectProviderBase
{
    /**
     * The subject type.
     */
    public static function subjectType(): string
    {
        return 'client';
    }

    /**
     * Whether a task may sit next to the subject.
     */
    public static function allowsTask(): bool
    {
        return true;
    }
}

<?php

declare(strict_types=1);

namespace App\TimeTrack;

/**
 * 2.1.0 test fixture (loaded with require_once, outside the bundle namespace): a type that breaks the pattern.
 */
final class HostBadTypeSubjectProvider extends HostSubjectProviderBase
{
    /**
     * The subject type.
     */
    public static function subjectType(): string
    {
        return 'Bad Type';
    }

    /**
     * Whether a task may sit next to the subject.
     */
    public static function allowsTask(): bool
    {
        return false;
    }
}

<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Subject;

use InvalidArgumentException;
use Nowo\TimeTrackBundle\Support\NoteSanitizer;

use function mb_strlen;

/**
 * Since 2.1.0 (Beacon spec 256): a bounded subject search ({@see TimeSubjectProviderInterface::search()}). The query is
 * passed as data (the bundle expands no wildcard), cleaned with the note rules and at most
 * {@see self::MAX_QUERY_LENGTH} characters; the limit is 1 to {@see self::MAX_LIMIT}. Results are a UX hint: every
 * write checks `canLog` again (Q-TS-4). Framework-free, immutable, built per call.
 */
final readonly class TimeSubjectSearch
{
    /** Longest query, in characters, after cleaning. */
    public const MAX_QUERY_LENGTH = 100;

    /** Most results. */
    public const MAX_LIMIT = 50;

    /** Default number of results. */
    public const DEFAULT_LIMIT = 20;

    /** The cleaned query ('' = the provider's first subjects). */
    public string $query;

    /**
     * @param string $type The subject type searched
     * @param string $query Free text
     * @param int $limit Most results (1–50)
     *
     * @throws InvalidArgumentException When the type is not valid, the query is too long or the limit is out of range
     */
    public function __construct(public string $type, string $query = '', public int $limit = self::DEFAULT_LIMIT)
    {
        if (!TimeSubjectRef::isType($type)) {
            throw new InvalidArgumentException('A TimeTrack subject type must match ' . TimeSubjectRef::TYPE_PATTERN . '.');
        }
        $query = NoteSanitizer::clean($query);
        if (mb_strlen($query) > self::MAX_QUERY_LENGTH) {
            throw new InvalidArgumentException('A TimeTrack subject search query may hold at most ' . self::MAX_QUERY_LENGTH . ' characters.');
        }
        if ($limit < 1 || $limit > self::MAX_LIMIT) {
            throw new InvalidArgumentException('A TimeTrack subject search limit must be between 1 and ' . self::MAX_LIMIT . '.');
        }
        $this->query = $query;
    }
}

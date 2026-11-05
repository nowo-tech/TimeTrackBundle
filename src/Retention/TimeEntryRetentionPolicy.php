<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Retention;

use Nowo\TimeTrackBundle\Enum\TimeEntrySource;

/**
 * Since 1.11: which entries a retention purge may delete. Imported entries are history and stay out of the purge by
 * default (`retention.include_imported: false`). The bundle ships no purge yet; any purge (the bundle's, later, or a
 * host's) must filter with {@see self::excludedSources()}.
 */
final readonly class TimeEntryRetentionPolicy
{
    /**
     * @param bool $includeImported `retention.include_imported`
     */
    public function __construct(
        private bool $includeImported = false,
    ) {
    }

    /**
     * Whether a purge may delete entries of this source.
     */
    public function isPurgeable(TimeEntrySource $source): bool
    {
        return $source->isLive() || $this->includeImported;
    }

    /**
     * Source values a purge must skip (`WHERE source NOT IN (...)`).
     *
     * @return list<string>
     */
    public function excludedSources(): array
    {
        return $this->includeImported ? [] : [TimeEntrySource::Import->value];
    }
}

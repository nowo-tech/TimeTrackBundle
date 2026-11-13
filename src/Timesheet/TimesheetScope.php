<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Timesheet;

use InvalidArgumentException;

use function mb_strlen;
use function strlen;

/**
 * Since 1.13: one host tenant scope a user tracks time in (e.g. Beacon `org-<uuid>`), with its display label and,
 * optionally, the IANA time zone its periods are cut in.
 */
final readonly class TimesheetScope
{
    /** Longest scope key (the `scope_key` columns). */
    public const MAX_KEY_LENGTH = 64;

    /**
     * Since 1.24.1 (REQ-SEC-006, audit OM TT-L2) a key with an edge space, another blank, a control, format or invisible
     * character or invalid UTF-8 is accepted here but never used: the bundle leaves such a scope out with a warning
     * ({@see ScopeKeys::usableScopes()}), so a legacy host key never fails a request.
     *
     * @throws InvalidArgumentException When the key is longer than {@see self::MAX_KEY_LENGTH} bytes
     */
    public function __construct(
        public string $key,
        public string $label,
        public ?string $timezone = null,
    ) {
        if (strlen($key) > self::MAX_KEY_LENGTH || mb_strlen($label) > 255) {
            throw new InvalidArgumentException('Scope key or label too long.');
        }
    }
}

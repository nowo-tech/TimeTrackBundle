<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Timer;

use InvalidArgumentException;

use function preg_match;

/**
 * Since 1.25.0: the channel label of an entry written through {@see HostTimerApi} or a host's manual entry (`api`, `mcp`,
 * …). It is a label for reports and audit, never an authorisation input.
 *
 * Accepted values are 1 to 16 characters of `a-z`, `0-9` and `_`. Anything else is refused at entry (REQ-SEC-006): no
 * upper case, no blank, no invisible character. A value stored by the bundle therefore compares equal under any
 * collation only when it is byte-for-byte equal. Stateless.
 */
final class TimerChannel
{
    /** Accepted channel labels. */
    public const PATTERN = '/^[a-z0-9_]{1,16}$/D';

    /**
     * Returns the channel unchanged when valid.
     *
     * @throws InvalidArgumentException when the label is not 1 to 16 characters of `a-z`, `0-9` and `_`
     */
    public static function assertValid(string $channel): string
    {
        if (preg_match(self::PATTERN, $channel) !== 1) {
            throw new InvalidArgumentException('A TimeTrack channel is 1 to 16 characters of a-z, 0-9 and _.');
        }

        return $channel;
    }
}

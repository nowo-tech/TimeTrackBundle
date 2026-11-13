<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Repository;

use InvalidArgumentException;
use Nowo\TimeTrackBundle\Entity\TimeEntry;
use Nowo\TimeTrackBundle\Timer\TimerChannel;

/**
 * Since 1.25.0: which entries a {@see OriginFilteredTimeEntryRepositoryInterface} query keeps, by channel and by the
 * service-account mark. A null criterion keeps every value. Framework-free; built per call.
 */
final readonly class TimeEntryOriginFilter
{
    /**
     * @param string|null $channel Only entries written through this channel (`api`, `mcp`, …); null = any, also none
     * @param bool|null $serviceAccount true = agents' entries only, false = people's only, null = both
     * @param bool $withoutChannel Only entries with no channel (client API, web UI, imports); not with `$channel`
     *
     * @throws InvalidArgumentException when the channel is not a valid label, or both channel criteria are set
     */
    public function __construct(
        public ?string $channel = null,
        public ?bool $serviceAccount = null,
        public bool $withoutChannel = false,
    ) {
        if ($channel !== null) {
            // REQ-SEC-006: refused at entry, so a collation can never fold a look-alike onto a stored label.
            TimerChannel::assertValid($channel);
            if ($withoutChannel) {
                throw new InvalidArgumentException('A TimeTrack origin filter takes a channel or withoutChannel, not both.');
            }
        }
    }

    /**
     * Agents' entries only.
     */
    public static function serviceAccounts(): self
    {
        return new self(serviceAccount: true);
    }

    /**
     * People's entries only.
     */
    public static function people(): self
    {
        return new self(serviceAccount: false);
    }

    /**
     * Whether the entry passes the filter (byte-for-byte channel comparison).
     */
    public function matches(TimeEntry $entry): bool
    {
        if ($this->serviceAccount !== null && $entry->isServiceAccount() !== $this->serviceAccount) {
            return false;
        }
        if ($this->withoutChannel) {
            return $entry->channel() === null;
        }

        return $this->channel === null || $entry->channel() === $this->channel;
    }
}

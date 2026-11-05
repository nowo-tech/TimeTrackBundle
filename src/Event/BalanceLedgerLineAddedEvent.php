<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Event;

use Nowo\TimeTrackBundle\Enum\LedgerKind;
use Symfony\Contracts\EventDispatcher\Event;

/**
 * Since 1.13 ({@see TimeTrackEvents::BALANCE_LEDGER_LINE_ADDED}): a balance ledger line was appended (after flush).
 * Ids only; the reason is never part of the event.
 */
final class BalanceLedgerLineAddedEvent extends Event
{
    /**
     * Creates the event.
     */
    public function __construct(
        public readonly string $lineId,
        public readonly string $ownerRef,
        public readonly string $scopeKey,
        public readonly LedgerKind $kind,
        public readonly int $seconds,
        public readonly ?string $actorRef,
    ) {
    }
}

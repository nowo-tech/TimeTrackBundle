<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Exception;

use RuntimeException;

/**
 * Since the GG fixes (audit GG-L1): a concurrent request with the same `Idempotency-Key` finished first; the stored
 * answer (or the mismatch answer) is replayed instead of writing again. Internal to the client API.
 */
final class IdempotentReplayException extends RuntimeException
{
    /**
     * @param array<string, mixed> $body
     */
    public function __construct(
        public readonly int $status,
        public readonly array $body,
    ) {
        parent::__construct('idempotent_replay');
    }
}

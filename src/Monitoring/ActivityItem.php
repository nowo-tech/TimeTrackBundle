<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Monitoring;

use DateTimeImmutable;
use InvalidArgumentException;

use function mb_strlen;
use function trim;

/**
 * Since 1.26.0 (contract §24.3 step 5): one activity aggregate of a slot of an entry — the frontmost app's seconds
 * (`app`, with its redacted `name`) or the activity level (`level`, 0-100, no name). The VO the bundle hands to
 * {@see MonitoringEvidenceStoreInterface::storeActivity()} after checking it.
 */
final readonly class ActivityItem
{
    /** The frontmost application and its seconds in the slot. */
    public const KIND_APP = 'app';

    /** The activity level of the slot (0-100, from the idle seconds the client already polls). */
    public const KIND_LEVEL = 'level';

    /** Longest app name, in characters. */
    public const NAME_MAX_LENGTH = 120;

    /** Most seconds of one slot. */
    public const MAX_SECONDS = 600;

    /**
     * @param string $kind {@see self::KIND_APP} or {@see self::KIND_LEVEL}
     * @param string|null $name App name (app only; redacted)
     * @param int $seconds 0-600
     * @param int|null $level 0-100 (level only)
     *
     * @throws InvalidArgumentException when a value is outside the contract
     */
    public function __construct(
        public string $entryId,
        public DateTimeImmutable $slot,
        public string $kind,
        public ?string $name,
        public int $seconds,
        public ?int $level = null,
    ) {
        if ($entryId === '' || mb_strlen($entryId) > 36) {
            throw new InvalidArgumentException('entryId must be 1-36 characters.');
        }
        if ($seconds < 0 || $seconds > self::MAX_SECONDS) {
            throw new InvalidArgumentException('seconds must be 0-600.');
        }
        $valid = match ($kind) {
            self::KIND_APP   => $name !== null && trim($name) !== '' && mb_strlen($name) <= self::NAME_MAX_LENGTH && $level === null,
            self::KIND_LEVEL => $name === null && $level !== null && $level >= 0 && $level <= 100,
            default          => false,
        };
        if (!$valid) {
            throw new InvalidArgumentException('An activity item is {kind: app, name} or {kind: level, level: 0-100}.');
        }
    }

    /**
     * The policy kind that must be enabled for this item: `apps` for an app, `activity` for a level.
     */
    public function policyKind(): string
    {
        return $this->kind === self::KIND_APP ? 'apps' : 'activity';
    }
}

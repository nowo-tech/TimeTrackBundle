<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Client;

use InvalidArgumentException;

use function checkdate;
use function preg_match;

/**
 * Since 1.22 (Beacon spec 204 FR-029): the user's current leave, as {@see ClientUserLeaveProviderInterface} answers it
 * for `GET workday.leave` (`{type, until}`). Framework-free (REQ-ARCH-001 rule 3).
 *
 * It holds only what the clients may show, and nothing else can be put in it: a **public** type label (or none) and
 * the last day of the leave. There is no field for a reason, a comment, an attachment or a health detail.
 *
 * - {@see self::typed()}: a type the user's devices may show and store (`"Vacation"`, `"Paid leave"`).
 * - {@see self::absent()}: no type at all; the client API sends the bundle's own *Absent* label in the user's language.
 *   Use it for medical / sick leave (GDPR art. 9) and for any type the user's devices must not learn.
 */
final readonly class ClientUserLeave
{
    /**
     * @param string|null $type Public type label (null = *Absent*)
     * @param string $until Last day of the leave, `YYYY-MM-DD` (inclusive, local date in the workday time zone)
     */
    private function __construct(
        public ?string $type,
        public string $until,
    ) {
    }

    /**
     * A leave whose type the user's devices may show and store. The label is plain text (the client API cleans it and
     * cuts it to {@see ClientDaysOffNormalizer::LABEL_MAX_LENGTH} grapheme clusters; an empty label is sent as *Absent*).
     *
     * @param string $until Last day of the leave, `YYYY-MM-DD`
     *
     * @throws InvalidArgumentException When `$until` is not a real `YYYY-MM-DD` date
     */
    public static function typed(string $publicType, string $until): self
    {
        return new self($publicType, self::date($until));
    }

    /**
     * A leave without a type: the client API sends *Absent* (medical leave, or any type that must stay private).
     *
     * @param string $until Last day of the leave, `YYYY-MM-DD`
     *
     * @throws InvalidArgumentException When `$until` is not a real `YYYY-MM-DD` date
     */
    public static function absent(string $until): self
    {
        return new self(null, self::date($until));
    }

    /**
     * True when no type may be shown (*Absent*).
     */
    public function isAbsent(): bool
    {
        return $this->type === null;
    }

    /**
     * `$until` when it is a real `YYYY-MM-DD` date.
     */
    private static function date(string $until): string
    {
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D', $until, $parts) !== 1
            || !checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1])) {
            throw new InvalidArgumentException('The leave end must be a YYYY-MM-DD date.');
        }

        return $until;
    }
}

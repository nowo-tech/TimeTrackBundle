<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Stub;

use DateTimeImmutable;
use Nowo\TimeTrackBundle\Client\ClientUserLeave;
use Nowo\TimeTrackBundle\Client\ClientUserLeaveProviderInterface;
use RuntimeException;

use function in_array;

use const DATE_ATOM;

/**
 * Since 1.22 (spec 204): a host adapter in the style of Beacon's. It holds the host's full leave records — type,
 * medical flag, comment, attachment — and maps them to the bundle value object the way the host must: a medical record
 * becomes {@see ClientUserLeave::absent()}, any other its public type. Users in `$failing` make it throw (with personal
 * data in the message, which must never be logged).
 */
final class StubLeaveProvider implements ClientUserLeaveProviderInterface
{
    /** @var array<array-key, array{type: string, medical: bool, comment: string, attachment: string|null, until: string}> host records by user id */
    public array $records = [];

    /** @var list<string> */
    public array $failing = [];

    /** @var list<array{string, string}> user id and the asked day (ATOM) */
    public array $calls = [];

    public function getCurrentLeave(string $userId, DateTimeImmutable $day): ?ClientUserLeave
    {
        $this->calls[] = [$userId, $day->format(DATE_ATOM)];
        if (in_array($userId, $this->failing, true)) {
            throw new RuntimeException('Leave store down while reading the sick note of user ' . $userId);
        }
        $record = $this->records[$userId] ?? null;
        if ($record === null) {
            return null;
        }

        return $record['medical'] ? ClientUserLeave::absent($record['until']) : ClientUserLeave::typed($record['type'], $record['until']);
    }
}

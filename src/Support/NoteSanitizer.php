<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Support;

use function mb_substr;
use function preg_replace;

/**
 * The one sanitiser and merge rule of every client note (since 1.10.2, EF2-I2 / EF2-I4): `timer/start`, `timer/stop`,
 * `timer/switch`, `timer/discard-idle` `keep` and `reassign`, and a `note` in allowed free metadata.
 *
 * Cleaning, in this order:
 * 1. bidirectional controls U+202A–U+202E and U+2066–U+2069 are removed (no right-to-left override spoofing);
 * 2. U+200B (zero-width space) and U+FEFF (byte-order mark) are removed everywhere; the zero-width joiners U+200C /
 *    U+200D and U+2060 are kept inside the text (emoji sequences and some scripts need them) but trimmed at both ends;
 * 3. each run of control characters (`\p{Cc}`: C0, DEL, C1, including CR / LF / tab) becomes one space;
 * 4. leading and trailing white space (`\s`, `\p{Z}`) and zero-width characters are trimmed.
 *
 * The cap is {@see self::MAX_LENGTH} Unicode code points everywhere. The result is plain user text: whoever renders it
 * (a host page, an audit log) must escape it for that context.
 */
final class NoteSanitizer
{
    /** Max length of a note, in code points (timer, entry, and every client input). */
    public const MAX_LENGTH = 500;

    /** Separator between notes merged into one. */
    public const SEPARATOR = "\n";

    /**
     * Cleans a note; '' when nothing is left. Invalid UTF-8 (never the case for a decoded JSON body) yields ''.
     */
    public static function clean(string $note): string
    {
        $note = (string) preg_replace('/[\x{202A}-\x{202E}\x{2066}-\x{2069}\x{200B}\x{FEFF}]+/u', '', $note);
        $note = (string) preg_replace('/\p{Cc}+/u', ' ', $note);

        return (string) preg_replace('/\A[\s\p{Z}\x{200C}\x{200D}\x{2060}]+|[\s\p{Z}\x{200C}\x{200D}\x{2060}]+\z/u', '', $note);
    }

    /**
     * Cleans a note and cuts it at {@see self::MAX_LENGTH} code points; null when nothing is left.
     */
    public static function cleanAndCut(string $note): ?string
    {
        $clean = self::clean($note);

        return $clean === '' ? null : mb_substr($clean, 0, self::MAX_LENGTH);
    }

    /**
     * Merge rule: an empty existing note takes the new one; otherwise the new note is appended after
     * {@see self::SEPARATOR}, and the result is cut at {@see self::MAX_LENGTH} code points (the earlier text wins).
     */
    public static function merge(?string $existing, string $note): string
    {
        return mb_substr($existing === null || $existing === '' ? $note : $existing . self::SEPARATOR . $note, 0, self::MAX_LENGTH);
    }
}

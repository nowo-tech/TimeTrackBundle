<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Import;

use Nowo\TimeTrackBundle\Support\NoteSanitizer;

use function preg_replace;
use function str_replace;

/**
 * Since 1.11: cleans an imported description like a note ({@see NoteSanitizer}) but
 * keeps the line breaks and **never truncates** (owner decision: the full text of the source tool is kept).
 *
 * 1. CRLF and CR become LF;
 * 2. bidirectional controls, U+200B and U+FEFF are removed;
 * 3. every other control character (`\p{Cc}` except LF) becomes one space;
 * 4. both ends are trimmed. An empty result is null.
 */
final class ImportedDescriptionSanitizer
{
    /**
     * The cleaned description (full length), or null when nothing is left.
     */
    public static function clean(?string $description): ?string
    {
        if ($description === null) {
            return null;
        }
        $text = str_replace(["\r\n", "\r"], "\n", $description);
        $text = (string) preg_replace('/[\x{061C}\x{200E}\x{200F}\x{202A}-\x{202E}\x{2066}-\x{2069}\x{200B}\x{FEFF}]+/u', '', $text);
        $text = (string) preg_replace('/[^\P{Cc}\n]+/u', ' ', $text);
        $text = (string) preg_replace('/\A[\s\p{Z}\x{200C}\x{200D}\x{2060}]+|[\s\p{Z}\x{200C}\x{200D}\x{2060}]+\z/u', '', $text);

        return $text === '' ? null : $text;
    }

    /**
     * Since 1.15: the same rules on one line: every run of line breaks (LF, U+2028 line separator, U+2029 paragraph
     * separator) and the blanks around it becomes one space. Nothing left = the empty string.
     */
    public static function oneLine(string $text): string
    {
        return (string) preg_replace('/\h*[\n\x{2028}\x{2029}][\n\x{2028}\x{2029}\h]*/u', ' ', self::clean($text) ?? '');
    }
}

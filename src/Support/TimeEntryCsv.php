<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Support;

use Nowo\TimeTrackBundle\Entity\TimeEntry;

use function fclose;
use function fopen;
use function fputcsv;
use function in_array;
use function is_string;
use function mb_substr;
use function preg_replace;
use function rewind;
use function stream_get_contents;

use const DATE_ATOM;

/**
 * CSV of the own-data export (`GET export?format=csv`, RGPD art. 15/20). Cells that a spreadsheet would read as a
 * formula (`=`, `+`, `-`, `@` or their full-width forms, after leading spaces and invisible characters; tab; carriage
 * return) are prefixed with a single quote.
 */
final class TimeEntryCsv
{
    /** Header row. */
    public const HEADER = ['id', 'taskId', 'taskTitle', 'boardId', 'startedAt', 'endedAt', 'durationSeconds', 'source', 'note'];

    /**
     * CSV text of the entries (header first, RFC 4180 quoting).
     *
     * @param list<TimeEntry> $entries
     */
    public static function render(array $entries): string
    {
        /** @var resource $stream php://temp always opens */
        $stream = fopen('php://temp', 'r+');
        fputcsv($stream, self::HEADER, ',', '"', '');
        foreach ($entries as $entry) {
            $note = ($entry->getMetadata() ?? [])['note'] ?? null;
            fputcsv($stream, [
                self::cell($entry->getId()),
                self::cell($entry->getTaskId()),
                self::cell($entry->getTaskTitleSnapshot()),
                self::cell($entry->getBoardIdSnapshot() ?? ''),
                $entry->getStartedAt()->format(DATE_ATOM),
                $entry->getEndedAt()->format(DATE_ATOM),
                (string) $entry->getDurationSeconds(),
                $entry->getSource()->value,
                self::cell(is_string($note) ? $note : ''),
            ], ',', '"', '');
        }
        rewind($stream);
        $csv = (string) stream_get_contents($stream);
        fclose($stream);

        return $csv;
    }

    /** Characters that start a formula in a spreadsheet (ASCII and full-width). */
    public const FORMULA_STARTS = ['=', '+', '-', '@', '＝', '＋', '－', '＠'];

    /**
     * Neutralises a spreadsheet formula: a cell starting with tab or CR, or whose first visible character (after
     * leading spaces and invisible format characters such as U+200B and U+FEFF, EF2-I3) starts a formula, gets a
     * leading single quote.
     */
    public static function cell(string $value): string
    {
        if ($value === '') {
            return $value;
        }

        if ($value[0] === "\t" || $value[0] === "\r") {
            return "'" . $value;
        }

        $visible = preg_replace('/\A[\s\p{Z}\p{Cf}]+/u', '', $value) ?? $value;

        return $visible !== '' && in_array(mb_substr($visible, 0, 1), self::FORMULA_STARTS, true) ? "'" . $value : $value;
    }
}

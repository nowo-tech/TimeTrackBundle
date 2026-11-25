<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Support;

use Nowo\TimeTrackBundle\Entity\TimeEntry;
use Nowo\TimeTrackBundle\Service\TimerService;
use Nowo\TimeTrackBundle\Subject\AllocationLabels;

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
    public const HEADER = ['id', 'taskId', 'taskTitle', 'boardId', 'startedAt', 'endedAt', 'durationSeconds', 'source', 'note', 'billable', 'description', 'imported', 'scope_key', 'locked', 'channel', 'service_account', 'subject_type', 'subject_id', 'subject_label', 'activity_type_key', 'activity_type_label'];

    /**
     * CSV text of the entries (header first, RFC 4180 quoting). Since 1.11 the columns `billable`, `description` and
     * `imported` are appended, and the title of an entry listed in `$hiddenTaskEntryIds` is `$hiddenTitle` (the
     * translated `reports.task_not_visible` in the client API export; {@see TimeEntry::HIDDEN_TASK_TITLE} by default).
     * Since 1.12 the `description` of such an entry is empty as well. Since 1.25.0 `channel` and `service_account` are
     * appended (the agent mark: `yes` / `no`). Since 2.1.0 (Beacon spec 256) `subject_type`, `subject_id`, `subject_label`,
     * `activity_type_key` and `activity_type_label` are appended at the end (the order of the earlier columns is
     * unchanged): labels resolved per page with the viewer; a subject the viewer may not read has an empty id and the
     * masked label; every cell keeps the formula neutralisation.
     *
     * @param list<TimeEntry> $entries
     * @param array<string, true> $hiddenTaskEntryIds entry id => true ({@see TimerService::hiddenTaskEntryIds()})
     * @param array<string, true> $lockedEntryIds Since 1.13: entry id => true for entries of a locked or submitted period (`locked` column; `scope_key` is appended too)
     * @param AllocationLabels|null $allocations Since 2.1.0: the page's subject and activity type labels (null = every subject masked)
     */
    public static function render(array $entries, array $hiddenTaskEntryIds = [], string $hiddenTitle = TimeEntry::HIDDEN_TASK_TITLE, array $lockedEntryIds = [], ?AllocationLabels $allocations = null): string
    {
        $allocations ??= AllocationLabels::none();
        /** @var resource $stream php://temp always opens */
        $stream = fopen('php://temp', 'r+');
        fputcsv($stream, self::HEADER, ',', '"', '');
        foreach ($entries as $entry) {
            $note   = ($entry->getMetadata() ?? [])['note'] ?? null;
            $hidden = isset($hiddenTaskEntryIds[$entry->getId()]);
            fputcsv($stream, [
                self::cell($entry->getId()),
                self::cell($entry->getTaskId()),
                self::cell($hidden ? $hiddenTitle : $entry->getTaskTitleSnapshot()),
                self::cell($entry->getBoardIdSnapshot() ?? ''),
                $entry->getStartedAt()->format(DATE_ATOM),
                $entry->getEndedAt()->format(DATE_ATOM),
                (string) $entry->getDurationSeconds(),
                $entry->getSource()->value,
                self::cell(is_string($note) ? $note : ''),
                match ($entry->getBillable()) {
                    true    => 'yes',
                    false   => 'no',
                    default => '',
                },
                self::cell($hidden ? '' : $entry->getDescription() ?? ''),
                $entry->isReadOnly() ? 'yes' : 'no',
                self::cell($entry->getScopeKey() ?? ''),
                isset($lockedEntryIds[$entry->getId()]) ? 'yes' : 'no',
                $entry->channel() ?? '',
                $entry->isServiceAccount() ? 'yes' : 'no',
                ...self::allocationCells($entry, $allocations),
            ], ',', '"', '');
        }
        rewind($stream);
        $csv = (string) stream_get_contents($stream);
        fclose($stream);

        return $csv;
    }

    /**
     * Since 2.1.0: the five allocation cells of an entry.
     *
     * @return list<string>
     */
    private static function allocationCells(TimeEntry $entry, AllocationLabels $allocations): array
    {
        $subject = $allocations->subject($entry->getScopeKey(), $entry->subject());
        $key     = $entry->getActivityTypeKey();

        return [
            self::cell($subject['type'] ?? ''),
            self::cell(isset($subject['id']) ? (string) $subject['id'] : ''),
            self::cell($subject['label'] ?? ''),
            self::cell($key ?? ''),
            self::cell($key === null ? '' : $allocations->activityTypeLabel($entry->getScopeKey(), $key)),
        ];
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

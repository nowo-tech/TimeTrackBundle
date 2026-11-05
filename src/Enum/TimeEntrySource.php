<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Enum;

/**
 * Origin of a recorded time entry (web UI, browser extension, desktop app, since 1.11 an import from another tool and,
 * since 1.13, `manual` / `calendar` for entries created without a timer).
 */
enum TimeEntrySource: string
{
    case Web       = 'web';
    case Extension = 'extension';
    case Desktop   = 'desktop';
    /** Since 1.11: copied from another tool (e.g. ClickUp) by the host through the imported-entry writer. */
    case Import = 'import';
    /** Since 1.13: a past entry the user entered by hand (write path in 1.14, `POST entries`). Live and editable. */
    case Manual = 'manual';
    /** Since 1.13: an accepted calendar suggestion (write path in 1.14). Live and editable. */
    case Calendar = 'calendar';

    /**
     * Since 1.11: whether the entry was recorded live by a timer in this application. Imported entries are history:
     * hosts keep them out of overlap checks, working-hours registers, correction windows and clock-in rules.
     */
    public function isLive(): bool
    {
        return $this !== self::Import;
    }

    /**
     * Since 1.11: whether nobody may edit the entry (imported entries are read-only; they can only be deleted).
     */
    public function isReadOnly(): bool
    {
        return $this === self::Import;
    }
}

<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Import;

use Nowo\TimeTrackBundle\Entity\TimeEntry;
use Nowo\TimeTrackBundle\Event\TimeEntryListQueryEvent;

use function mb_substr;
use function preg_replace;
use function str_contains;
use function trim;

/**
 * Since 1.15: the person of an imported entry when no host user stands for them (a former colleague with no account).
 * The writer validates it. The entry has no owner and shows under this name.
 */
final readonly class ImportedTimeEntryPerson
{
    /** References the writer accepts; {@see TimeEntry::USER_REF_PREFIX} is reserved. */
    public const REF_PATTERN = '/^[A-Za-z0-9._:#-]{1,64}$/D';

    /** The `scope_key` column length. */
    public const MAX_SCOPE_KEY_LENGTH = 64;

    /** The `person_name_snapshot` column length, in code points. */
    public const MAX_NAME_LENGTH = 255;

    /**
     * @param string $ref stable opaque identity of the person (e.g. the TaskBoard `TaskSyncPerson` id): erasure and export look it up
     * @param string|null $name name shown in reports (null or blank = a generic "former person")
     * @param string|null $scopeKey host tenant scope of the entry: who may list it ({@see TimeEntryListQueryEvent::includeOwnerless()})
     */
    public function __construct(
        public string $ref,
        public ?string $name = null,
        public ?string $scopeKey = null,
    ) {
    }

    /**
     * A name as stored: controls, bidi overrides and every other invisible format character stripped, one line, at most
     * {@see self::MAX_NAME_LENGTH} code points; blank, or anything holding an `@` (a contact detail, not a name, also in
     * `Ana <ana@example.com>`), = null.
     */
    public static function cleanName(?string $name): ?string
    {
        if ($name === null) {
            return null;
        }
        $clean = trim((string) preg_replace('/\p{Cf}+/u', '', ImportedDescriptionSanitizer::oneLine($name)));

        return $clean === '' || str_contains($clean, '@') || str_contains($clean, '＠') ? null : mb_substr($clean, 0, self::MAX_NAME_LENGTH);
    }
}

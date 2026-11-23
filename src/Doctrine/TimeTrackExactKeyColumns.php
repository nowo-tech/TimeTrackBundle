<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Doctrine;

use Nowo\MigrationsKitContracts\Collation\ExactKeyColumn;
use Nowo\MigrationsKitContracts\Collation\ExactKeyColumnsInterface;
use Nowo\TimeTrackBundle\UserRef\UserMapping;

/**
 * The bundle's own declaration of the identity / access-key columns of its tables (REQ-SEC-006).
 *
 * Two kinds of key live in these tables, and the bundle already keeps both on the server's binary NO PAD collation
 * when the schema is built ({@see TimeTrackMetadataListener}):
 *
 * - `scope_key`, the tenant scope every read is filtered by (1.24.1, audit OA-R2 / F4). On a folding collation the
 *   approver of scope `acme` reads `Acme`'s and `acme `'s timesheets, ledger lines, calendar rows and entries — this
 *   is the class of defect behind GHSA-r889-x27h-wq4q. Declared for every configuration, because a host's scope keys
 *   are not user ids and the column exists either way.
 * - the person reference columns (`owner_ref`, `submitted_by_ref`, `decided_by_ref`, `actor_ref`, `person_ref`),
 *   which hold an opaque user id and only compare exactly with `user_mapping: id` (1.23.0, audit OA-M2). With the
 *   relation mapping they are the DDL of a mode that is off, so they are declared **inactive** with that setting:
 *   reported as skipped, never as a failure.
 *
 * What was missing is the *assertion*. Until now only a host's hand-kept list knew that `scope_key` is an access key,
 * so a table created before 1.24.1, restored from a dump or imported by hand kept the host's folding default and
 * nothing said so. This declaration is what `nowo:migrations:check-key-collations` checks; MigrationsKit discovers it
 * by autoconfiguration, so a host registers **nothing** and can delete the
 * `nowo_migrations_kit.collation_check.ignore_columns` entries it kept for these columns.
 *
 * Every table name is **configurable** (`nowo_time_track.table_prefix`), so the declaration builds them from the
 * same prefix the schema is built from; there is not one literal table name here.
 *
 * - since 1.38.0, with `user_mapping: id`, the host users table's `id` and the `user_id` of every table that references
 *   it (the schema tool copies the collation of the referenced column to the foreign key).
 *
 * Deliberately **not** declared, because a declaration is a claim about identity and an untrue one is worse than
 * none: the task and client identifiers that are opaque strings the bundle only stores and gives back, the
 * `client_tokens` / `device_codes` digests and the monitoring tables' ids and `user_id` (the bundle does not give those an exact
 * collation today, and a declared column that was never collated reports a failure on every host — raising them is a
 * schema decision for its own release), the state and timestamp columns, and the `uuid` / integer primary keys,
 * which carry no collation at all.
 *
 * Stateless and database-free (REQ-RUNTIME-002): the list is rebuilt on every call, so the service is safe to keep
 * between requests in a FrankenPHP worker with `kernel.reset: false`.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final readonly class TimeTrackExactKeyColumns implements ExactKeyColumnsInterface
{
    /** The package that owns the tables, as reported by the aggregate run. */
    public const OWNER = 'nowo-tech/time-track-bundle';

    /** Condition reported for the person reference columns with the relation mapping. */
    public const REF_CONDITION = 'nowo_time_track.user_mapping: id';

    /**
     * Table names after the configured prefix, exactly as {@see TimeTrackExtension} builds them for
     * {@see TimeTrackMetadataListener}.
     */
    public const SUFFIXES = [
        'timesheets'           => '_timesheets',
        'timesheet_events'     => '_timesheet_events',
        'balance_ledger'       => '_balance_ledger',
        'entries'              => '_entries',
        'active_timers'        => '_active_timers',
        'calendar_connections' => '_calendar_connections',
        'calendar_rules'       => '_calendar_rules',
        // Since 1.38.0: the tables whose `user_id` is the foreign key to the bundle-owned host users table.
        'host_users'           => '_host_users',
        'client_tokens'        => '_client_tokens',
        'device_codes'         => '_device_codes',
        'calendar_suggestions' => '_calendar_suggestions',
    ];

    /**
     * Tables whose `user_id` references `<prefix>_host_users.id` with `user_mapping: id`
     * ({@see TimeTrackMetadataListener::mapHostUsers()} collates the target, and the schema tool copies that collation
     * to every referencing column). The `time_track_monitoring_*` tables are deliberately left out.
     */
    public const USER_ID_TABLES = [
        'entries',
        'active_timers',
        'client_tokens',
        'device_codes',
        'timesheets',
        'balance_ledger',
        'calendar_connections',
        'calendar_suggestions',
        'calendar_rules',
    ];

    /**
     * @param string $tablePrefix nowo_time_track.table_prefix, with its trailing underscore or without it
     * @param string $userMapping nowo_time_track.user_mapping (`relation` or `id`)
     */
    public function __construct(
        private string $tablePrefix = 'time_track_',
        private string $userMapping = UserMapping::RELATION,
    ) {
    }

    /**
     * {@inheritdoc}
     */
    public function exactKeyColumns(): array
    {
        $table = fn (string $key): string => rtrim($this->tablePrefix, '_') . self::SUFFIXES[$key];

        $byId      = $this->userMapping === UserMapping::ID;
        $condition = $byId ? null : self::REF_CONDITION;

        $columns = [];
        // The tenant scope of every row the bundle scopes: exact in every configuration.
        foreach (['timesheets', 'balance_ledger', 'calendar_connections', 'calendar_rules', 'entries', 'active_timers'] as $key) {
            $columns[] = new ExactKeyColumn($table($key), 'scope_key', self::OWNER, 'tenant scope every read is filtered by', null, true);
        }

        // The person a row belongs to or was acted on by: an opaque user id only with user_mapping: id.
        foreach ([
            ['timesheets', 'owner_ref', 'owner of the timesheet (opaque user id)'],
            ['timesheets', 'submitted_by_ref', 'person who submitted the timesheet'],
            ['timesheets', 'decided_by_ref', 'person who approved or rejected the timesheet'],
            ['balance_ledger', 'owner_ref', 'owner of the ledger line (the balance chain is read by it)'],
            ['balance_ledger', 'actor_ref', 'person who caused the ledger line'],
            ['timesheet_events', 'actor_ref', 'person who caused the timesheet event'],
            ['entries', 'person_ref', 'person the time entry belongs to, also after the account is gone'],
        ] as [$key, $column, $purpose]) {
            $columns[] = new ExactKeyColumn($table($key), $column, self::OWNER, $purpose, $condition, $byId);
        }

        // Since 1.38.0: the identity of the bundle-owned host user row and every `user_id` that references it. Only a
        // mode-id column: with the relation mapping the id belongs to the host's own users table.
        $columns[] = new ExactKeyColumn($table('host_users'), 'id', self::OWNER, 'host user id (opaque user id the bundle owns)', $condition, $byId);
        foreach (self::USER_ID_TABLES as $key) {
            $columns[] = new ExactKeyColumn($table($key), 'user_id', self::OWNER, 'foreign key to the host user id (inherits its exact collation)', $condition, $byId);
        }

        return $columns;
    }
}

<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Command;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Nowo\TimeTrackBundle\Entity\ActiveTimer;
use Nowo\TimeTrackBundle\Entity\TimeEntry;
use Nowo\TimeTrackBundle\Subject\ActivityType;
use Nowo\TimeTrackBundle\Subject\ActivityTypeCatalog;
use Nowo\TimeTrackBundle\Subject\ActivityTypeList;
use Nowo\TimeTrackBundle\Subject\TimeSubjectRef;
use Nowo\TimeTrackBundle\Subject\TimeSubjectRegistry;
use Nowo\TimeTrackBundle\Timesheet\PeriodLockGuard;
use Nowo\TimeTrackBundle\Timesheet\ScopeKeys;
use SortDirection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

use function array_keys;
use function array_map;
use function count;
use function implode;
use function is_scalar;
use function is_string;
use function max;
use function min;
use function sprintf;

/**
 * Since 2.1.0 (Beacon spec 256, design §12.2): checks, **read-only**, the allocation columns of the entries and running
 * timers against the column contract after a host back-fill (Q-TS-22): half-set subject pair, malformed subject type /
 * id or activity type key, subject type with no provider, a subject next to a task whose type does not allow tasks,
 * activity type unknown or retired in the row's scope. Keyset on the id, bounded batches; one activity type list per
 * scope read. Also counts the allocated entries that sit in a locked or submitted period (Q-TS-15: back-filling them is
 * allowed, approvers may want to know). Exits non-zero on findings so a host can run it in CI or after its migration.
 * Writes nothing.
 */
#[AsCommand(
    name: 'nowo:time-track:entries:check-allocation',
    description: 'Check the subject and activity type columns of time entries and running timers (read-only).',
)]
final class CheckAllocationCommand extends Command
{
    /** Finding codes. */
    public const HALF_PAIR         = 'half_pair';
    public const MALFORMED_SUBJECT = 'malformed_subject';
    public const UNKNOWN_TYPE      = 'unknown_subject_type';
    public const TASK_DISALLOWED   = 'task_not_allowed';
    public const MALFORMED_KEY     = 'malformed_activity_type';
    public const UNKNOWN_KEY       = 'activity_type_unknown';
    public const INACTIVE_KEY      = 'activity_type_inactive';

    /**
     * @param EntityManagerInterface $entityManager The bundle's entity manager (private or `database.entity_manager`)
     * @param PeriodLockGuard|null $lockGuard Counts allocated entries in locked or submitted periods (null = timesheets off)
     */
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly TimeSubjectRegistry $subjects,
        private readonly ActivityTypeCatalog $activityTypes,
        private readonly ?PeriodLockGuard $lockGuard = null,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('scope', null, InputOption::VALUE_REQUIRED, 'Only rows of this tenant scope (exact).')
            ->addOption('batch', null, InputOption::VALUE_REQUIRED, 'Rows per batch (1-5000).', '500')
            ->addOption('fix-report', null, InputOption::VALUE_NONE, 'List every row with a finding (table, id, findings).');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io    = new SymfonyStyle($input, $output);
        $scope = $input->getOption('scope');
        if ($scope !== null && (!is_string($scope) || !ScopeKeys::isUsable($scope))) {
            $io->error('--scope must be a usable scope key.');

            return Command::INVALID;
        }
        $batch = max(1, min(5000, (int) (is_scalar($batchOption = $input->getOption('batch')) ? $batchOption : 0)));
        /** @var array<string, ActivityTypeList> $types Read once per scope for this run only. */
        $types    = [];
        $findings = [];
        $counts   = [];
        $locked   = 0;
        $checked  = 0;
        foreach ([TimeEntry::class => 'entries', ActiveTimer::class => 'active_timers'] as $class => $table) {
            $after = '';
            do {
                $rows = $this->rows($class, $after, $batch, $scope);
                foreach ($rows as $row) {
                    $after = $this->string($row['id']);
                    ++$checked;
                    $rowScope = is_scalar($row['scopeKey']) ? (string) $row['scopeKey'] : null;
                    if ($scope !== null && $rowScope !== $scope) {
                        // Only a case-folding collation returns a look-alike row here.
                        continue; // @codeCoverageIgnore
                    }
                    $codes = $this->check($row, $rowScope, $types);
                    foreach ($codes as $code) {
                        $counts[$code] = ($counts[$code] ?? 0) + 1;
                    }
                    if ($codes !== []) {
                        $findings[] = [$table, $after, implode(', ', $codes)];
                    }
                    if ($class === TimeEntry::class && $this->isLocked($row, $rowScope)) {
                        ++$locked;
                    }
                }
            } while (count($rows) === $batch);
        }

        $io->writeln(sprintf('Checked %d allocated row(s); %d allocated entr%s in a locked or submitted period.', $checked, $locked, $locked === 1 ? 'y sits' : 'ies sit'));
        if ($findings === []) {
            $io->success('Every allocated row meets the TimeTrack 2.1 column contract.');

            return Command::SUCCESS;
        }
        $io->table(['Finding', 'Rows'], array_map(static fn (string $code, int $count): array => [$code, (string) $count], array_keys($counts), $counts));
        if ($input->getOption('fix-report') === true) {
            $io->table(['Table', 'Id', 'Findings'], $findings);
        }
        $io->error(sprintf('%d row(s) break the TimeTrack 2.1 allocation column contract (see UPGRADING "To 2.1.0").', count($findings)));

        return Command::FAILURE;
    }

    /**
     * Rows with any allocation column set, after an id.
     *
     * @param class-string $class
     *
     * @return list<array<string, mixed>>
     */
    private function rows(string $class, string $after, int $batch, ?string $scope): array
    {
        $query = $this->entityManager->createQueryBuilder()
            ->select('r.id AS id', 'IDENTITY(r.user) AS userId', 'r.taskId AS taskId', 'r.scopeKey AS scopeKey', 'r.subjectType AS subjectType', 'r.subjectId AS subjectId', 'r.activityTypeKey AS activityTypeKey', 'r.startedAt AS startedAt')
            ->from($class, 'r')
            ->where('r.subjectType IS NOT NULL OR r.subjectId IS NOT NULL OR r.activityTypeKey IS NOT NULL')
            ->andWhere('r.id > :after')
            ->setParameter('after', $after)
            ->orderBy('r.id', SortDirection::Ascending)
            ->setMaxResults($batch);
        if ($class === TimeEntry::class) {
            $query->addSelect('r.personRef AS personRef');
        }
        if ($scope !== null) {
            $query->andWhere('r.scopeKey = :scope')->setParameter('scope', $scope);
        }

        /** @var list<array<string, mixed>> $rows */
        $rows = $query->getQuery()->getArrayResult();

        return $rows;
    }

    /**
     * The finding codes of one row.
     *
     * @param array<string, mixed> $row
     * @param array<string, ActivityTypeList> $types
     *
     * @return list<string>
     */
    private function check(array $row, ?string $scope, array &$types): array
    {
        $codes = [];
        $type  = is_scalar($row['subjectType']) ? (string) $row['subjectType'] : null;
        $id    = is_scalar($row['subjectId']) ? (string) $row['subjectId'] : null;
        if (($type === null) !== ($id === null)) {
            $codes[] = self::HALF_PAIR;
        } elseif ($type !== null && $id !== null) {
            if (!TimeSubjectRef::isType($type) || !TimeSubjectRef::isId($id)) {
                $codes[] = self::MALFORMED_SUBJECT;
            } elseif (!$this->subjects->has($type)) {
                $codes[] = self::UNKNOWN_TYPE;
            } elseif ($row['taskId'] !== null && !$this->subjects->allowsTask($type)) {
                $codes[] = self::TASK_DISALLOWED;
            }
        }
        $key = is_scalar($row['activityTypeKey']) ? (string) $row['activityTypeKey'] : null;
        if ($key !== null) {
            if (!ActivityType::isKey($key)) {
                $codes[] = self::MALFORMED_KEY;
            } else {
                $types[$scope ?? ''] ??= $this->activityTypes->listFor($scope);
                $found = $types[$scope ?? '']->find($key);
                if (!$found instanceof ActivityType) {
                    $codes[] = self::UNKNOWN_KEY;
                } elseif (!$found->active) {
                    $codes[] = self::INACTIVE_KEY;
                }
            }
        }

        return $codes;
    }

    /**
     * Whether an allocated entry sits in a locked or submitted period of its owner.
     *
     * @param array<string, mixed> $row
     */
    private function isLocked(array $row, ?string $scope): bool
    {
        if (!$this->lockGuard instanceof PeriodLockGuard || !$this->lockGuard->isEnabled()) {
            return false;
        }
        $startedAt = $row['startedAt'];

        return $startedAt instanceof DateTimeImmutable && $this->lockGuard->isRowBlocked(is_scalar($row['userId']) ? (string) $row['userId'] : null, is_scalar($row['personRef'] ?? null) ? (string) $row['personRef'] : null, $scope, $startedAt);
    }

    private function string(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }
}

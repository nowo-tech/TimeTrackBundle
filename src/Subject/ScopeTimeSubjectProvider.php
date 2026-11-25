<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Subject;

use Nowo\TimeTrackBundle\Port\Internal\TimesheetScopeProviderInterface;
use Nowo\TimeTrackBundle\Timesheet\ScopeKeys;
use Nowo\TimeTrackBundle\Timesheet\TimesheetScope;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Security\Core\User\UserInterface;

use function array_slice;
use function mb_stripos;

/**
 * Since 2.1.0 (Beacon spec 256, Q-TS-13): the optional built-in subject "the tenant itself" (`entries.subjects.
 * scope_subject.enabled`, default false; type `organisation` unless `entries.subjects.scope_subject.type` says
 * otherwise), over the configured timesheet scope port: internal time on the organisation under an activity type, with
 * no host code beyond activity types.
 *
 * - `canLog`: the id is the context's scope and one of the actor's scopes (byte for byte);
 * - `describe`: the scope's label, for the actor's own scopes only;
 * - `search`: the actor's scopes whose key or label contains the query;
 * - `allowsTask()`: false (internal time is never next to a task).
 *
 * Without a scope provider, or for a reference without a user object, it denies everything. Stateless.
 *
 * @internal registered by the bundle when enabled; hosts register their own provider for this type instead
 */
final readonly class ScopeTimeSubjectProvider implements TimeSubjectProviderInterface
{
    /** The default subject type. */
    public const DEFAULT_TYPE = 'organisation';

    private LoggerInterface $logger;

    /**
     * @param string $type The configured subject type
     */
    public function __construct(
        private ?TimesheetScopeProviderInterface $scopes,
        private string $type = self::DEFAULT_TYPE,
        ?LoggerInterface $logger = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * The default type; the configured one is given to the bundle's registry when the container is compiled.
     */
    public static function subjectType(): string
    {
        return self::DEFAULT_TYPE;
    }

    /**
     * Never next to a task.
     */
    public static function allowsTask(): bool
    {
        return false;
    }

    /**
     * {@inheritDoc}
     */
    public function canLog(TimeSubjectContext $context, TimeSubjectRef $subject): bool
    {
        return $subject->type === $this->type && $subject->id === $context->scopeKey && isset($this->scopesOf($context)[$subject->id]);
    }

    /**
     * {@inheritDoc}
     */
    public function describe(TimeSubjectContext $context, array $subjects): TimeSubjectLabels
    {
        $scopes = $this->scopesOf($context);
        $labels = [];
        foreach ($subjects as $subject) {
            $scope = $subject->type === $this->type ? ($scopes[$subject->id] ?? null) : null;
            if ($scope instanceof TimesheetScope) {
                $labels[] = new TimeSubjectLabel($subject, $scope->label !== '' ? $scope->label : $scope->key);
            }
        }

        return TimeSubjectLabels::of($labels);
    }

    /**
     * {@inheritDoc}
     */
    public function search(TimeSubjectContext $context, TimeSubjectSearch $search): array
    {
        $found = [];
        foreach ($this->scopesOf($context) as $scope) {
            if ($search->query === '' || mb_stripos($scope->key, $search->query) !== false || mb_stripos($scope->label, $search->query) !== false) {
                $found[] = new TimeSubjectLabel(new TimeSubjectRef($this->type, $scope->key), $scope->label !== '' ? $scope->label : $scope->key);
            }
        }

        return array_slice($found, 0, $search->limit);
    }

    /**
     * The actor's usable non-empty scopes, keyed by key (none without a provider or a security user).
     *
     * @return array<string, TimesheetScope>
     */
    private function scopesOf(TimeSubjectContext $context): array
    {
        $user = $context->actor->user();
        if (!$this->scopes instanceof TimesheetScopeProviderInterface || !$user instanceof UserInterface) {
            return [];
        }

        $keyed = [];
        foreach (ScopeKeys::usableScopes($this->scopes->scopesFor($user), $this->logger) as $scope) {
            if (TimeSubjectRef::isId($scope->key)) {
                $keyed[$scope->key] = $scope;
            }
        }

        return $keyed;
    }
}

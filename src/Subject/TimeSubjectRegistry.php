<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Subject;

use Nowo\TimeTrackBundle\DependencyInjection\Compiler\TimeSubjectProvidersPass;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Throwable;

use function array_keys;
use function array_slice;
use function array_values;
use function count;

/**
 * Since 2.1.0 (Beacon spec 256): the subject providers by type (a service locator built by
 * {@see TimeSubjectProvidersPass}) and the constant `type => allowsTask()` map read when the container was compiled.
 *
 * Every question goes through here, deny by default: an unknown type is refused (writes), hidden (reads) or empty
 * (search); a provider exception is logged with its class only and answered the same way. Stateless: the locator is
 * immutable and nothing is kept between calls (FrankenPHP worker mode, kernel reset false).
 *
 * @internal
 */
final readonly class TimeSubjectRegistry
{
    /** Most subjects one `describe()` call takes. */
    public const MAX_DESCRIBE = 1000;

    private LoggerInterface $logger;

    /**
     * @param ContainerInterface $providers The providers, keyed by subject type
     * @param array<string, bool> $allowsTask Subject type => whether it may sit next to a task
     */
    public function __construct(
        private ContainerInterface $providers,
        private array $allowsTask = [],
        ?LoggerInterface $logger = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * A registry with no provider.
     */
    public static function none(): self
    {
        return new self(new EmptySubjectProviders(), []);
    }

    /**
     * Whether a provider answers for this type.
     */
    public function has(string $type): bool
    {
        return isset($this->allowsTask[$type]) && $this->providers->has($type);
    }

    /**
     * Whether any provider is registered.
     */
    public function isEnabled(): bool
    {
        return $this->allowsTask !== [];
    }

    /**
     * The provider of a type, or null.
     */
    public function provider(string $type): ?TimeSubjectProviderInterface
    {
        if (!$this->has($type)) {
            return null;
        }
        $provider = $this->providers->get($type);

        return $provider instanceof TimeSubjectProviderInterface ? $provider : null;
    }

    /**
     * Whether a subject of this type may sit next to a task (false for an unknown type).
     */
    public function allowsTask(string $type): bool
    {
        return $this->allowsTask[$type] ?? false;
    }

    /**
     * The registered types.
     *
     * @return list<string>
     */
    public function types(): array
    {
        return array_keys($this->allowsTask);
    }

    /**
     * Whether the actor may log on the subject in the context's tenant (false for an unknown type or a failing provider).
     */
    public function canLog(TimeSubjectContext $context, TimeSubjectRef $subject): bool
    {
        $provider = $this->provider($subject->type);
        if (!$provider instanceof TimeSubjectProviderInterface) {
            return false;
        }

        try {
            return $provider->canLog($context, $subject);
        } catch (Throwable $exception) {
            $this->failed('subject_can_log', $exception);

            return false;
        }
    }

    /**
     * The labels of the readable subjects among these (all of one type), asked in batches of {@see self::MAX_DESCRIBE};
     * only labels of subjects that were asked about are kept.
     *
     * @param list<TimeSubjectRef> $subjects
     */
    public function describe(TimeSubjectContext $context, string $type, array $subjects): TimeSubjectLabels
    {
        $provider = $this->provider($type);
        $asked    = [];
        foreach ($subjects as $subject) {
            if ($subject->type === $type) {
                $asked[$subject->key()] = $subject;
            }
        }
        if (!$provider instanceof TimeSubjectProviderInterface || $asked === []) {
            return TimeSubjectLabels::none();
        }

        $labels = TimeSubjectLabels::none();
        $refs   = array_values($asked);
        for ($offset = 0; $offset < count($refs); $offset += self::MAX_DESCRIBE) {
            try {
                $labels = $labels->with($provider->describe($context, array_slice($refs, $offset, self::MAX_DESCRIBE))->onlyFor($asked));
            } catch (Throwable $exception) {
                $this->failed('subject_describe', $exception);
            }
        }

        return $labels;
    }

    /**
     * The provider's search, cut to the limit and to subjects of the searched type ([] for an unknown type or a failing
     * provider).
     *
     * @return list<TimeSubjectLabel>
     */
    public function search(TimeSubjectContext $context, TimeSubjectSearch $search): array
    {
        $provider = $this->provider($search->type);
        if (!$provider instanceof TimeSubjectProviderInterface) {
            return [];
        }

        try {
            $results = $provider->search($context, $search);
        } catch (Throwable $exception) {
            $this->failed('subject_search', $exception);

            return [];
        }

        $kept = [];
        foreach ($results as $result) {
            if ($result instanceof TimeSubjectLabel && $result->subject->type === $search->type && !isset($kept[$result->subject->key()])) {
                $kept[$result->subject->key()] = $result;
                if (count($kept) === $search->limit) {
                    break;
                }
            }
        }

        return array_values($kept);
    }

    private function failed(string $action, Throwable $exception): void
    {
        // REQ-OBS-001: the exception class only, never the actor, the subject or the message.
        $this->logger->warning('TimeTrack subject provider failed; answered as denied.', ['bundle' => 'time_track', 'action' => $action, 'exception' => $exception::class]);
    }
}

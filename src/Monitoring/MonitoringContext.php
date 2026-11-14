<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Monitoring;

use DateTimeImmutable;
use DateTimeZone;
use Nowo\TimeTrackBundle\Port\TimeTrackUserRefs;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Security\Core\User\UserInterface;
use Throwable;

/**
 * Since 1.26.0 (contract §24.1 / §24.2): the host monitoring ports as the client API uses them — whether monitoring is
 * configured at all (a policy source and an evidence store; otherwise every monitoring route answers 404), the
 * worker's policy and last acknowledgement, the `GET me` block and the acknowledgement rule of the uploads.
 *
 * Stateless: every call asks the ports again; nothing is memoised (FrankenPHP worker mode, kernel reset false).
 */
final readonly class MonitoringContext
{
    private LoggerInterface $logger;

    /**
     * Receives the host ports (null policy source or evidence store = monitoring off).
     */
    public function __construct(
        private ?MonitoringPolicySourceInterface $policySource = null,
        private ?MonitoringAcknowledgementStoreInterface $acknowledgements = null,
        private ?MonitoringEvidenceStoreInterface $evidence = null,
        ?LoggerInterface $logger = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * Whether monitoring is configured (policy source, acknowledgement store and evidence store).
     */
    public function isConfigured(): bool
    {
        return $this->policySource instanceof MonitoringPolicySourceInterface
            && $this->acknowledgements instanceof MonitoringAcknowledgementStoreInterface
            && $this->evidence instanceof MonitoringEvidenceStoreInterface;
    }

    /**
     * The host evidence store (only while configured).
     */
    public function evidence(): ?MonitoringEvidenceStoreInterface
    {
        return $this->isConfigured() ? $this->evidence : null;
    }

    /**
     * The worker's policy (null = monitoring off for them). Exceptions of the host port propagate.
     */
    public function policyFor(UserInterface $user): ?MonitoringPolicy
    {
        return $this->isConfigured() ? $this->policySource?->policyOf(TimeTrackUserRefs::fromUser($user)) : null;
    }

    /**
     * The worker's last acknowledgement. Exceptions of the store propagate.
     */
    public function lastAcknowledged(UserInterface $user): ?MonitoringAcknowledgement
    {
        return $this->isConfigured() ? $this->acknowledgements?->lastAcknowledged(TimeTrackUserRefs::fromUser($user)) : null;
    }

    /**
     * Records an acknowledgement. Exceptions of the store propagate.
     */
    public function acknowledge(UserInterface $user, MonitoringAcknowledgement $acknowledgement): void
    {
        if ($this->isConfigured()) {
            $this->acknowledgements?->acknowledge(TimeTrackUserRefs::fromUser($user), $acknowledgement);
        }
    }

    /**
     * `acknowledgement.required` of `GET me`: the worker never acknowledged a version, or the current version widens
     * collection (`acknowledgementRequired`) and is not the one they acknowledged last.
     */
    public static function acknowledgementRequired(MonitoringPolicy $policy, ?MonitoringAcknowledgement $last): bool
    {
        return !$last instanceof MonitoringAcknowledgement || ($policy->acknowledgementRequired && $last->policyVersion !== $policy->policyVersion);
    }

    /**
     * `GET me.monitoring`, or null when monitoring is off for the worker (no policy, not configured, or a host port
     * failed: then a warning without personal data is logged and nothing is collected).
     *
     * @return array<string, mixed>|null
     */
    public function summary(UserInterface $user): ?array
    {
        if (!$this->isConfigured()) {
            return null;
        }
        try {
            $policy = $this->policyFor($user);

            return $policy instanceof MonitoringPolicy ? self::block($policy, $this->lastAcknowledged($user)) : null;
        } catch (Throwable $exception) {
            $this->logger->warning('TimeTrack monitoring policy unavailable.', ['bundle' => 'time_track', 'action' => 'client_me', 'exception' => $exception::class]);

            return null;
        }
    }

    /**
     * The `monitoring` block of `GET me` (contract §24.1).
     *
     * @return array<string, mixed>
     */
    public static function block(MonitoringPolicy $policy, ?MonitoringAcknowledgement $last): array
    {
        return [
            'enabled'              => $policy->isCollecting(),
            'scope'                => $policy->scope,
            'policyVersion'        => $policy->policyVersion,
            'lockedUntilChecklist' => $policy->lockedUntilChecklist,
            'dpia'                 => ['reference' => $policy->dpiaReference, 'recordedAt' => $policy->dpiaRecordedAt, 'noticeUrl' => $policy->noticeUrl],
            'acknowledgement'      => [
                'required'            => self::acknowledgementRequired($policy, $last),
                'acknowledgedVersion' => $last?->policyVersion,
                'acknowledgedAt'      => $last instanceof MonitoringAcknowledgement ? self::utc($last->acknowledgedAt) : null,
            ],
            'kinds'   => $policy->kinds,
            'capture' => [
                'screenshotsPer10Min' => $policy->screenshotsPer10Min,
                'slotMinutes'         => $policy->slotMinutes,
                'reviewWindowMinutes' => $policy->reviewWindowMinutes,
            ],
            'screenshots' => [
                'enabled'        => $policy->allows('screenshots'),
                'blur'           => $policy->blur,
                'fullResolution' => $policy->fullResolution,
                'maxLongSidePx'  => $policy->maxLongSidePx,
            ],
            'windowTitles'  => $policy->windowTitles,
            'excludedApps'  => $policy->excludedApps,
            'retentionDays' => $policy->retentionDays,
            'upload'        => ['maxBufferedScreenshots' => $policy->maxBufferedScreenshots, 'maxBufferAgeHours' => $policy->maxBufferAgeHours],
        ];
    }

    /**
     * `Y-m-d\TH:i:s\Z` of an instant.
     */
    public static function utc(DateTimeImmutable $time): string
    {
        return $time->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
    }
}

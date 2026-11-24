<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Monitoring;

use InvalidArgumentException;

use function array_unique;
use function array_values;
use function count;
use function in_array;
use function is_string;
use function mb_strlen;
use function preg_match;
use function sprintf;
use function str_starts_with;
use function trigger_deprecation;
use function trim;

/**
 * Since 1.26.0 (contract §24.1, spec 199): the activity-monitoring policy that applies to one worker, as the host's
 * {@see MonitoringPolicySourceInterface} returns it (null there = monitoring off for the worker). Framework-free
 * (REQ-ARCH-001 rule 3): scalars and lists only.
 *
 * The bundle publishes it on `GET me` as the `monitoring` block and checks every upload against it. Two fields are not
 * published: `noticeSha256` (the SHA-256 of the notice text the host renders at `noticeUrl` for this version, compared
 * with the hash the client sends when it acknowledges, `422 notice_mismatch`) and `acknowledgementRequired` (whether
 * this version widens collection compared with the last version the worker acknowledged; the bundle publishes
 * `acknowledgement.required` from it and from its acknowledgement store).
 *
 * Since 1.41.0 (owner decisions D4 and D9 of the 2026-11-24 monitoring study): window titles are never collected —
 * `windowTitles` is frozen to false (a true value is ignored with a deprecation notice) — and the policy declares its
 * `purpose` and the `teamView` third parties get: `project_aggregates` (default) or `per_person`, which is valid only for
 * the `billing_evidence` purpose of an organisation policy and only with the date the organisation opted in
 * (`perPersonDetailRecordedAt`, the audit trail of the decision). Hosts enforce it with {@see MonitoringTeamViewGuard}.
 *
 * The constructor refuses a value outside the contract (a host bug), with an `InvalidArgumentException`.
 */
final readonly class MonitoringPolicy
{
    /** Kinds a policy can enable in 1.26 (websites / domains come with a later extension release, Q-B2). */
    public const KINDS = ['screenshots', 'apps', 'activity'];

    /** Organisation policy or personal self-analytics. */
    public const SCOPES = ['organization', 'personal'];

    /** Named blur levels both sides know (client-side blur, §24.3). */
    public const BLUR_LEVELS = ['none', 'low', 'medium', 'high'];

    /** Upper bound of `maxBufferAgeHours` (owner Q-B4: 24 h). */
    public const MAX_BUFFER_AGE_HOURS = 24;

    /** Upper bound of `maxBufferedScreenshots` (owner Q-B4: 36). */
    public const MAX_BUFFERED_SCREENSHOTS = 36;

    /** Hard cap of the long side of an uploaded screenshot, in pixels. */
    public const MAX_LONG_SIDE_PX = 3840;

    /** Since 1.41.0: the written purpose of an organisation policy (study §2.3 condition 1). */
    public const PURPOSES = [self::PURPOSE_BILLING_EVIDENCE, 'project_costing', 'self_analytics'];

    /** Since 1.41.0: evidence of hours billed to a client — the only purpose that may show per-person detail (D9). */
    public const PURPOSE_BILLING_EVIDENCE = 'billing_evidence';

    /** Since 1.41.0: third parties (`view_team` holders) see totals per project only, never one person's slots. */
    public const TEAM_VIEW_PROJECT_AGGREGATES = 'project_aggregates';

    /** Since 1.41.0: third parties may open one person's slots (billing evidence only; every view is audited). */
    public const TEAM_VIEW_PER_PERSON = 'per_person';

    /** Since 1.41.0. */
    public const TEAM_VIEWS = [self::TEAM_VIEW_PROJECT_AGGREGATES, self::TEAM_VIEW_PER_PERSON];

    /** @var list<string> enabled kinds, in the order of {@see self::KINDS} */
    public array $kinds;

    /** @var list<string> */
    public array $excludedApps;

    /** Since 1.41.0: the first policy version with `per_person` (null without `per_person`). */
    public ?int $perPersonSinceVersion;

    /**
     * Always false since 1.41.0 (D4: window titles and project names are never sent, never stored, never returned).
     *
     * @deprecated since 1.41.0, always false; 2.0 removes it
     */
    public bool $windowTitles;

    /**
     * @param bool $enabled Whether collection is on (published as false while `lockedUntilChecklist` is true)
     * @param int $policyVersion Strictly increasing version, bumped by every policy change (≥ 1)
     * @param string $noticeUrl https page of the notice for this version
     * @param string $noticeSha256 Lowercase hex SHA-256 of the notice text rendered for this version (never published)
     * @param list<string> $kinds Enabled kinds, a subset of {@see self::KINDS}
     * @param bool $acknowledgementRequired This version widens collection compared with the worker's last acknowledged version
     * @param string $scope `organization` or `personal`
     * @param bool $lockedUntilChecklist The organisation's checklist is incomplete: nothing is collected
     * @param string|null $dpiaReference DPIA id (never the file)
     * @param string|null $dpiaRecordedAt Date the DPIA was recorded (`Y-m-d`)
     * @param int $screenshotsPer10Min 1 in v1 (0 only without the screenshots kind)
     * @param int $slotMinutes 1-60
     * @param int $reviewWindowMinutes 0-60
     * @param string $blur One of {@see self::BLUR_LEVELS}; `none` only with `fullResolution`
     * @param bool $fullResolution Full-resolution uploads allowed (the checklist recorded why)
     * @param int $maxLongSidePx 1-3840
     * @param bool $windowTitles Deprecated since 1.41.0 and ignored: window titles are never collected (true triggers a deprecation notice)
     * @param list<string> $excludedApps Apps never captured (≤ 100 names of ≤ 120 characters)
     * @param int $retentionDays 1-3650
     * @param int $maxBufferedScreenshots 0-36
     * @param int $maxBufferAgeHours 1-24
     * @param string|null $purpose Since 1.41.0: one of {@see self::PURPOSES} (null = not declared, as before 1.41)
     * @param string $teamView Since 1.41.0: one of {@see self::TEAM_VIEWS}; `per_person` only with the `billing_evidence` purpose, the `organization` scope and `perPersonDetailRecordedAt`
     * @param string|null $perPersonDetailRecordedAt Since 1.41.0: date (`Y-m-d`) the organisation opted in to per-person detail (required with `per_person`, null otherwise)
     * @param int|null $perPersonSinceVersion Since 1.41.0 (audit ZBB-D-M1): the first policy version with `per_person` (1..`policyVersion`; null = this version). Per-person detail needs the worker's acknowledgement of this version or a later one, whatever `acknowledgementRequired` says
     *
     * @throws InvalidArgumentException when a value is outside the contract
     */
    public function __construct(
        public bool $enabled,
        public int $policyVersion,
        public string $noticeUrl,
        public string $noticeSha256,
        array $kinds,
        public bool $acknowledgementRequired = true,
        public string $scope = 'organization',
        public bool $lockedUntilChecklist = false,
        public ?string $dpiaReference = null,
        public ?string $dpiaRecordedAt = null,
        public int $screenshotsPer10Min = 1,
        public int $slotMinutes = 10,
        public int $reviewWindowMinutes = 10,
        public string $blur = 'medium',
        public bool $fullResolution = false,
        public int $maxLongSidePx = 1920,
        bool $windowTitles = false,
        array $excludedApps = [],
        public int $retentionDays = 30,
        public int $maxBufferedScreenshots = self::MAX_BUFFERED_SCREENSHOTS,
        public int $maxBufferAgeHours = self::MAX_BUFFER_AGE_HOURS,
        public ?string $purpose = null,
        public string $teamView = self::TEAM_VIEW_PROJECT_AGGREGATES,
        public ?string $perPersonDetailRecordedAt = null,
        ?int $perPersonSinceVersion = null,
    ) {
        if ($windowTitles) {
            trigger_deprecation('nowo-tech/time-track-bundle', '1.41.0', 'The "windowTitles" argument of %s is ignored: window titles are never collected (owner decision D4); stop passing it, 2.0 removes it.', self::class);
        }
        $this->windowTitles = false;
        foreach ($kinds as $kind) {
            if (!in_array($kind, self::KINDS, true)) {
                throw new InvalidArgumentException(sprintf('Unknown monitoring kind; expected one of %s.', implode(', ', self::KINDS)));
            }
        }
        $this->kinds = array_values(array_filter(self::KINDS, static fn (string $kind): bool => in_array($kind, $kinds, true)));
        $apps        = [];
        foreach ($excludedApps as $app) {
            if (!is_string($app) || trim($app) === '' || mb_strlen($app) > 120) {
                throw new InvalidArgumentException('An excluded app is a non-empty name of at most 120 characters.');
            }
            $apps[] = $app;
        }
        $this->excludedApps = array_values(array_unique($apps));
        if (count($this->excludedApps) > 100) {
            throw new InvalidArgumentException('At most 100 excluded apps.');
        }

        $this->check($policyVersion >= 1, 'policyVersion must be at least 1.');
        $this->check(str_starts_with($noticeUrl, 'https://') && preg_match('/\s/', $noticeUrl) !== 1, 'noticeUrl must be an https URL.');
        $this->check(preg_match('/^[0-9a-f]{64}$/D', $noticeSha256) === 1, 'noticeSha256 must be a lowercase hex SHA-256.');
        $this->check(in_array($scope, self::SCOPES, true), 'scope must be organization or personal.');
        $this->check($dpiaReference === null || (trim($dpiaReference) !== '' && mb_strlen($dpiaReference) <= 64), 'dpiaReference must be 1-64 characters.');
        $this->check($dpiaRecordedAt === null || preg_match('/^\d{4}-\d{2}-\d{2}$/D', $dpiaRecordedAt) === 1, 'dpiaRecordedAt must be a Y-m-d date.');
        // Audit PM-I6 (Beacon): one screenshot per slot in v1 — the server keeps one per (user, slot) and the rate limits
        // are sized for it; 0 only when screenshots are not collected.
        $this->check($screenshotsPer10Min === 1 || ($screenshotsPer10Min === 0 && !in_array('screenshots', $this->kinds, true)), 'screenshotsPer10Min must be 1 (0 only without the screenshots kind).');
        $this->check($slotMinutes >= 1 && $slotMinutes <= 60, 'slotMinutes must be 1-60.');
        $this->check($reviewWindowMinutes >= 0 && $reviewWindowMinutes <= 60, 'reviewWindowMinutes must be 0-60.');
        $this->check(in_array($blur, self::BLUR_LEVELS, true), 'blur must be none, low, medium or high.');
        $this->check($blur !== 'none' || $fullResolution, 'blur "none" is only valid with fullResolution.');
        $this->check($maxLongSidePx >= 1 && $maxLongSidePx <= self::MAX_LONG_SIDE_PX, 'maxLongSidePx must be 1-3840.');
        $this->check($retentionDays >= 1 && $retentionDays <= 3650, 'retentionDays must be 1-3650.');
        $this->check($maxBufferedScreenshots >= 0 && $maxBufferedScreenshots <= self::MAX_BUFFERED_SCREENSHOTS, 'maxBufferedScreenshots must be 0-36.');
        $this->check($maxBufferAgeHours >= 1 && $maxBufferAgeHours <= self::MAX_BUFFER_AGE_HOURS, 'maxBufferAgeHours must be 1-24.');
        $this->check($purpose === null || in_array($purpose, self::PURPOSES, true), 'purpose must be billing_evidence, project_costing or self_analytics.');
        $this->check(in_array($teamView, self::TEAM_VIEWS, true), 'teamView must be project_aggregates or per_person.');
        $perPerson = $teamView === self::TEAM_VIEW_PER_PERSON;
        $this->check(!$perPerson || ($purpose === self::PURPOSE_BILLING_EVIDENCE && $scope === 'organization'), 'teamView per_person needs the billing_evidence purpose of an organization policy.');
        $this->check($perPerson ? ($perPersonDetailRecordedAt !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/D', $perPersonDetailRecordedAt) === 1) : $perPersonDetailRecordedAt === null, 'perPersonDetailRecordedAt must be the Y-m-d opt-in date with teamView per_person, and null otherwise.');
        $this->check(!$perPerson ? $perPersonSinceVersion === null : ($perPersonSinceVersion === null || ($perPersonSinceVersion >= 1 && $perPersonSinceVersion <= $policyVersion)), 'perPersonSinceVersion must be 1..policyVersion with teamView per_person, and null otherwise.');
        $this->perPersonSinceVersion = $perPerson ? ($perPersonSinceVersion ?? $policyVersion) : null;
    }

    /**
     * Whether anything is collected under this policy: enabled and the checklist complete.
     */
    public function isCollecting(): bool
    {
        return $this->enabled && !$this->lockedUntilChecklist;
    }

    /**
     * Whether the kind is collected under this policy.
     */
    public function allows(string $kind): bool
    {
        return $this->isCollecting() && in_array($kind, $this->kinds, true);
    }

    /**
     * Since 1.41.0: whether third parties may see one person's slots under this policy (D9): only a collecting
     * organisation policy whose purpose is billing evidence and that recorded the opt-in.
     */
    public function allowsPerPersonDetail(): bool
    {
        return $this->isCollecting() && $this->teamView === self::TEAM_VIEW_PER_PERSON;
    }

    private function check(bool $condition, string $message): void
    {
        if (!$condition) {
            throw new InvalidArgumentException('Invalid monitoring policy: ' . $message);
        }
    }
}

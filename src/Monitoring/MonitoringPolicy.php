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

    /** @var list<string> enabled kinds, in the order of {@see self::KINDS} */
    public array $kinds;

    /** @var list<string> */
    public array $excludedApps;

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
     * @param bool $windowTitles Window titles allowed (redacted)
     * @param list<string> $excludedApps Apps never captured (≤ 100 names of ≤ 120 characters)
     * @param int $retentionDays 1-3650
     * @param int $maxBufferedScreenshots 0-36
     * @param int $maxBufferAgeHours 1-24
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
        public bool $windowTitles = false,
        array $excludedApps = [],
        public int $retentionDays = 30,
        public int $maxBufferedScreenshots = self::MAX_BUFFERED_SCREENSHOTS,
        public int $maxBufferAgeHours = self::MAX_BUFFER_AGE_HOURS,
    ) {
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

    private function check(bool $condition, string $message): void
    {
        if (!$condition) {
            throw new InvalidArgumentException('Invalid monitoring policy: ' . $message);
        }
    }
}

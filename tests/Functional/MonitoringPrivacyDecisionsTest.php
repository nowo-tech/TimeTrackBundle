<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Functional;

use Nowo\TimeTrackBundle\Event\MonitoringPersonDetailViewedEvent;
use Nowo\TimeTrackBundle\Event\TimeTrackEvents;
use Nowo\TimeTrackBundle\Monitoring\ActivityItem;
use Nowo\TimeTrackBundle\Monitoring\MonitoringPersonDetailGrant;
use Nowo\TimeTrackBundle\Monitoring\MonitoringTeamDetailDeniedException;
use Nowo\TimeTrackBundle\Monitoring\MonitoringTeamViewGuard;
use Nowo\TimeTrackBundle\Port\TimeTrackUserRefs;
use Nowo\TimeTrackBundle\Tests\Stub\MutableProfileProvider;
use Nowo\TimeTrackBundle\Tests\Stub\StubMonitoringEvidenceStore;
use Nowo\TimeTrackBundle\Tests\Stub\StubMonitoringPolicySource;
use Nowo\TimeTrackBundle\Tests\Stub\StubWorkdayHistorySource;
use Nowo\TimeTrackBundle\Tests\Support\FixedWorkdayRegister;
use Nowo\TimeTrackBundle\Tests\Support\MonitoringFixtures;
use RuntimeException;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

use function array_map;
use function gmdate;
use function json_encode;

/**
 * Since 1.41.0 (owner decisions D4, D9 and D11 of the 2026-11-24 monitoring study), end to end on the unversioned and
 * the v1 paths: window titles are never published, stored or logged; the worker sees the purpose and the team view;
 * the team-view guard allows per-person detail only for billing evidence and records every view; evidence taken during
 * a clocked break or after clock-out is refused with `on_break`.
 */
final class MonitoringPrivacyDecisionsTest extends MonitoringTestCase
{
    protected static function timeTrackConfig(): array
    {
        return ['client_api' => [
            'profile_provider' => MutableProfileProvider::class,
            'workday_provider' => StubWorkdayHistorySource::class,
            'rate_limit'       => ['per_token' => 0, 'per_ip' => 0],
            'monitoring'       => [
                'policy_source'  => StubMonitoringPolicySource::class,
                'evidence_store' => StubMonitoringEvidenceStore::class,
                'rate_limit'     => ['intents_per_10_min' => 0, 'uploads_per_10_min' => 0, 'activity_per_10_min' => 0],
            ],
        ]];
    }

    public function testWindowTitlesAreNeverPublishedStoredOrLogged(): void
    {
        $this->policySource()->policy = MonitoringFixtures::policy();
        foreach (['/api/time-track/me', '/api/time-track/v1/me'] as $path) {
            $me = $this->api('GET', $path, null, $this->login());
            self::assertIsArray($me['monitoring'] ?? null, $path);
            self::assertArrayNotHasKey('windowTitles', $me['monitoring'], $path . ': 2.0 removed the key (always false since 1.41)');
        }

        $token = $this->token();
        $this->acknowledge($token);
        $timer = $this->startTimer($token);
        $this->clockedIn();
        $slot   = gmdate('Y-m-d\TH:i:s\Z', $this->now() - $this->now() % 600);
        $title  = 'ZZ-TITLE Payroll 2026 - Confidential.xlsx';
        $answer = $this->api('POST', '/api/time-track/v1/monitoring/activity', ['items' => [
            ['entryId' => $timer, 'slot' => $slot, 'kind' => 'app', 'name' => 'Excel', 'seconds' => 120, 'windowTitle' => $title, 'title' => $title, 'project' => $title],
        ], 'windowTitles' => [$title]], $token, ['HTTP_IDEMPOTENCY_KEY' => 'titles-1']);
        self::assertResponseStatusCodeSame(202);
        self::assertSame(['accepted' => 1, 'dropped' => 0, 'droppedSeconds' => 0], $answer, 'the title fields are ignored, the item is kept');
        $stored = $this->evidence()->activity;
        self::assertCount(1, $stored);
        self::assertSame(['Excel'], array_map(static fn (ActivityItem $item): ?string => $item->name, $stored[0]['items']));
        self::assertStringNotContainsString('ZZ-TITLE', (string) json_encode(array_map(static fn (ActivityItem $item): array => (array) $item, $stored[0]['items'])));
        self::assertNotContains($title, $this->logger()->contextValues());
        self::assertStringNotContainsString('ZZ-TITLE', (string) json_encode($this->logger()->records));
    }

    public function testTheWorkerSeesThePurposeAndTheTeamView(): void
    {
        $this->policySource()->policy = MonitoringFixtures::policy(overrides: ['purpose' => 'billing_evidence', 'teamView' => 'per_person', 'perPersonDetailRecordedAt' => '2026-11-24']);
        $me                           = $this->api('GET', '/api/time-track/v1/me', null, $this->login());
        self::assertSame(['billing_evidence', 'per_person'], [$me['monitoring']['purpose'] ?? null, $me['monitoring']['teamView'] ?? null]);
        self::assertStringNotContainsString('2026-11-24', (string) json_encode($me['monitoring'] ?? []), 'the opt-in date stays with the host');
    }

    public function testTheTeamViewGuard(): void
    {
        $guard = self::getContainer()->get(MonitoringTeamViewGuard::class);
        self::assertInstanceOf(MonitoringTeamViewGuard::class, $guard);
        $dispatcher = self::getContainer()->get('event_dispatcher');
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);
        $viewed = [];
        $dispatcher->addListener(TimeTrackEvents::MONITORING_PERSON_DETAIL_VIEWED, static function (MonitoringPersonDetailViewedEvent $event) use (&$viewed): void {
            $viewed[] = $event;
        });
        $manager = TimeTrackUserRefs::fromUser($this->createUser('manager@example.com'));
        $worker  = TimeTrackUserRefs::fromUser($this->user);

        $denied = static function (callable $call): string {
            try {
                $call();
            } catch (MonitoringTeamDetailDeniedException $exception) {
                return $exception->reason;
            }

            return 'allowed';
        };
        self::assertSame('aggregates_only', $denied(static fn (): MonitoringPersonDetailGrant => $guard->authorizePersonDetail($manager, $worker)), 'default: project aggregates');
        $this->policySource()->policy = MonitoringFixtures::policy(overrides: ['purpose' => 'project_costing']);
        self::assertSame('aggregates_only', $denied(static fn (): MonitoringPersonDetailGrant => $guard->authorizePersonDetail($manager, $worker)));
        $this->policySource()->policy = MonitoringFixtures::policy(overrides: ['scope' => 'personal']);
        self::assertSame('personal', $denied(static fn (): MonitoringPersonDetailGrant => $guard->authorizePersonDetail($manager, $worker)));
        $this->policySource()->policy = null;
        self::assertSame('no_policy', $denied(static fn (): MonitoringPersonDetailGrant => $guard->authorizePersonDetail($manager, $worker)));
        self::assertSame([], $viewed, 'a refused view leaves no view record');

        $today                        = gmdate('Y-m-d', $this->now());
        $this->policySource()->policy = MonitoringFixtures::policy(overrides: ['purpose' => 'billing_evidence', 'teamView' => 'per_person', 'perPersonDetailRecordedAt' => $today, 'lockedUntilChecklist' => true]);
        self::assertSame('aggregates_only', $denied(static fn (): MonitoringPersonDetailGrant => $guard->authorizePersonDetail($manager, $worker)), 'a policy that collects nothing shows nothing');
        $this->policySource()->policy = MonitoringFixtures::policy(overrides: ['purpose' => 'billing_evidence', 'teamView' => 'per_person', 'perPersonDetailRecordedAt' => $today]);
        self::assertSame('not_acknowledged', $denied(static fn (): MonitoringPersonDetailGrant => $guard->authorizePersonDetail($manager, $worker)), 'the worker has not accepted the per-person policy');
        $this->acknowledge($this->token());
        $grant = $guard->authorizePersonDetail($manager, $worker);
        self::assertSame($this->now(), $grant->visibleSince?->getTimestamp(), 'visible from the acknowledgement (after the opt-in day started)');
        self::assertFalse($grant->covers($this->at('-1 second')));
        self::assertCount(1, $viewed, 'the view is recorded before anything is shown');
        self::assertSame([$manager->id, $worker->id, 7, 'billing_evidence'], [$viewed[0]->viewer->id, $viewed[0]->worker->id, $viewed[0]->policyVersion, $viewed[0]->purpose]);
        self::assertSame('allowed', $denied(static fn (): MonitoringPersonDetailGrant => $guard->authorizePersonDetail($worker, $worker)), 'the worker\'s own view');
        self::assertCount(1, $viewed, 'the own view is not a third-party view');

        $dispatcher->addListener(TimeTrackEvents::MONITORING_PERSON_DETAIL_VIEWED, static function (): never {
            throw new RuntimeException('audit store down');
        });
        try {
            $guard->authorizePersonDetail($manager, $worker);
            self::fail('a failed audit record stops the view');
        } catch (RuntimeException $exception) {
            self::assertSame('audit store down', $exception->getMessage());
        }
    }

    public function testOnBreakAndAfterClockOut(): void
    {
        $token = $this->token();
        $this->acknowledge($token);
        $timer   = $this->startTimer($token);
        $image   = MonitoringFixtures::webp(1280, 720);
        $now     = $this->now();
        $day     = gmdate('Y-m-d', $now);
        $iso     = static fn (int $time): string => gmdate('Y-m-d\TH:i:s\Z', $time);
        $refused = function (array $body, string $key) use ($token): void {
            $answer = $this->intent($token, $body, $key);
            self::assertResponseStatusCodeSame(422, $key);
            self::assertSame(['monitoring_refused', 'on_break'], [$answer['error'] ?? null, $answer['reason'] ?? null], $key);
        };

        // The workday is on a break that started 5 minutes ago; the task timer keeps running.
        $this->register()->today = $day;
        $this->register()->day($day, 'on_break', [['work', $iso($now - 3600), $iso($now - 300)], ['break', $iso($now - 300), null]]);
        $refused($this->intentBody($timer, $image), 'break');
        $slot   = $iso($now - $now % 600);
        $answer = $this->api('POST', self::ACTIVITY, ['items' => [['entryId' => $timer, 'slot' => $slot, 'kind' => 'level', 'seconds' => 600, 'level' => 50]]], $token, ['HTTP_IDEMPOTENCY_KEY' => 'break-activity']);
        self::assertSame(['accepted' => 0, 'dropped' => 1, 'droppedSeconds' => 0], $answer, 'activity of a break is dropped, never stored');
        self::assertSame([], $this->evidence()->activity);

        // Clocked out 5 minutes ago.
        $this->register()->day($day, 'closed', [['work', $iso($now - 3600), $iso($now - 300)]]);
        $refused($this->intentBody($timer, $image), 'clocked-out');

        // Back to work: accepted.
        $this->register()->day($day, 'working', [['work', $iso($now - 3600), null]]);
        $this->intent($token, $this->intentBody($timer, $image), 'working');
        self::assertResponseStatusCodeSame(201);

        // A buffered capture of an earlier day, after that day's clock-out (history port).
        $yesterday = $now - 86_400 + 3600;
        $entry     = $this->recordedEntry($this->at('-24 hours'), $this->at('-22 hours'));
        $this->register()->day(gmdate('Y-m-d', $yesterday), 'closed', [['work', $iso($now - 86_400), $iso($now - 86_400 + 1800)]]);
        $refused($this->intentBody($entry, $image, takenAt: $yesterday), 'yesterday');
        self::assertSame([], $this->evidence()->screenshots, 'nothing was stored');
    }

    private function register(): FixedWorkdayRegister
    {
        $source = self::getContainer()->get(StubWorkdayHistorySource::class);
        self::assertInstanceOf(StubWorkdayHistorySource::class, $source);

        return $source->register;
    }

    /**
     * The register shows the worker clocked in since an hour ago (no break).
     */
    private function clockedIn(): void
    {
        $now                     = $this->now();
        $this->register()->today = gmdate('Y-m-d', $now);
        $this->register()->day(gmdate('Y-m-d', $now), 'working', [['work', gmdate('Y-m-d\TH:i:s\Z', $now - 3600), null]]);
    }
}

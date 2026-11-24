<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Unit\Monitoring;

use DateTimeImmutable;
use InvalidArgumentException;
use Nowo\TimeTrackBundle\Event\MonitoringPersonDetailViewedEvent;
use Nowo\TimeTrackBundle\Event\TimeTrackEvents;
use Nowo\TimeTrackBundle\Monitoring\MonitoringAcknowledgement;
use Nowo\TimeTrackBundle\Monitoring\MonitoringContext;
use Nowo\TimeTrackBundle\Monitoring\MonitoringPersonDetailGrant;
use Nowo\TimeTrackBundle\Monitoring\MonitoringPolicy;
use Nowo\TimeTrackBundle\Monitoring\MonitoringPolicySourceInterface;
use Nowo\TimeTrackBundle\Monitoring\MonitoringTeamDetailDeniedException;
use Nowo\TimeTrackBundle\Monitoring\MonitoringTeamViewGuard;
use Nowo\TimeTrackBundle\Port\WorkdaySourceGate;
use Nowo\TimeTrackBundle\Tests\Support\FixedWorkdayRegister;
use Nowo\TimeTrackBundle\Tests\Support\InMemoryMonitoringAcknowledgementStore;
use Nowo\TimeTrackBundle\Tests\Support\MonitoringFixtures;
use Nowo\TimeTrackBundle\Tests\Support\RecordingLogger;
use Nowo\TimeTrackBundle\ValueObject\TimeTrackUserRef;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\EventDispatcher\EventDispatcher;

use function json_encode;
use function restore_error_handler;
use function set_error_handler;

use const E_USER_DEPRECATED;

/**
 * Since 1.41.0 (owner decisions D4 and D9 of the 2026-11-24 monitoring study): `windowTitles` frozen to false with a
 * deprecation notice, the purpose and team-view rules of the policy, the `GET me` block and the team-view guard.
 */
final class MonitoringPrivacyDecisionsTest extends TestCase
{
    public function testWindowTitlesAreFrozenToFalseWithANotice(): void
    {
        $notices = [];
        // @phpstan-ignore frankenphp.worker.noSetErrorExceptionHandler (test only: restored in the finally block below)
        set_error_handler(static function (int $level, string $message) use (&$notices): bool {
            $notices[] = $message;

            return true;
        }, E_USER_DEPRECATED);
        try {
            $titles = MonitoringFixtures::policy(overrides: ['windowTitles' => true]);
            $plain  = MonitoringFixtures::policy(overrides: ['windowTitles' => false]);
        } finally {
            restore_error_handler();
        }

        self::assertFalse($titles->windowTitles);
        self::assertFalse($plain->windowTitles);
        self::assertCount(1, $notices, 'only a true value is reported');
        self::assertStringContainsString('"windowTitles" argument', $notices[0]);
        self::assertStringContainsString('2.0 removes it', $notices[0]);
        self::assertFalse(MonitoringContext::block($titles, null)['windowTitles']);
    }

    public function testTheBlockPublishesThePurposeAndTheTeamView(): void
    {
        $block = MonitoringContext::block(MonitoringFixtures::policy(), null);
        self::assertSame([null, 'project_aggregates'], [$block['purpose'], $block['teamView']]);

        $billing = $this->billing();
        $block   = MonitoringContext::block($billing, null);
        self::assertSame(['billing_evidence', 'per_person'], [$block['purpose'], $block['teamView']]);
        self::assertStringNotContainsString('2026-11-24', (string) json_encode($block));
        self::assertTrue($billing->allowsPerPersonDetail());
        self::assertSame('per_person', MonitoringTeamViewGuard::granularity($billing));
        self::assertSame('project_aggregates', MonitoringTeamViewGuard::granularity(null));
        self::assertSame('project_aggregates', MonitoringTeamViewGuard::granularity(MonitoringFixtures::policy(overrides: ['purpose' => 'billing_evidence'])), 'the purpose alone does not open per-person detail');
        self::assertFalse($this->billing(['enabled' => false])->allowsPerPersonDetail());
        foreach (MonitoringPolicy::PURPOSES as $purpose) {
            self::assertSame($purpose, MonitoringFixtures::policy(overrides: ['purpose' => $purpose])->purpose);
        }
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function invalidTeamViews(): iterable
    {
        yield 'unknown purpose' => [['purpose' => 'productivity']];
        yield 'unknown team view' => [['teamView' => 'everyone']];
        yield 'per person without a purpose' => [['teamView' => 'per_person', 'perPersonDetailRecordedAt' => '2026-11-24']];
        yield 'per person for project costing' => [['purpose' => 'project_costing', 'teamView' => 'per_person', 'perPersonDetailRecordedAt' => '2026-11-24']];
        yield 'per person without the opt-in date' => [['purpose' => 'billing_evidence', 'teamView' => 'per_person']];
        yield 'per person with a bad date' => [['purpose' => 'billing_evidence', 'teamView' => 'per_person', 'perPersonDetailRecordedAt' => '24/11/2026']];
        yield 'per person of a personal policy' => [['purpose' => 'billing_evidence', 'teamView' => 'per_person', 'perPersonDetailRecordedAt' => '2026-11-24', 'scope' => 'personal']];
        yield 'an opt-in date without per person' => [['purpose' => 'billing_evidence', 'perPersonDetailRecordedAt' => '2026-11-24']];
    }

    /**
     * @param array<string, mixed> $overrides
     */
    #[DataProvider('invalidTeamViews')]
    public function testTheTeamViewRules(array $overrides): void
    {
        $this->expectException(InvalidArgumentException::class);
        MonitoringFixtures::policy(overrides: $overrides);
    }

    public function testTheGuard(): void
    {
        $source = new class implements MonitoringPolicySourceInterface {
            public ?MonitoringPolicy $policy = null;

            public function policyOf(TimeTrackUserRef $user): ?MonitoringPolicy
            {
                return $this->policy;
            }
        };
        $acks       = new InMemoryMonitoringAcknowledgementStore();
        $dispatcher = new EventDispatcher();
        $seen       = [];
        $dispatcher->addListener(TimeTrackEvents::MONITORING_PERSON_DETAIL_VIEWED, static function (MonitoringPersonDetailViewedEvent $event) use (&$seen): void {
            $seen[] = $event;
        });
        $logger = new RecordingLogger();
        $clock  = new MockClock('2026-11-24 10:00:00 UTC');
        $guard  = new MonitoringTeamViewGuard($source, $dispatcher, $clock, $logger, $acks);
        $viewer = new TimeTrackUserRef('App\\User', 'manager-1');
        $worker = new TimeTrackUserRef('App\\User', 'worker-1');
        $reason = static function (callable $call): string {
            try {
                $call();
            } catch (MonitoringTeamDetailDeniedException $exception) {
                return $exception->reason;
            }

            return 'allowed';
        };

        self::assertSame('unknown_user', $reason(static fn (): MonitoringPersonDetailGrant => $guard->authorizePersonDetail(new TimeTrackUserRef('App\\User', null), $worker)));
        self::assertSame('unknown_user', $reason(static fn (): MonitoringPersonDetailGrant => $guard->authorizePersonDetail($viewer, new TimeTrackUserRef('App\\User', null))));
        self::assertSame('no_policy', $reason(static fn (): MonitoringPersonDetailGrant => $guard->authorizePersonDetail($viewer, $worker)));
        $own = $guard->authorizePersonDetail($worker, $worker);
        self::assertTrue($own->ownView);
        self::assertNull($own->policy, 'own view without a policy');
        self::assertTrue($own->covers(new DateTimeImmutable('2000-01-01')), 'the worker sees all of their own evidence');

        // Audit ZBB-M1: another user class with the same id is a third party.
        $source->policy = MonitoringFixtures::policy();
        $admin          = new TimeTrackUserRef('App\\Admin', 'worker-1');
        self::assertSame('aggregates_only', $reason(static fn (): MonitoringPersonDetailGrant => $guard->authorizePersonDetail($admin, $worker)));

        // Audit ZBB-M2: the opt-in date has come and the worker acknowledged after it.
        $source->policy = $this->billing(['perPersonDetailRecordedAt' => '2026-11-25']);
        self::assertSame('opt_in_pending', $reason(static fn (): MonitoringPersonDetailGrant => $guard->authorizePersonDetail($viewer, $worker)), 'a future opt-in date');
        $source->policy = $this->billing();
        self::assertSame('not_acknowledged', $reason(static fn (): MonitoringPersonDetailGrant => $guard->authorizePersonDetail($viewer, $worker)), 'never acknowledged');
        $acks->last = new MonitoringAcknowledgement(7, new DateTimeImmutable('2026-11-23 18:00:00 UTC'), 'desktop', MonitoringFixtures::noticeSha256());
        self::assertSame('not_acknowledged', $reason(static fn (): MonitoringPersonDetailGrant => $guard->authorizePersonDetail($viewer, $worker)), 'acknowledged before the opt-in');
        $acks->last = new MonitoringAcknowledgement(6, new DateTimeImmutable('2026-11-24 09:00:00 UTC'), 'desktop', MonitoringFixtures::noticeSha256());
        self::assertSame('not_acknowledged', $reason(static fn (): MonitoringPersonDetailGrant => $guard->authorizePersonDetail($viewer, $worker)), 'an older version than the widening one');
        $acks->last = new MonitoringAcknowledgement(7, new DateTimeImmutable('2026-11-24 09:00:00 UTC'), 'desktop', MonitoringFixtures::noticeSha256());
        self::assertSame([], $seen, 'a refused view leaves no record');

        $grant = $guard->authorizePersonDetail($viewer, $worker);
        self::assertFalse($grant->ownView);
        self::assertEquals(new DateTimeImmutable('2026-11-24 09:00:00 UTC'), $grant->visibleSince, 'the later of the opt-in and the acknowledgement');
        self::assertFalse($grant->covers(new DateTimeImmutable('2026-11-24 08:59:59 UTC')), 'evidence of before the acknowledgement stays aggregated');
        self::assertTrue($grant->covers(new DateTimeImmutable('2026-11-24 09:00:00 UTC')));
        self::assertCount(1, $seen);
        self::assertEquals(new DateTimeImmutable('2026-11-24 10:00:00 UTC'), $seen[0]->viewedAt);
        self::assertEquals($grant->visibleSince, $seen[0]->visibleSince);
        self::assertSame(['manager-1', 'worker-1'], [$seen[0]->viewer->id, $seen[0]->worker->id]);
        self::assertNotContains('manager-1', $logger->contextValues(), 'logs carry no ids');
        self::assertNotContains('worker-1', $logger->contextValues());
        self::assertSame('allowed', $reason(static fn (): MonitoringPersonDetailGrant => $guard->authorizePersonDetail($admin, $worker)), 'the other class goes through the policy and the record');
        self::assertCount(2, $seen);

        // Acknowledged on the opt-in day before midnight UTC: visible from the acknowledgement; earlier day: from the opt-in.
        $source->policy = $this->billing(['perPersonDetailRecordedAt' => '2026-11-24', 'acknowledgementRequired' => false]);
        $acks->last     = new MonitoringAcknowledgement(5, new DateTimeImmutable('2026-11-24 00:00:00 UTC'), 'desktop', MonitoringFixtures::noticeSha256());
        self::assertEquals(new DateTimeImmutable('2026-11-24 00:00:00 UTC'), $guard->authorizePersonDetail($viewer, $worker)->visibleSince);

        // Audit ZBB-L1: no dispatcher, a dispatcher without a listener, or no acknowledgement store: no detail.
        self::assertSame('no_audit', $reason(static fn (): MonitoringPersonDetailGrant => (new MonitoringTeamViewGuard($source, null, $clock, null, $acks))->authorizePersonDetail($viewer, $worker)));
        self::assertSame('no_audit', $reason(static fn (): MonitoringPersonDetailGrant => (new MonitoringTeamViewGuard($source, new EventDispatcher(), $clock, null, $acks))->authorizePersonDetail($viewer, $worker)), 'nothing listens: nothing would record the view');
        self::assertSame('not_acknowledged', $reason(static fn (): MonitoringPersonDetailGrant => (new MonitoringTeamViewGuard($source, $dispatcher, $clock))->authorizePersonDetail($viewer, $worker)));
        self::assertSame('no_policy', $reason(static fn (): MonitoringPersonDetailGrant => (new MonitoringTeamViewGuard())->authorizePersonDetail($viewer, $worker)), 'monitoring not configured');
    }

    public function testTheWorkdayGateExposesTheHistoryPortOnlyWhenImplemented(): void
    {
        $register = new FixedWorkdayRegister('2026-11-24');
        self::assertNull((new WorkdaySourceGate($register))->history());
        $history = $register->withHistory();
        self::assertSame($history, (new WorkdaySourceGate($history))->history());
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function billing(array $overrides = []): MonitoringPolicy
    {
        return MonitoringFixtures::policy(overrides: $overrides + ['purpose' => 'billing_evidence', 'teamView' => 'per_person', 'perPersonDetailRecordedAt' => '2026-11-24']);
    }
}

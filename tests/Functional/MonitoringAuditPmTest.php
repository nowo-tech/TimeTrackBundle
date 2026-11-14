<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Functional;

use Nowo\TimeTrackBundle\Event\MonitoringSpanDiscardedEvent;
use Nowo\TimeTrackBundle\Event\TimeTrackEvents;
use Nowo\TimeTrackBundle\Monitoring\ActivityItem;
use Nowo\TimeTrackBundle\Monitoring\MonitoringEvidenceRejectedException;
use Nowo\TimeTrackBundle\Tests\Support\MonitoringFixtures;
use Nowo\TimeTrackBundle\Tests\Support\RacingMonitoringIntentRepository;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

use function array_fill;
use function array_map;
use function gmdate;
use function hash;
use function strlen;

/**
 * Since 1.26.0 (SEC-004 audit PM on PR #104): the regression tests of probes P1–P7 and of the fixes M1, M2, L1, L3–L7
 * and Q3 (Beacon 2026-11-14).
 */
class MonitoringAuditPmTest extends MonitoringTestCase
{
    /** M1 / P1: a slot off the UTC grid of slotMinutes is refused on every route. */
    public function testM1SlotsAreOnThePolicyGrid(): void
    {
        $token = $this->token();
        $this->acknowledge($token);
        $timer = $this->startTimer($token);
        $image = MonitoringFixtures::webp(1280, 720);
        $off   = gmdate('Y-m-d\TH:i:s\Z', $this->now() + 1);

        $answer = $this->intent($token, $this->intentBody($timer, $image, ['slot' => $off], $this->now() + 1), 'm1');
        self::assertResponseStatusCodeSame(400);
        self::assertSame(['invalid_request', 'slot'], [$answer['error'] ?? null, $answer['field'] ?? null]);
        $answer = $this->api('POST', self::DISCARDED, ['entryId' => $timer, 'slot' => $off, 'reason' => 'worker'], $token);
        self::assertSame([400, 'slot'], [$this->client->getResponse()->getStatusCode(), $answer['field'] ?? null]);
        $answer = $this->api('POST', self::ACTIVITY, ['items' => [['entryId' => $timer, 'slot' => $off, 'kind' => 'level', 'seconds' => 60, 'level' => 5]]], $token, ['HTTP_IDEMPOTENCY_KEY' => 'm1']);
        self::assertSame([400, 'items'], [$this->client->getResponse()->getStatusCode(), $answer['field'] ?? null]);
    }

    /** M2 / P2: one screenshot per (user, slot), whatever the entry id (timer id, then the entry it became). */
    public function testM2TheSlotKeyIsUserAndSlot(): void
    {
        $token = $this->token();
        $this->acknowledge($token);
        $timer = $this->startTimer($token);
        $image = MonitoringFixtures::webp(1280, 720);
        $body  = $this->intentBody($timer, $image);
        $this->put(self::url($this->intent($token, $body, 'm2-a')), $token, $image);
        self::assertResponseStatusCodeSame(201);
        $entry = $this->api('POST', '/api/time-track/timer/stop', [], $token)['entry']['id'] ?? null;
        self::assertIsString($entry);

        $other  = MonitoringFixtures::webp(1280, 719);
        $answer = $this->intent($token, ['entryId' => $entry, 'height' => 719, 'bytes' => strlen($other), 'sha256' => hash('sha256', $other)] + $body, 'm2-b');
        self::assertResponseStatusCodeSame(409);
        self::assertSame('duplicate', $answer['error'] ?? null);
        self::assertCount(1, $this->evidence()->screenshots);

        // A live intent under another entry id holds the slot too (two entries covering the same instant).
        $a = $this->recordedEntry($this->at('-3 hours'), $this->at('-1 hour'));
        $b = $this->recordedEntry($this->at('-3 hours'), $this->at('-1 hour'));
        $this->intent($token, $this->intentBody($a, $image, [], $this->now() - 7200), 'm2-c');
        self::assertResponseStatusCodeSame(201);
        $this->intent($token, $this->intentBody($b, $other, ['height' => 719], $this->now() - 7200), 'm2-d');
        self::assertResponseStatusCodeSame(409);
    }

    /** L1 / P3: excluded apps are matched on the raw name, before the redaction. */
    public function testL1ExclusionBeforeRedaction(): void
    {
        $this->policySource()->policy = MonitoringFixtures::policy(overrides: ['excludedApps' => ['Vault2024']]);
        $token                        = $this->token();
        $this->acknowledge($token);
        $timer  = $this->startTimer($token);
        $slot   = gmdate('Y-m-d\TH:i:s\Z', $this->now());
        $answer = $this->api('POST', self::ACTIVITY, ['items' => [['entryId' => $timer, 'slot' => $slot, 'kind' => 'app', 'name' => 'Vault2024', 'seconds' => 30]]], $token, ['HTTP_IDEMPOTENCY_KEY' => 'l1']);
        self::assertResponseStatusCodeSame(202);
        self::assertSame(0, $answer['accepted'] ?? null);
        self::assertSame([], $this->evidence()->activity);
    }

    /** L3 / P5: the policy's maxLongSidePx is enforced at intent and at PUT (422 upload_mismatch dimensions). */
    public function testL3PolicyMaxLongSide(): void
    {
        $token = $this->token();
        $this->acknowledge($token);
        $timer  = $this->startTimer($token);
        $big    = MonitoringFixtures::webp(3840, 2160);
        $answer = $this->intent($token, $this->intentBody($timer, $big, ['width' => 3840, 'height' => 2160]), 'l3');
        self::assertResponseStatusCodeSame(422);
        self::assertSame(['upload_mismatch', 'dimensions'], [$answer['error'] ?? null, $answer['field'] ?? null]);

        $this->policySource()->policy = MonitoringFixtures::policy(overrides: ['maxLongSidePx' => 3840]);
        $grant                        = $this->intent($token, $this->intentBody($timer, $big, ['width' => 3840, 'height' => 2160]), 'l3-b');
        self::assertResponseStatusCodeSame(201);
        $this->policySource()->policy = MonitoringFixtures::policy();
        $answer                       = $this->put(self::url($grant), $token, $big);
        self::assertResponseStatusCodeSame(422);
        self::assertSame('dimensions', $answer['field'] ?? null);
    }

    /** L4: the PUT re-checks SC-002 (and the schedule and the age). */
    public function testL4ThePutRechecksTheTimer(): void
    {
        $token = $this->token();
        $this->acknowledge($token);
        $timer = $this->startTimer($token);
        $image = MonitoringFixtures::webp(1280, 720);
        $grant = $this->intent($token, $this->intentBody($timer, $image), 'l4');
        $this->api('POST', '/api/time-track/timer/stop', [], $token);
        $answer = $this->put(self::url($grant), $token, $image);
        self::assertResponseStatusCodeSame(422);
        self::assertSame('outside_timer', $answer['reason'] ?? null);

        $timer = $this->startTimer($token);
        $grant = $this->intent($token, $this->intentBody($timer, $image), 'l4-b');
        self::assertResponseStatusCodeSame(201);
        $this->profile()->hours = ['timezone' => 'UTC', 'slots' => [['day' => (int) gmdate('N', $this->now() + 86_400), 'from' => '00:00', 'to' => '23:59']]];
        self::assertSame('outside_hours', $this->put(self::url($grant), $token, $image)['reason'] ?? null);
    }

    /** L5 / P7: app seconds per (user, slot) never exceed slotMinutes × 60, across batches. */
    public function testL5ActivitySecondsAreClampedPerSlot(): void
    {
        $token = $this->token();
        $this->acknowledge($token);
        $timer = $this->startTimer($token);
        $slot  = gmdate('Y-m-d\TH:i:s\Z', $this->now());
        $item  = ['entryId' => $timer, 'slot' => $slot, 'kind' => 'app', 'name' => 'Editor', 'seconds' => 400];

        $first = $this->api('POST', self::ACTIVITY, ['items' => [$item, ['name' => 'Mail', 'seconds' => 150] + $item]], $token, ['HTTP_IDEMPOTENCY_KEY' => 'l5-a']);
        self::assertSame(['accepted' => 2, 'dropped' => 0, 'droppedSeconds' => 0], $first);
        $second = $this->api('POST', self::ACTIVITY, ['items' => [['seconds' => 100] + $item, ['seconds' => 30] + $item, ['kind' => 'level', 'name' => null, 'level' => 50, 'seconds' => 600] + $item]], $token, ['HTTP_IDEMPOTENCY_KEY' => 'l5-b']);
        self::assertSame(['accepted' => 2, 'dropped' => 1, 'droppedSeconds' => 80], $second, '50 s left: 100 → 50, the 30 s item is dropped; the level is not app time');
        self::assertSame([400, 150, 50, 600], array_map(static fn (ActivityItem $item): int => $item->seconds, [...$this->evidence()->activity[0]['items'], ...$this->evidence()->activity[1]['items']]));
        $p7 = $this->api('POST', self::ACTIVITY, ['items' => array_fill(0, 200, $item)], $token, ['HTTP_IDEMPOTENCY_KEY' => 'l5-c']);
        self::assertSame(['accepted' => 0, 'dropped' => 200, 'droppedSeconds' => 80_000], $p7);
    }

    /** L6: a typed rejection of the host answers 422 evidence_rejected, burns the intent, and is not retried. */
    public function testL6TheHostCanRejectEvidence(): void
    {
        $token = $this->token();
        $this->acknowledge($token);
        $timer                        = $this->startTimer($token);
        $image                        = MonitoringFixtures::webp(1280, 720);
        $body                         = $this->intentBody($timer, $image);
        $grant                        = $this->intent($token, $body, 'l6');
        $this->evidence()->rejectWith = new MonitoringEvidenceRejectedException('undecodable');
        $answer                       = $this->put(self::url($grant), $token, $image);
        self::assertResponseStatusCodeSame(422);
        self::assertSame('evidence_rejected', $answer['error'] ?? null);
        self::assertSame('evidence_rejected', $this->intent($token, $body, 'l6')['error'] ?? null, 'a replay of a rejected intent');
        self::assertResponseStatusCodeSame(422);

        $answer = $this->api('POST', self::ACTIVITY, ['items' => [['entryId' => $timer, 'slot' => $body['slot'], 'kind' => 'level', 'seconds' => 60, 'level' => 5]]], $token, ['HTTP_IDEMPOTENCY_KEY' => 'l6-a']);
        self::assertSame([422, 'evidence_rejected'], [$this->client->getResponse()->getStatusCode(), $answer['error'] ?? null]);
        $this->api('POST', self::DISCARDED, ['entryId' => $timer, 'slot' => $body['slot'], 'reason' => 'worker'], $token);
        self::assertResponseStatusCodeSame(422);
    }

    /** L7: the host stored the screenshot but the intent could not be marked: the client still gets the id. */
    public function testL7TheIdIsReturnedWhenTheIntentCannotBeMarked(): void
    {
        $token = $this->token();
        $this->acknowledge($token);
        $timer  = $this->startTimer($token);
        $image  = MonitoringFixtures::webp(1280, 720);
        $body   = $this->intentBody($timer, $image);
        $grant  = $this->intent($token, $body, 'l7');
        $racing = self::getContainer()->get(RacingMonitoringIntentRepository::class);
        self::assertInstanceOf(RacingMonitoringIntentRepository::class, $racing);
        $racing->failMarkStored = true;
        self::assertSame(['id' => 'shot-1'], $this->put(self::url($grant), $token, $image));
        self::assertResponseStatusCodeSame(201);
        self::assertContains('TimeTrack monitoring upload not marked stored.', $this->logger()->messages());
        $this->intent($token, $body, 'l7');
        self::assertResponseStatusCodeSame(409, 'the replay finds the stored screenshot through hasScreenshot()');
    }

    /** Q3: discarding idle time (discard or split) emits MonitoringSpanDiscardedEvent with the span. */
    public function testQ3DiscardedIdleTimeEmitsTheSpan(): void
    {
        /** @var list<MonitoringSpanDiscardedEvent> $events */
        $events     = [];
        $dispatcher = self::getContainer()->get('event_dispatcher');
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);
        $dispatcher->addListener(TimeTrackEvents::MONITORING_SPAN_DISCARDED, static function (MonitoringSpanDiscardedEvent $event) use (&$events): void {
            $events[] = $event;
        });
        foreach (['discard', 'split', 'keep'] as $i => $action) {
            // One worker per action: a backdated start never goes before the worker's latest entry.
            $token = $this->token($i === 0 ? null : $this->createUser('worker' . $i . '@example.com'));
            $this->api('POST', '/api/time-track/timer/start', ['taskId' => 'task-1', 'clientType' => 'desktop', 'startedAt' => gmdate('Y-m-d\TH:i:s\Z', $this->now() - 3600)], $token);
            self::assertResponseStatusCodeSame(201);
            $this->api('POST', '/api/time-track/timer/discard-idle', ['from' => gmdate('Y-m-d\TH:i:s\Z', $this->now() - 1800), 'to' => gmdate('Y-m-d\TH:i:s\Z', $this->now() - 600), 'action' => $action], $token);
            self::assertContains($this->client->getResponse()->getStatusCode(), [200, 201], $action . ' ' . $this->client->getResponse()->getContent());
            $this->api('POST', '/api/time-track/timer/stop', [], $token);
        }
        self::assertSame(['idle_discard', 'idle_split'], array_map(static fn (MonitoringSpanDiscardedEvent $event): string => $event->reason, $events));
        self::assertSame((string) $this->user->getId(), $events[0]->user->id);
        self::assertSame([$this->now() - 1800, $this->now() - 600], [$events[0]->from->getTimestamp(), $events[0]->to->getTimestamp()]);
    }
}

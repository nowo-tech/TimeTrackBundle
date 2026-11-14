<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Unit\Monitoring;

use DateTimeImmutable;
use Nowo\TimeTrackBundle\Monitoring\MonitoringAcknowledgement;
use Nowo\TimeTrackBundle\Monitoring\MonitoringAcknowledgementStoreInterface;
use Nowo\TimeTrackBundle\Monitoring\MonitoringContext;
use Nowo\TimeTrackBundle\Tests\Stub\StubMonitoringEvidenceStore;
use Nowo\TimeTrackBundle\Tests\Stub\StubMonitoringPolicySource;
use Nowo\TimeTrackBundle\Tests\Stub\TestUser;
use Nowo\TimeTrackBundle\Tests\Support\MonitoringFixtures;
use Nowo\TimeTrackBundle\Tests\Support\RecordingLogger;
use Nowo\TimeTrackBundle\ValueObject\TimeTrackUserRef;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Since 1.26.0: the monitoring context is off unless every port is there, asks the ports on every call (nothing kept),
 * and treats a failing port as off on `GET me`.
 */
final class MonitoringContextTest extends TestCase
{
    public function testOffUnlessEveryPortIsThere(): void
    {
        $user           = new TestUser('1', 'u@example.com');
        $source         = new StubMonitoringPolicySource();
        $source->policy = MonitoringFixtures::policy();
        $acks           = $this->acks();
        foreach ([new MonitoringContext(), new MonitoringContext($source, $acks), new MonitoringContext(null, $acks, new StubMonitoringEvidenceStore()), new MonitoringContext($source, null, new StubMonitoringEvidenceStore())] as $off) {
            self::assertFalse($off->isConfigured());
            self::assertNull($off->evidence());
            self::assertNull($off->policyFor($user));
            self::assertNull($off->lastAcknowledged($user));
            self::assertNull($off->summary($user));
            $off->acknowledge($user, new MonitoringAcknowledgement(7, new DateTimeImmutable(), 'web', MonitoringFixtures::noticeSha256()));
        }
        self::assertSame([], $acks->stored, 'nothing is written while off');
        self::assertSame([], $source->calls, 'the source is not even asked');
    }

    public function testSummaryAndAcknowledgement(): void
    {
        $user           = new TestUser('1', 'u@example.com');
        $source         = new StubMonitoringPolicySource();
        $source->policy = MonitoringFixtures::policy();
        $acks           = $this->acks();
        $logger         = new RecordingLogger();
        $context        = new MonitoringContext($source, $acks, new StubMonitoringEvidenceStore(), $logger);

        self::assertTrue($context->isConfigured());
        self::assertTrue($context->summary($user)['acknowledgement']['required'] ?? null);
        $context->acknowledge($user, new MonitoringAcknowledgement(7, new DateTimeImmutable('2026-11-18T10:12:00+01:00'), 'web', MonitoringFixtures::noticeSha256()));
        self::assertSame(['required' => false, 'acknowledgedVersion' => 7, 'acknowledgedAt' => '2026-11-18T09:12:00Z'], $context->summary($user)['acknowledgement'] ?? null);
        self::assertSame(['1', '1'], $source->calls, 'asked again on every call');

        $source->failWith = 'SECRET-HOST-DETAIL';
        self::assertNull($context->summary($user));
        self::assertNotContains('SECRET-HOST-DETAIL', $logger->contextValues());
        self::assertContains(RuntimeException::class, $logger->contextValues());
    }

    public function testAcknowledgementRequired(): void
    {
        $policy = MonitoringFixtures::policy(7);
        $at     = new DateTimeImmutable();
        $ack    = static fn (int $version): MonitoringAcknowledgement => new MonitoringAcknowledgement($version, $at, 'web', MonitoringFixtures::noticeSha256());
        self::assertTrue(MonitoringContext::acknowledgementRequired($policy, null));
        self::assertTrue(MonitoringContext::acknowledgementRequired($policy, $ack(6)));
        self::assertFalse(MonitoringContext::acknowledgementRequired($policy, $ack(7)));
        self::assertFalse(MonitoringContext::acknowledgementRequired(MonitoringFixtures::policy(7, overrides: ['acknowledgementRequired' => false]), $ack(6)));
        self::assertTrue(MonitoringContext::acknowledgementRequired(MonitoringFixtures::policy(7, overrides: ['acknowledgementRequired' => false]), null), 'the first version always needs one');
    }

    /**
     * @return MonitoringAcknowledgementStoreInterface&object{stored: list<MonitoringAcknowledgement>}
     */
    private function acks(): MonitoringAcknowledgementStoreInterface
    {
        return new class implements MonitoringAcknowledgementStoreInterface {
            /** @var list<MonitoringAcknowledgement> */
            public array $stored = [];

            public function acknowledge(TimeTrackUserRef $user, MonitoringAcknowledgement $acknowledgement): void
            {
                $this->stored[] = $acknowledgement;
            }

            public function lastAcknowledged(TimeTrackUserRef $user): ?MonitoringAcknowledgement
            {
                return $this->stored[0] ?? null;
            }
        };
    }
}

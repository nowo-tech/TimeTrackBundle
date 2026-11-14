<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Unit\Monitoring;

use DateTimeImmutable;
use InvalidArgumentException;
use Nowo\TimeTrackBundle\Monitoring\ActivityItem;
use Nowo\TimeTrackBundle\Monitoring\MonitoringAcknowledgement;
use Nowo\TimeTrackBundle\Monitoring\MonitoringUploadClaim;
use Nowo\TimeTrackBundle\Monitoring\ScreenshotUpload;
use Nowo\TimeTrackBundle\Tests\Support\MonitoringFixtures;
use Nowo\TimeTrackBundle\ValueObject\TimeTrackUserRef;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function fclose;
use function fopen;
use function str_repeat;

/**
 * Since 1.26.0 (contract §24): the framework-free value objects of the monitoring ports refuse every value outside the
 * contract.
 */
final class MonitoringValueObjectsTest extends TestCase
{
    public function testAPolicyKeepsTheKindsInContractOrderAndAnswersWhatItCollects(): void
    {
        $policy = MonitoringFixtures::policy(7, ['activity', 'screenshots', 'activity'], ['excludedApps' => ['a', 'a', 'b']]);
        self::assertSame(['screenshots', 'activity'], $policy->kinds);
        self::assertSame(['a', 'b'], $policy->excludedApps);
        self::assertTrue($policy->allows('screenshots'));
        self::assertFalse($policy->allows('apps'));
        self::assertFalse(MonitoringFixtures::policy(7, overrides: ['lockedUntilChecklist' => true])->allows('screenshots'));
        self::assertFalse(MonitoringFixtures::policy(7, overrides: ['enabled' => false])->isCollecting());
        self::assertSame('none', MonitoringFixtures::policy(7, overrides: ['blur' => 'none', 'fullResolution' => true])->blur);
        self::assertSame(0, MonitoringFixtures::policy(7, ['apps'], ['screenshotsPer10Min' => 0])->screenshotsPer10Min, 'no screenshots kind: 0 is allowed');
    }

    /**
     * @param array<string, mixed> $overrides
     */
    #[DataProvider('invalidPolicies')]
    public function testAnInvalidPolicyIsRefused(array $overrides): void
    {
        $this->expectException(InvalidArgumentException::class);
        MonitoringFixtures::policy(7, $overrides['kinds'] ?? ['screenshots'], $overrides['policy'] ?? []);
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function invalidPolicies(): iterable
    {
        yield 'unknown kind' => [['kinds' => ['websites']]];
        foreach ([
            'version'         => ['policyVersion' => 0],
            'http notice'     => ['noticeUrl' => 'http://beacon.example/n'],
            'spaced notice'   => ['noticeUrl' => 'https://beacon.example/a b'],
            'notice hash'     => ['noticeSha256' => str_repeat('A', 64)],
            'scope'           => ['scope' => 'team'],
            'dpia reference'  => ['dpiaReference' => ' '],
            'dpia date'       => ['dpiaRecordedAt' => '20/11/2026'],
            'samples'         => ['screenshotsPer10Min' => 2],
            'no samples'      => ['screenshotsPer10Min' => 0],
            'slot'            => ['slotMinutes' => 0],
            'long slot'       => ['slotMinutes' => 61],
            'review'          => ['reviewWindowMinutes' => 61],
            'negative review' => ['reviewWindowMinutes' => -1],
            'blur'            => ['blur' => 'extreme'],
            'no blur'         => ['blur' => 'none'],
            'long side'       => ['maxLongSidePx' => 3841],
            'no side'         => ['maxLongSidePx' => 0],
            'retention'       => ['retentionDays' => 0],
            'long retention'  => ['retentionDays' => 3651],
            'buffer'          => ['maxBufferedScreenshots' => 37],
            'negative buffer' => ['maxBufferedScreenshots' => -1],
            'buffer age'      => ['maxBufferAgeHours' => 25],
            'no buffer age'   => ['maxBufferAgeHours' => 0],
            'blank app'       => ['excludedApps' => [' ']],
            'long app'        => ['excludedApps' => [str_repeat('a', 121)]],
            'app type'        => ['excludedApps' => [5]],
            'many apps'       => ['excludedApps' => array_map(static fn (int $i): string => 'app' . $i, range(1, 101))],
        ] as $name => $override) {
            yield $name => [['policy' => $override]];
        }
    }

    public function testAnAcknowledgement(): void
    {
        $at  = new DateTimeImmutable('2026-11-20T09:00:00Z');
        $ack = new MonitoringAcknowledgement(7, $at, 'desktop', MonitoringFixtures::noticeSha256());
        self::assertSame([7, $at, 'desktop'], [$ack->policyVersion, $ack->acknowledgedAt, $ack->clientType]);
        foreach ([[0, 'desktop', MonitoringFixtures::noticeSha256()], [7, 'Desktop!', MonitoringFixtures::noticeSha256()], [7, 'web', 'x']] as [$version, $client, $sha]) {
            try {
                new MonitoringAcknowledgement($version, $at, $client, $sha);
                self::fail('refused expected');
            } catch (InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testActivityItems(): void
    {
        $slot = new DateTimeImmutable('2026-11-20T09:10:00Z');
        self::assertSame('apps', (new ActivityItem('e1', $slot, 'app', 'Editor', 300))->policyKind());
        self::assertSame('activity', (new ActivityItem('e1', $slot, 'level', null, 600, 0))->policyKind());
        foreach ([
            ['', 'app', 'x', 1, null],
            [str_repeat('e', 37), 'app', 'x', 1, null],
            ['e1', 'app', 'x', 601, null],
            ['e1', 'app', 'x', -1, null],
            ['e1', 'app', null, 1, null],
            ['e1', 'app', ' ', 1, null],
            ['e1', 'app', str_repeat('n', 121), 1, null],
            ['e1', 'app', 'x', 1, 50],
            ['e1', 'level', 'x', 1, 50],
            ['e1', 'level', null, 1, null],
            ['e1', 'level', null, 1, 101],
            ['e1', 'level', null, 1, -1],
            ['e1', 'domain', 'example.com', 1, null],
        ] as [$entry, $kind, $name, $seconds, $level]) {
            try {
                new ActivityItem($entry, $slot, $kind, $name, $seconds, $level);
                self::fail('refused expected: ' . $kind);
            } catch (InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testAScreenshotUploadNeedsAReadOnlyStream(): void
    {
        $user   = TimeTrackUserRef::ofId('App\\User', '1');
        $at     = new DateTimeImmutable();
        $stream = fopen('php://memory', 'r');
        self::assertIsResource($stream);
        $upload = new ScreenshotUpload($user, 'e1', $at, $at, 7, true, 'image/webp', 10, 1, 1, str_repeat('a', 64), $stream);
        self::assertSame($stream, $upload->stream());
        foreach (['not a stream', fopen('php://memory', 'w+')] as $invalid) {
            try {
                // @phpstan-ignore argument.type (the refusal under test)
                new ScreenshotUpload($user, 'e1', $at, $at, 7, true, 'image/webp', 10, 1, 1, str_repeat('a', 64), $invalid);
                self::fail('refused expected');
            } catch (InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
        fclose($stream);
    }

    public function testTheClaimIsCanonical(): void
    {
        self::assertSame("nowo-time-track-monitoring-upload-v1\nu\nt\ns\n5\nimage/webp\n9", (new MonitoringUploadClaim('u', 't', 's', 5, 'image/webp', 9))->canonical());
    }
}

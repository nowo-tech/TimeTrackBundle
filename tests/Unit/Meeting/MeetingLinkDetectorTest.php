<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Unit\Meeting;

use Nowo\TimeTrackBundle\Meeting\MeetingLinkDetector;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function dirname;
use function file_get_contents;
use function json_decode;

use const JSON_THROW_ON_ERROR;

/**
 * Since 1.14 (contract §22.4, AC-27): join links leave the API only when https and on the provider allow-list; the
 * ChatKit 1.19 vectors plus hostile ones (`tests/Fixtures/meetings/links.json`).
 */
final class MeetingLinkDetectorTest extends TestCase
{
    /**
     * @return array{jitsiHosts: list<string>, accepted: list<array{string, string, string}>, refused: list<array{string, string}>}
     */
    private static function vectors(): array
    {
        /** @var array{jitsiHosts: list<string>, accepted: list<array{string, string, string}>, refused: list<array{string, string}>} $vectors */
        $vectors = json_decode((string) file_get_contents(dirname(__DIR__, 2) . '/Fixtures/meetings/links.json'), true, 512, JSON_THROW_ON_ERROR);

        return $vectors;
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function accepted(): iterable
    {
        foreach (self::vectors()['accepted'] as [$name, $url, $provider]) {
            yield $name => [$url, $provider];
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function refused(): iterable
    {
        foreach (self::vectors()['refused'] as [$name, $url]) {
            yield $name => [$url];
        }
    }

    #[DataProvider('accepted')]
    public function testAcceptsTheProviders(string $url, string $provider): void
    {
        self::assertSame(['url' => $url, 'provider' => $provider], (new MeetingLinkDetector(self::vectors()['jitsiHosts']))->detect($url));
    }

    #[DataProvider('refused')]
    public function testRefusesEverythingElse(string $url): void
    {
        self::assertNull((new MeetingLinkDetector(self::vectors()['jitsiHosts']))->detect($url));
    }

    public function testNullAndUnconfiguredJitsi(): void
    {
        self::assertNull((new MeetingLinkDetector())->detect(null));
        self::assertNull((new MeetingLinkDetector())->detect('https://jitsi.example.org/FakeRoom'));
    }
}

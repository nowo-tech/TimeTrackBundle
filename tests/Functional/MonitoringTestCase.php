<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Functional;

use DateTimeImmutable;
use Nowo\TimeTrackBundle\Entity\TimeEntry;
use Nowo\TimeTrackBundle\Enum\ClientScope;
use Nowo\TimeTrackBundle\Enum\ClientType;
use Nowo\TimeTrackBundle\Enum\TimeEntrySource;
use Nowo\TimeTrackBundle\Service\ClientAuthService;
use Nowo\TimeTrackBundle\Tests\Fixtures\FunctionalEntity\AppUser;
use Nowo\TimeTrackBundle\Tests\Stub\FrozenMonitoringClock;
use Nowo\TimeTrackBundle\Tests\Stub\MutableProfileProvider;
use Nowo\TimeTrackBundle\Tests\Stub\StubMonitoringEvidenceStore;
use Nowo\TimeTrackBundle\Tests\Stub\StubMonitoringPolicySource;
use Nowo\TimeTrackBundle\Tests\Support\FunctionalKernel;
use Nowo\TimeTrackBundle\Tests\Support\MonitoringFixtures;
use Nowo\TimeTrackBundle\Tests\Support\RecordingLogger;
use Symfony\Component\HttpKernel\KernelInterface;

use function array_values;
use function gmdate;
use function hash;
use function is_string;
use function parse_url;
use function strlen;

use const JSON_THROW_ON_ERROR;

/**
 * Since 1.26.0 (contract §24): shared set-up of the monitoring API tests — a host policy source and evidence store
 * (stubs), the default acknowledgement store and signer, every-day all-day working hours, and helpers for tokens,
 * timers, intents and uploads.
 */
abstract class MonitoringTestCase extends FunctionalTestCase
{
    protected const ACK = '/api/time-track/monitoring/acknowledge';

    protected const INTENT = '/api/time-track/monitoring/screenshots/intent';

    protected const DISCARDED = '/api/time-track/monitoring/screenshots/discarded';

    protected const ACTIVITY = '/api/time-track/monitoring/activity';

    /**
     * Audit PM-L8: every monitoring test runs on a frozen clock ({@see FrozenMonitoringClock}).
     *
     * @param array<string, mixed> $options
     */
    protected static function createKernel(array $options = []): KernelInterface
    {
        return new FunctionalKernel(static::timeTrackConfig(), false, false, true, false, true);
    }

    /**
     * The frozen "now" of the server, as a Unix timestamp (start of the current 10-minute slot).
     */
    protected function now(): int
    {
        $clock = self::getContainer()->get(FrozenMonitoringClock::class);
        self::assertInstanceOf(FrozenMonitoringClock::class, $clock);

        return $clock->now()->getTimestamp();
    }

    /**
     * An instant relative to the frozen now (e.g. "-3 hours").
     */
    protected function at(string $modify): DateTimeImmutable
    {
        return (new DateTimeImmutable('@' . $this->now()))->modify($modify);
    }

    protected static function timeTrackConfig(): array
    {
        return ['client_api' => [
            'profile_provider' => MutableProfileProvider::class,
            // The limits have their own test (MonitoringRateLimitTest).
            'rate_limit' => ['per_token' => 0, 'per_ip' => 0],
            'monitoring' => [
                'policy_source'  => StubMonitoringPolicySource::class,
                'evidence_store' => StubMonitoringEvidenceStore::class,
                'rate_limit'     => ['intents_per_10_min' => 0, 'uploads_per_10_min' => 0, 'activity_per_10_min' => 0],
            ],
        ]];
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->policySource()->policy = MonitoringFixtures::policy();
        $this->logger()->records      = [];
    }

    protected function policySource(): StubMonitoringPolicySource
    {
        $source = self::getContainer()->get(StubMonitoringPolicySource::class);
        self::assertInstanceOf(StubMonitoringPolicySource::class, $source);

        return $source;
    }

    protected function evidence(): StubMonitoringEvidenceStore
    {
        $store = self::getContainer()->get(StubMonitoringEvidenceStore::class);
        self::assertInstanceOf(StubMonitoringEvidenceStore::class, $store);

        return $store;
    }

    protected function profile(): MutableProfileProvider
    {
        $profile = self::getContainer()->get(MutableProfileProvider::class);
        self::assertInstanceOf(MutableProfileProvider::class, $profile);

        return $profile;
    }

    protected function logger(): RecordingLogger
    {
        $logger = self::getContainer()->get('logger');
        self::assertInstanceOf(RecordingLogger::class, $logger);

        return $logger;
    }

    /**
     * A desktop token with `monitoring:write` and the timer scopes (of `$user`, default the signed-up person).
     */
    protected function token(?AppUser $user = null, ClientScope ...$scopes): string
    {
        $auth = self::getContainer()->get(ClientAuthService::class);
        self::assertInstanceOf(ClientAuthService::class, $auth);
        $user = $this->entityManager()->find(AppUser::class, ($user ?? $this->user)->getId());
        self::assertInstanceOf(AppUser::class, $user);

        return (string) $auth->issueToken($user, ClientType::Desktop, null, $scopes === [] ? [ClientScope::MonitoringWrite, ClientScope::TimerRead, ClientScope::TimerWrite] : array_values($scopes))['token'];
    }

    /**
     * Acknowledges the policy version with the fixture notice hash; asserts 204.
     */
    protected function acknowledge(string $token, int $version = 7, string $key = 'ack-1'): void
    {
        $this->api('POST', self::ACK, ['policyVersion' => $version, 'noticeSha256' => MonitoringFixtures::noticeSha256(), 'shownAt' => gmdate('Y-m-d\TH:i:s\Z', $this->now())], $token, ['HTTP_IDEMPOTENCY_KEY' => $key]);
        self::assertResponseStatusCodeSame(204);
    }

    /**
     * Starts the token user's timer; returns its id.
     */
    protected function startTimer(string $token, string $taskId = 'task-1'): string
    {
        $body = $this->api('POST', '/api/time-track/timer/start', ['taskId' => $taskId, 'clientType' => 'desktop'], $token);
        self::assertResponseStatusCodeSame(201);
        $id = $body['timer']['id'] ?? null;
        self::assertIsString($id);

        return $id;
    }

    /**
     * A recorded desktop entry of the user (a stopped timer) from `$start` to `$end`; returns its id.
     */
    protected function recordedEntry(DateTimeImmutable $start, DateTimeImmutable $end, TimeEntrySource $source = TimeEntrySource::Desktop, ?AppUser $user = null): string
    {
        $owner = $this->entityManager()->find(AppUser::class, ($user ?? $this->user)->getId());
        self::assertInstanceOf(AppUser::class, $owner);
        $entry = new TimeEntry($owner, 'task-1', 'Task', null, $start, $end, $end->getTimestamp() - $start->getTimestamp(), $source);
        $this->entityManager()->persist($entry);
        $this->entityManager()->flush();

        return $entry->getId();
    }

    /**
     * An intent body for an image taken now (slot = the current 10-minute slot).
     *
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    protected function intentBody(string $entryId, string $image, array $overrides = [], ?int $takenAt = null): array
    {
        $taken = $takenAt ?? $this->now();

        return $overrides + [
            'entryId'       => $entryId,
            'slot'          => gmdate('Y-m-d\TH:i:s\Z', $taken - $taken % 600),
            'takenAt'       => gmdate('Y-m-d\TH:i:s\Z', $taken),
            'policyVersion' => 7,
            'blurred'       => true,
            'mime'          => 'image/webp',
            'bytes'         => strlen($image),
            'width'         => 1280,
            'height'        => 720,
            'sha256'        => hash('sha256', $image),
        ];
    }

    /**
     * POST screenshots/intent.
     *
     * @param array<string, mixed> $body
     *
     * @return array<mixed>|null
     */
    protected function intent(string $token, array $body, string $key = 'cap-1'): ?array
    {
        return $this->api('POST', self::INTENT, $body, $token, ['HTTP_IDEMPOTENCY_KEY' => $key]);
    }

    /**
     * PUT the bytes to a signed URL (path and query of the absolute URL); `$length` null = the content length, -1 = no
     * Content-Length header.
     *
     * @param array<string, string> $server
     *
     * @return array<mixed>|null
     */
    protected function put(string $url, string $token, string $content, string $type = 'image/webp', ?int $length = null, array $server = []): ?array
    {
        $parts = parse_url($url);
        self::assertIsArray($parts);
        $uri = ($parts['path'] ?? '') . (isset($parts['query']) ? '?' . $parts['query'] : '');
        $server += ['HTTP_AUTHORIZATION' => 'Bearer ' . $token, 'CONTENT_TYPE' => $type];
        if ($length !== -1) {
            // -1: no Content-Length header at all.
            $server['CONTENT_LENGTH'] = (string) ($length ?? strlen($content));
        }
        $this->client->request('PUT', $uri, [], [], $server, $content);
        $raw = (string) $this->client->getResponse()->getContent();

        /** @var array<mixed>|null $decoded */
        $decoded = $raw === '' ? null : json_decode($raw, true, 512, JSON_THROW_ON_ERROR);

        return $decoded;
    }

    /**
     * The signed URL of an intent answer.
     *
     * @param array<mixed>|null $answer
     */
    protected static function url(?array $answer): string
    {
        $url = $answer['url'] ?? null;
        self::assertTrue(is_string($url), 'an intent answer has a url');

        return $url;
    }
}

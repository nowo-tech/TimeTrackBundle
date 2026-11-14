<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Functional;

use DateTimeImmutable;
use Nowo\TimeTrackBundle\Enum\ClientScope;
use Nowo\TimeTrackBundle\Enum\TimeEntrySource;
use Nowo\TimeTrackBundle\Event\ClientApiEvent;
use Nowo\TimeTrackBundle\Event\TimeTrackEvents;
use Nowo\TimeTrackBundle\Monitoring\ActivityItem;
use Nowo\TimeTrackBundle\Tests\Stub\MutableProfileProvider;
use Nowo\TimeTrackBundle\Tests\Support\MonitoringFixtures;
use Nowo\TimeTrackBundle\Tests\Support\RacingMonitoringIntentRepository;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

use function array_column;
use function array_filter;
use function array_map;
use function array_values;
use function glob;
use function gmdate;
use function hash;
use function json_encode;
use function str_repeat;
use function strlen;
use function sys_get_temp_dir;
use function time;

use const DATE_ATOM;

/**
 * Since 1.26.0 (contract §24, spec 199): the monitoring routes end to end — `GET me` block, acknowledgement, the
 * two-step screenshot upload with every refusal at intent time and every cap while streaming, idempotent replays,
 * discards, deletes and activity batches; audit events and logs without personal data.
 */
class MonitoringApiTest extends MonitoringTestCase
{
    /** @var list<ClientApiEvent> */
    private array $events = [];

    protected function setUp(): void
    {
        parent::setUp();
        $dispatcher = self::getContainer()->get('event_dispatcher');
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);
        $dispatcher->addListener(TimeTrackEvents::CLIENT_API, function (ClientApiEvent $event): void {
            $this->events[] = $event;
        });
    }

    public function testMeHasTheBlockOnlyWithAPolicy(): void
    {
        $me = $this->api('GET', '/api/time-track/me', null, $this->login());
        self::assertTrue($me['features']['monitoring'] ?? null);
        self::assertSame([
            'enabled'              => true,
            'scope'                => 'organization',
            'policyVersion'        => 7,
            'lockedUntilChecklist' => false,
            'dpia'                 => ['reference' => 'DPIA-2026-003', 'recordedAt' => '2026-11-10', 'noticeUrl' => 'https://beacon.example/monitoring-notice/7'],
            'acknowledgement'      => ['required' => true, 'acknowledgedVersion' => null, 'acknowledgedAt' => null],
            'kinds'                => ['screenshots', 'apps', 'activity'],
            'capture'              => ['screenshotsPer10Min' => 1, 'slotMinutes' => 10, 'reviewWindowMinutes' => 10],
            'screenshots'          => ['enabled' => true, 'blur' => 'medium', 'fullResolution' => false, 'maxLongSidePx' => 1920],
            'windowTitles'         => false,
            'excludedApps'         => ['vaultapp', 'diaryapp'],
            'retentionDays'        => 30,
            'upload'               => ['maxBufferedScreenshots' => 36, 'maxBufferAgeHours' => 24],
        ], $me['monitoring'] ?? null);
        self::assertStringNotContainsString(MonitoringFixtures::noticeSha256(), (string) json_encode($me), 'the notice hash is never published');

        $this->acknowledge($this->token());
        $ack = $this->api('GET', '/api/time-track/me', null, $this->login())['monitoring']['acknowledgement'] ?? null;
        self::assertIsArray($ack);
        self::assertSame([false, 7], [$ack['required'] ?? null, $ack['acknowledgedVersion'] ?? null]);
        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', (string) ($ack['acknowledgedAt'] ?? ''));

        // A widening version 8 needs a new acknowledgement; a narrowing one does not.
        $this->policySource()->policy = MonitoringFixtures::policy(8);
        self::assertTrue($this->api('GET', '/api/time-track/me', null, $this->login())['monitoring']['acknowledgement']['required'] ?? null);
        $this->policySource()->policy = MonitoringFixtures::policy(8, overrides: ['acknowledgementRequired' => false]);
        self::assertFalse($this->api('GET', '/api/time-track/me', null, $this->login())['monitoring']['acknowledgement']['required'] ?? null);

        $this->policySource()->policy = MonitoringFixtures::policy(9, overrides: ['lockedUntilChecklist' => true]);
        $locked                       = $this->api('GET', '/api/time-track/me', null, $this->login())['monitoring'] ?? null;
        self::assertSame([false, true, false], [$locked['enabled'] ?? null, $locked['lockedUntilChecklist'] ?? null, $locked['screenshots']['enabled'] ?? null], 'a locked policy always says enabled: false');

        $this->policySource()->policy = null;
        $me                           = $this->api('GET', '/api/time-track/me', null, $this->login());
        self::assertFalse($me['features']['monitoring'] ?? null);
        self::assertIsArray($me);
        self::assertArrayNotHasKey('monitoring', $me, 'no policy for this user: no block at all');

        $this->policySource()->failWith = 'ZZ-POLICY-SECRET';
        $me                             = $this->api('GET', '/api/time-track/me', null, $this->login());
        self::assertResponseStatusCodeSame(200);
        self::assertFalse($me['features']['monitoring'] ?? null, 'a failing host port counts as off');
        self::assertNotContains('ZZ-POLICY-SECRET', $this->logger()->contextValues());
    }

    public function testAcknowledge(): void
    {
        $token = $this->token();
        $body  = ['policyVersion' => 7, 'noticeSha256' => MonitoringFixtures::noticeSha256(), 'shownAt' => '2026-11-20T08:59:41Z'];

        $this->api('POST', self::ACK, $body, $this->login(), ['HTTP_IDEMPOTENCY_KEY' => 'k']);
        self::assertResponseStatusCodeSame(403, 'monitoring:write is never a default scope');
        $this->api('POST', self::ACK, $body, $token);
        self::assertResponseStatusCodeSame(400, 'Idempotency-Key is required');
        foreach (['policyVersion' => 0, 'noticeSha256' => 'abc', 'shownAt' => 'yesterday'] as $field => $value) {
            $answer = $this->api('POST', self::ACK, [$field => $value] + $body, $token, ['HTTP_IDEMPOTENCY_KEY' => 'v-' . $field]);
            self::assertResponseStatusCodeSame(400);
            self::assertSame(['invalid_request', $field], [$answer['error'] ?? null, $answer['field'] ?? null]);
        }

        $answer = $this->api('POST', self::ACK, ['policyVersion' => 6] + $body, $token, ['HTTP_IDEMPOTENCY_KEY' => 'old']);
        self::assertResponseStatusCodeSame(409);
        self::assertSame(['policy_changed', 7], [$answer['error'] ?? null, $answer['policyVersion'] ?? null]);
        $answer = $this->api('POST', self::ACK, ['noticeSha256' => hash('sha256', 'a stale cached notice')] + $body, $token, ['HTTP_IDEMPOTENCY_KEY' => 'stale']);
        self::assertResponseStatusCodeSame(422);
        self::assertSame('notice_mismatch', $answer['error'] ?? null);

        $this->api('POST', self::ACK, $body, $token, ['HTTP_IDEMPOTENCY_KEY' => 'ok']);
        self::assertResponseStatusCodeSame(204);
        self::assertSame('', (string) $this->client->getResponse()->getContent());
        $this->api('POST', self::ACK, $body, $token, ['HTTP_IDEMPOTENCY_KEY' => 'ok']);
        self::assertResponseStatusCodeSame(204, 'a replay');
        $this->api('POST', self::ACK, ['shownAt' => '2026-11-20T09:00:00Z'] + $body, $token, ['HTTP_IDEMPOTENCY_KEY' => 'ok']);
        self::assertResponseStatusCodeSame(422);
        $this->api('POST', self::ACK, ['policyVersion' => 6] + $body, $token, ['HTTP_IDEMPOTENCY_KEY' => 'old']);
        self::assertResponseStatusCodeSame(409, 'a 409 is replayed too');
        $this->api('POST', self::ACK, $body, $token, ['HTTP_IDEMPOTENCY_KEY' => 'again']);
        self::assertResponseStatusCodeSame(204, 'acknowledging twice keeps the first record');

        $this->policySource()->policy = null;
        $answer                       = $this->api('POST', self::ACK, $body, $token, ['HTTP_IDEMPOTENCY_KEY' => 'none']);
        self::assertResponseStatusCodeSame(409);
        self::assertIsArray($answer);
        self::assertArrayHasKey('policyVersion', $answer);
        self::assertSame(['policy_changed', null], [$answer['error'] ?? null, $answer['policyVersion']]);
        $this->policySource()->failWith = 'down';
        $this->api('POST', self::ACK, $body, $token, ['HTTP_IDEMPOTENCY_KEY' => 'down']);
        self::assertResponseStatusCodeSame(503);
        self::assertSame('60', $this->client->getResponse()->headers->get('Retry-After'));

        $actions = array_map(static fn (ClientApiEvent $event): string => $event->action->value . ':' . $event->reason, $this->events);
        self::assertContains('monitoring_acknowledged:v7', $actions);
        self::assertContains('monitoring_refused:notice_mismatch', $actions);
        self::assertContains('monitoring_refused:policy_changed', $actions);
    }

    public function testTheTwoStepUploadStoresTheCheckedImage(): void
    {
        $token = $this->token();
        $this->acknowledge($token);
        $timer = $this->startTimer($token);
        $image = MonitoringFixtures::webp(1280, 720);
        $body  = $this->intentBody($timer, $image);

        $grant = $this->intent($token, $body);
        self::assertResponseStatusCodeSame(201);
        self::assertSame(['uploadId', 'url', 'method', 'headers', 'expiresAt'], array_keys((array) $grant));
        self::assertSame(['PUT', ['Content-Type' => 'image/webp']], [$grant['method'] ?? null, $grant['headers'] ?? null]);
        self::assertMatchesRegularExpression('#^http://localhost/api/time-track/monitoring/uploads/[0-9a-f-]{36}\?exp=\d+&sig=[0-9a-f]{64}$#', self::url($grant));
        $this->assertPrivacyHeaders();
        $replay = $this->intent($token, $body);
        self::assertResponseStatusCodeSame(200, 'a lost answer is safe to ask again');
        self::assertSame($grant, $replay, 'the same body while the URL is valid');

        $stored = $this->put(self::url($grant), $token, $image);
        self::assertResponseStatusCodeSame(201);
        $id = $stored['id'] ?? null;
        self::assertSame('shot-1', $id);
        $shot = $this->evidence()->screenshots['shot-1'];
        self::assertSame([(string) $this->user->getId(), $timer, 7, true, 'image/webp', strlen($image), 1280, 720, hash('sha256', $image), $image], [$shot['user'], $shot['entryId'], $shot['policyVersion'], $shot['blurred'], $shot['mime'], $shot['bytes'], $shot['width'], $shot['height'], $shot['sha256'], $shot['content']]);
        $this->assertPrivacyHeaders();

        self::assertSame(['id' => 'shot-1', 'status' => 'stored'], $this->intent($token, $body));
        self::assertResponseStatusCodeSame(200);
        $again = $this->put(self::url($grant), $token, $image);
        self::assertResponseStatusCodeSame(403, 'single use');
        self::assertSame('invalid_signature', $again['error'] ?? null);
        self::assertSame([], glob(sys_get_temp_dir() . '/tt_monitoring_*') ?: [], 'no temporary file is left');

        $actions = array_map(static fn (ClientApiEvent $event): string => $event->action->value, $this->events);
        self::assertContains('monitoring_uploaded', $actions);
        $haystack = (string) json_encode([$this->logger()->records, array_map(static fn (ClientApiEvent $event): array => $event->toArray(), $this->events)]);
        foreach ([hash('sha256', $image), (string) ($grant['uploadId'] ?? 'x'), 'sig='] as $secret) {
            self::assertStringNotContainsString($secret, $haystack, 'no hash, upload id or signature is logged or dispatched');
        }

        // A JPEG of a recorded desktop entry, in the next slot of the past.
        $start = new DateTimeImmutable('-3 hours');
        $entry = $this->recordedEntry($start, new DateTimeImmutable('-1 hour'));
        $jpeg  = MonitoringFixtures::jpeg(800, 600, 64);
        $grant = $this->intent($token, $this->intentBody($entry, $jpeg, ['mime' => 'image/jpeg', 'width' => 800, 'height' => 600], $start->getTimestamp() + 900), 'cap-2');
        self::assertResponseStatusCodeSame(201);
        $this->put(self::url($grant), $token, $jpeg, 'image/jpeg; charset=binary');
        self::assertResponseStatusCodeSame(201);
        self::assertSame(['image/jpeg', $jpeg], [$this->evidence()->screenshots['shot-2']['mime'], $this->evidence()->screenshots['shot-2']['content']]);
    }

    public function testEveryRefusalHappensAtIntentTime(): void
    {
        $token   = $this->token();
        $image   = MonitoringFixtures::webp(1280, 720);
        $timer   = $this->startTimer($token);
        $refused = function (string $reason, array $body, string $key) use ($token): void {
            $answer = $this->intent($token, $body, $key);
            self::assertResponseStatusCodeSame(422, $reason);
            self::assertSame(['monitoring_refused', $reason], [$answer['error'] ?? null, $answer['reason'] ?? null]);
        };

        $refused('not_acknowledged', $this->intentBody($timer, $image), 'r1');
        $this->acknowledge($token, 7);
        foreach ([
            null,
            MonitoringFixtures::policy(7, ['apps', 'activity']),
            MonitoringFixtures::policy(7, overrides: ['enabled' => false]),
            MonitoringFixtures::policy(7, overrides: ['lockedUntilChecklist' => true]),
        ] as $i => $policy) {
            $this->policySource()->policy = $policy;
            $refused('kind_off', $this->intentBody($timer, $image), 'k' . $i);
        }
        $this->policySource()->policy = MonitoringFixtures::policy(8);
        $refused('not_acknowledged', $this->intentBody($timer, $image), 'r2');
        $this->policySource()->policy = MonitoringFixtures::policy(8, overrides: ['acknowledgementRequired' => false]);
        $this->intent($token, $this->intentBody($timer, $image), 'narrowed');
        self::assertResponseStatusCodeSame(201, 'a narrowing version needs no new acknowledgement');
        $this->policySource()->policy = MonitoringFixtures::policy();

        // SC-002: the worker's own timer ran on entryId at takenAt.
        $refused('outside_timer', $this->intentBody('00000000-0000-0000-0000-000000000000', $image), 't1');
        $refused('outside_timer', $this->intentBody($timer, $image, takenAt: time() - 3600), 't2');
        $manual = $this->recordedEntry(new DateTimeImmutable('-2 hours'), new DateTimeImmutable('-1 hour'), TimeEntrySource::Manual);
        $refused('outside_timer', $this->intentBody($manual, $image, takenAt: time() - 5400), 't3');
        $other      = $this->createUser('other@example.com');
        $otherTimer = $this->startTimer($this->token($other));
        $refused('outside_timer', $this->intentBody($otherTimer, $image), 't4');
        $ended = $this->recordedEntry(new DateTimeImmutable('-2 hours'), new DateTimeImmutable('-1 hour'));
        $refused('outside_timer', $this->intentBody($ended, $image, takenAt: time() - 1800), 't5');

        // Spec 198 schedule through the working calendar (here derived from the working hours).
        $this->profile()->hours = ['timezone' => 'UTC', 'slots' => [['day' => (int) gmdate('N'), 'from' => '00:00', 'to' => '00:01']]];
        $refused('outside_hours', $this->intentBody($timer, $image), 'h1');
        $this->profile()->hours = ['timezone' => 'UTC', 'slots' => [['day' => (int) gmdate('N', time() + 86_400), 'from' => '00:00', 'to' => '23:59']]];
        $refused('outside_hours', $this->intentBody($timer, $image), 'h2');
        $this->profile()->hours = null;
        $past                   = $this->recordedEntry(new DateTimeImmutable('-3 hours'), new DateTimeImmutable('-1 hour'));
        $this->intent($token, $this->intentBody($past, $image, takenAt: time() - 7200), 'no-schedule');
        self::assertResponseStatusCodeSame(201, 'no schedule from the host: no restriction');
        $this->profile()->hours = (new MutableProfileProvider())->hours;

        $old = $this->recordedEntry(new DateTimeImmutable('-30 hours'), new DateTimeImmutable('-28 hours'));
        $refused('too_old', $this->intentBody($old, $image, takenAt: time() - 29 * 3600), 'o1');

        // The slot already has a screenshot, or another live intent holds it.
        $this->evidence()->taken = true;
        $answer                  = $this->intent($token, $this->intentBody($timer, $image), 'd1');
        self::assertResponseStatusCodeSame(409);
        self::assertSame('duplicate', $answer['error'] ?? null);
        $this->evidence()->taken = null;
        $this->intent($token, $this->intentBody($timer, $image), 'd2');
        self::assertResponseStatusCodeSame(409, 'the slot is held by the narrowed intent above');

        self::assertSame([], $this->evidence()->screenshots, 'nothing was ever stored');
        $reasons = array_values(array_filter(array_map(static fn (ClientApiEvent $event): ?string => $event->action->value === 'monitoring_refused' ? $event->reason : null, $this->events)));
        self::assertContains('outside_timer', $reasons);
        self::assertContains('duplicate', $reasons);
    }

    public function testIntentValidation(): void
    {
        $token = $this->token();
        $this->acknowledge($token);
        $timer = $this->startTimer($token);
        $image = MonitoringFixtures::webp(1280, 720);
        $base  = $this->intentBody($timer, $image);

        $this->api('POST', self::INTENT, $base, $token);
        self::assertResponseStatusCodeSame(400, 'Idempotency-Key is required');
        foreach ([
            'entryId'       => 'not an id!',
            'slot'          => 'soon',
            'takenAt'       => gmdate('Y-m-d\TH:i:s\Z', time() + 600),
            'policyVersion' => '7',
            'blurred'       => 'yes',
            'mime'          => 5,
            'bytes'         => 0,
            'width'         => 3841,
            'height'        => 0,
            'sha256'        => 'ABC',
        ] as $field => $value) {
            $answer = $this->intent($token, [$field => $value] + $base, 'v-' . $field);
            self::assertResponseStatusCodeSame(400, $field);
            self::assertSame(['invalid_request', $field], [$answer['error'] ?? null, $answer['field'] ?? null]);
        }
        $this->intent($token, ['mime' => 'image/png'] + $base, 'png');
        self::assertResponseStatusCodeSame(415);
        $this->intent($token, ['bytes' => 1_572_865] + $base, 'big');
        self::assertResponseStatusCodeSame(413);
        $answer = $this->intent($token, ['slot' => gmdate('Y-m-d\TH:i:s\Z', time() + 60)] + $base, 'slot-after');
        self::assertSame(['invalid_request', 'slot'], [$answer['error'] ?? null, $answer['field'] ?? null], 'takenAt lies in its slot');
        $answer = $this->intent($token, ['blurred' => false] + $base, 'unblurred');
        self::assertSame(['invalid_request', 'blurred'], [$answer['error'] ?? null, $answer['field'] ?? null], 'only blurred images without fullResolution');
        $answer = $this->intent($token, ['blurred' => false, 'policyVersion' => 6] + $base, 'unblurred-old');
        self::assertResponseStatusCodeSame(409, 'an unblurred capture of an older full-resolution version');
        self::assertSame(['policy_changed', 7], [$answer['error'] ?? null, $answer['policyVersion'] ?? null]);
        $this->intent($token, ['policyVersion' => 8] + $base, 'newer');
        self::assertResponseStatusCodeSame(409);

        $this->intent($token, $base, 'same');
        self::assertResponseStatusCodeSame(201);
        $answer = $this->intent($token, ['width' => 1281] + $base, 'same');
        self::assertResponseStatusCodeSame(422);
        self::assertSame('idempotency_mismatch', $answer['error'] ?? null);

        $this->policySource()->policy = MonitoringFixtures::policy(7, overrides: ['fullResolution' => true, 'blur' => 'none']);
        $this->evidence()->taken      = false;
        $this->intent($token, ['blurred' => false, 'slot' => gmdate('Y-m-d\TH:i:s\Z', time() - time() % 600 - 600)] + $base, 'full');
        self::assertResponseStatusCodeSame(400, 'still the slot rule');
        $this->policySource()->failWith = 'down';
        $this->intent($token, $base, 'down');
        self::assertResponseStatusCodeSame(503);
    }

    public function testThePutEnforcesEveryCapWhileStreaming(): void
    {
        $token = $this->token();
        $this->acknowledge($token);
        $timer = $this->startTimer($token);
        $image = MonitoringFixtures::webp(1280, 720);
        $taken = time();
        $grant = function (string $key, string $content, array $overrides = []) use ($token, $timer, $taken): string {
            $answer = $this->intent($token, $this->intentBody($timer, $content, $overrides, $taken), $key);
            self::assertContains($this->client->getResponse()->getStatusCode(), [200, 201], $key);

            return self::url($answer);
        };
        $fails = function (int $status, string $error, ?string $field, string $url, string $content, string $type = 'image/webp', ?int $length = null) use ($token): void {
            $answer = $this->put($url, $token, $content, $type, $length);
            self::assertResponseStatusCodeSame($status, $error . ' ' . $field);
            self::assertSame([$error, $field], [$answer['error'] ?? null, $answer['field'] ?? null]);
            $this->assertPrivacyHeaders();
        };

        $url = $grant('c1', $image);
        $fails(415, 'unsupported_media_type', null, $url, $image, 'image/jpeg');
        $fails(403, 'invalid_signature', null, $url, $image);
        $renewed = $grant('c1', $image);
        self::assertNotSame($url, $renewed, 'a replay after a failed PUT signs a new URL');
        $fails(413, 'too_large', null, $renewed, $image, 'image/webp', 1_572_865);
        $fails(422, 'upload_mismatch', 'bytes', $grant('c1', $image), $image, 'image/webp', strlen($image) + 1);
        $fails(422, 'upload_mismatch', 'bytes', $grant('c1', $image), $image, 'image/webp', -1);
        $short = $grant('c1', $image);
        $fails(422, 'upload_mismatch', 'bytes', $short, substr($image, 0, -2), 'image/webp', strlen($image));
        $fails(422, 'upload_mismatch', 'bytes', $grant('c1', $image), $image . 'xx', 'image/webp', strlen($image));
        $fails(422, 'upload_mismatch', 'sha256', $grant('c1', $image), MonitoringFixtures::webp(1280, 721));

        // The declared hash is right, the bytes are not an image / not the declared one.
        $text = str_repeat('not an image ', 4);
        $fails(415, 'unsupported_media_type', null, $grant('c2', $text), $text);
        $jpeg = MonitoringFixtures::jpeg(1280, 720);
        $fails(422, 'upload_mismatch', 'mime', $grant('c3', $jpeg), $jpeg);
        $garbage = 'RIFF' . "\x04\x00\x00\x00" . 'WEBPVP8Z' . str_repeat("\x00", 12);
        $fails(415, 'unsupported_media_type', null, $grant('c4', $garbage), $garbage);
        $wrong = MonitoringFixtures::webp(1280, 700);
        $fails(422, 'upload_mismatch', 'dimensions', $grant('c5', $wrong), $wrong);
        $huge = MonitoringFixtures::webp(3841, 100);
        $fails(422, 'upload_mismatch', 'dimensions', $grant('c6', $huge, ['width' => 3840, 'height' => 100]), $huge);
        self::assertSame([], $this->evidence()->screenshots);

        // Signatures: tampered, another user's upload, expired, malformed.
        $good = $grant('c7', $image);
        $fails(403, 'invalid_signature', null, substr($good, 0, -1) . (str_ends_with($good, 'a') ? 'b' : 'a'), $image);
        $good   = $grant('c7', $image);
        $other  = $this->createUser('other@example.com');
        $answer = $this->put($good, $this->token($other), $image);
        self::assertResponseStatusCodeSame(403, 'another user cannot use the URL');
        self::assertSame('invalid_signature', $answer['error'] ?? null);
        $this->put($good, $this->token($this->user), $image);
        self::assertResponseStatusCodeSame(403, 'another token of the same user cannot either (the URL is bound to the token)');
        $this->put((string) preg_replace('/exp=\d+/', 'exp=0', $this->renew($grant, 'c7', $image)), $token, $image);
        self::assertResponseStatusCodeSame(403);
        $this->put((string) preg_replace('/exp=(\d+)/', 'exp=1$1', $this->renew($grant, 'c7', $image)), $token, $image);
        self::assertResponseStatusCodeSame(403);

        // The policy is re-checked before any byte is read.
        $url                          = $this->renew($grant, 'c7', $image);
        $this->policySource()->policy = MonitoringFixtures::policy(7, ['apps']);
        $fails(422, 'monitoring_refused', null, $url, $image);
        $this->policySource()->policy = null;
        $fails(422, 'monitoring_refused', null, $this->renewWith($token, 'c7', $timer, $image, $taken), $image);
        $this->policySource()->policy = MonitoringFixtures::policy(8);
        $fails(422, 'monitoring_refused', null, $this->renewWith($token, 'c7', $timer, $image, $taken), $image);
        $this->policySource()->policy = MonitoringFixtures::policy(6);
        $fails(409, 'policy_changed', null, $this->renewWith($token, 'c7', $timer, $image, $taken), $image);
        $this->policySource()->policy = MonitoringFixtures::policy();
        $url                          = $this->renew($grant, 'c7', $image);
        $this->evidence()->taken      = true;
        $fails(409, 'duplicate', null, $url, $image);
        $this->evidence()->taken  = null;
        $this->evidence()->nextId = 'bad id';
        $fails(503, 'monitoring_unavailable', null, $this->renew($grant, 'c7', $image), $image);
        // The host kept that one (it only returned an unusable id): forget it, as a host would after a failure.
        $this->evidence()->nextId      = null;
        $this->evidence()->screenshots = [];
        $url                           = $this->renew($grant, 'c7', $image);
        $this->evidence()->failWith    = 'down';
        $fails(503, 'monitoring_unavailable', null, $url, $image);
        $this->evidence()->failWith = null;
        $this->put($this->renew($grant, 'c7', $image), $token, $image);
        self::assertResponseStatusCodeSame(201, 'after every failure the intent can still be uploaded');
        self::assertSame([], glob(sys_get_temp_dir() . '/tt_monitoring_*') ?: [], 'no temporary file is left');
    }

    public function testDiscardedAndDelete(): void
    {
        $token = $this->token();
        $this->acknowledge($token);
        $timer = $this->startTimer($token);
        $slot  = gmdate('Y-m-d\TH:i:s\Z', time() - time() % 600);

        $this->api('POST', self::DISCARDED, ['entryId' => $timer, 'slot' => $slot, 'reason' => 'worker'], $token);
        self::assertResponseStatusCodeSame(204);
        self::assertSame([['user' => (string) $this->user->getId(), 'entryId' => $timer, 'slot' => (new DateTimeImmutable($slot))->format(DATE_ATOM)]], $this->evidence()->discarded);
        foreach (['entryId' => '../x', 'slot' => gmdate('Y-m-d\TH:i:s\Z', time() + 3600), 'reason' => 'manager'] as $field => $value) {
            $answer = $this->api('POST', self::DISCARDED, [$field => $value] + ['entryId' => $timer, 'slot' => $slot, 'reason' => 'worker'], $token);
            self::assertResponseStatusCodeSame(400);
            self::assertSame($field, $answer['field'] ?? null);
        }
        $answer = $this->api('POST', self::DISCARDED, ['entryId' => '00000000-0000-0000-0000-000000000000', 'slot' => $slot, 'reason' => 'worker'], $token);
        self::assertSame([422, 'outside_timer'], [$this->client->getResponse()->getStatusCode(), $answer['reason'] ?? null]);
        $old    = $this->recordedEntry(new DateTimeImmutable('-30 hours'), new DateTimeImmutable('-28 hours'));
        $answer = $this->api('POST', self::DISCARDED, ['entryId' => $old, 'slot' => gmdate('Y-m-d\TH:i:s\Z', time() - 29 * 3600), 'reason' => 'worker'], $token);
        self::assertSame([422, 'too_old'], [$this->client->getResponse()->getStatusCode(), $answer['reason'] ?? null]);
        $this->policySource()->policy = null;
        $answer                       = $this->api('POST', self::DISCARDED, ['entryId' => $timer, 'slot' => $slot, 'reason' => 'worker'], $token);
        self::assertSame([422, 'kind_off'], [$this->client->getResponse()->getStatusCode(), $answer['reason'] ?? null]);
        $this->policySource()->policy = MonitoringFixtures::policy();
        $this->evidence()->failWith   = 'down';
        $this->api('POST', self::DISCARDED, ['entryId' => $timer, 'slot' => $slot, 'reason' => 'worker'], $token);
        self::assertResponseStatusCodeSame(503);
        $this->evidence()->failWith = null;

        // DELETE: the worker's own screenshot only, whatever the policy says now.
        $image = MonitoringFixtures::webp(1280, 720);
        $this->put(self::url($this->intent($token, $this->intentBody($timer, $image))), $token, $image);
        self::assertResponseStatusCodeSame(201);
        $other = $this->createUser('other@example.com');
        $this->api('DELETE', '/api/time-track/monitoring/screenshots/shot-1', null, $this->token($other));
        self::assertResponseStatusCodeSame(404, 'not theirs');
        $this->policySource()->policy = null;
        $this->api('DELETE', '/api/time-track/monitoring/screenshots/shot-1', null, $token);
        self::assertResponseStatusCodeSame(204);
        self::assertSame([['user' => (string) $this->user->getId(), 'id' => 'shot-1']], $this->evidence()->deleted);
        $this->api('DELETE', '/api/time-track/monitoring/screenshots/shot-1', null, $token);
        self::assertResponseStatusCodeSame(404);
        $this->evidence()->failWith = 'down';
        $this->api('DELETE', '/api/time-track/monitoring/screenshots/shot-1', null, $token);
        self::assertResponseStatusCodeSame(503);
    }

    public function testActivityBatches(): void
    {
        $token = $this->token();
        $timer = $this->startTimer($token);
        $slot  = gmdate('Y-m-d\TH:i:s\Z', time() - time() % 600);
        $items = [
            ['entryId' => $timer, 'slot' => $slot, 'kind' => 'app', 'name' => 'Mail ana@example.com 123456', 'seconds' => 300],
            ['entryId' => $timer, 'slot' => $slot, 'kind' => 'app', 'name' => 'VaultApp 8', 'seconds' => 10],
            ['entryId' => $timer, 'slot' => $slot, 'kind' => 'level', 'seconds' => 600, 'level' => 72],
            ['entryId' => '00000000-0000-0000-0000-000000000000', 'slot' => $slot, 'kind' => 'level', 'seconds' => 600, 'level' => 10],
        ];
        $send = fn (array $body, string $key = 'a-1'): ?array => $this->api('POST', self::ACTIVITY, $body, $token, ['HTTP_IDEMPOTENCY_KEY' => $key]);

        $answer = $send(['items' => $items], 'before-ack');
        self::assertSame([422, 'not_acknowledged'], [$this->client->getResponse()->getStatusCode(), $answer['reason'] ?? null]);
        $this->acknowledge($token);
        $this->api('POST', self::ACTIVITY, ['items' => $items], $token);
        self::assertResponseStatusCodeSame(400, 'Idempotency-Key is required');
        foreach ([
            [],
            ['items' => []],
            ['items' => ['a' => 1]],
            ['items' => array_fill(0, 201, $items[2])],
            ['items' => [['name' => null] + $items[0]]],
            ['items' => [['kind' => 'domain'] + $items[0]]],
            ['items' => [['seconds' => 601] + $items[0]]],
            ['items' => [['slot' => gmdate('Y-m-d\TH:i:s\Z', time() + 3600)] + $items[2]]],
            ['items' => [['level' => '72'] + $items[2]]],
            ['items' => [['name' => 5] + $items[0]]],
            ['items' => [['entryId' => 'a/b'] + $items[0]]],
            ['items' => ['x']],
        ] as $i => $invalid) {
            $answer = $send($invalid, 'bad-' . $i);
            self::assertResponseStatusCodeSame(400, (string) $i);
            self::assertSame('items', $answer['field'] ?? null);
        }

        $answer = $send(['items' => $items]);
        self::assertResponseStatusCodeSame(202);
        self::assertSame(['accepted' => 2, 'dropped' => 2], $answer, 'the excluded app and the foreign entry are dropped');
        self::assertSame($answer, $send(['items' => $items]), 'a replay');
        self::assertResponseStatusCodeSame(202);
        $send(['items' => [$items[2]]]);
        self::assertResponseStatusCodeSame(422, 'same key, another body');
        $stored = $this->evidence()->activity;
        self::assertCount(1, $stored);
        self::assertSame((string) $this->user->getId(), $stored[0]['user']);
        self::assertSame(['Mail [email] [digits]', null], array_map(static fn (ActivityItem $item): ?string => $item->name, $stored[0]['items']), 'names are redacted again by the bundle');
        self::assertSame([72], array_values(array_filter(array_column(array_map(static fn (ActivityItem $item): array => (array) $item, $stored[0]['items']), 'level'))));

        $this->policySource()->policy = MonitoringFixtures::policy(7, ['screenshots', 'activity']);
        self::assertSame('kind_off', $send(['items' => $items], 'apps-off')['reason'] ?? null);
        $this->policySource()->policy = MonitoringFixtures::policy(7, overrides: ['enabled' => false]);
        self::assertSame('kind_off', $send(['items' => $items], 'off')['reason'] ?? null);
        $this->policySource()->policy = MonitoringFixtures::policy();
        $answer                       = $send(['items' => [$items[3]]], 'nothing');
        self::assertSame(['accepted' => 0, 'dropped' => 1], $answer);
        self::assertCount(1, $this->evidence()->activity, 'nothing to store: the host is not called');
        $this->evidence()->failWith = 'down';
        $send(['items' => $items], 'down');
        self::assertResponseStatusCodeSame(503);
        self::assertNotContains('Mail ana@example.com 123456', $this->logger()->contextValues());
    }

    public function testARaceOnTheSameKeyAndAFailingRelease(): void
    {
        $token = $this->token();
        $this->acknowledge($token);
        $timer  = $this->startTimer($token);
        $image  = MonitoringFixtures::webp(1280, 720);
        $racing = self::getContainer()->get(RacingMonitoringIntentRepository::class);
        self::assertInstanceOf(RacingMonitoringIntentRepository::class, $racing);

        $racing->raceNextSave = true;
        $grant                = $this->intent($token, $this->intentBody($timer, $image), 'raced');
        self::assertResponseStatusCodeSame(200, 'the concurrent request with the same key won: answered as its replay');
        self::assertMatchesRegularExpression('#/uploads/[0-9a-f-]{36}\?#', self::url($grant));

        $racing->raceNextSave = true;
        $racing->raceBody     = hash('sha256', 'another body');
        $past                 = $this->recordedEntry(new DateTimeImmutable('-3 hours'), new DateTimeImmutable('-1 hour'));
        $answer               = $this->intent($token, $this->intentBody($past, $image, takenAt: time() - 7200), 'raced-other');
        self::assertResponseStatusCodeSame(422);
        self::assertSame('idempotency_mismatch', $answer['error'] ?? null);

        $racing->failRelease = true;
        $this->put(self::url($grant), $token, $image, 'image/jpeg');
        self::assertResponseStatusCodeSame(415, 'the answer does not depend on the release');
        self::assertContains('client_monitoring_upload', array_column(array_column($this->logger()->records, 'context'), 'action'));
        self::assertContains('TimeTrack monitoring upload not released.', $this->logger()->messages());

        $racing->failFind = true;
        $this->put(self::url($grant), $token, $image);
        self::assertResponseStatusCodeSame(503);
    }

    public function testOptionsAndScope(): void
    {
        foreach ([['POST', self::ACK], ['POST', self::INTENT], ['PUT', '/api/time-track/monitoring/uploads/00000000-0000-4000-8000-000000000000'], ['POST', self::DISCARDED], ['DELETE', '/api/time-track/monitoring/screenshots/x'], ['POST', self::ACTIVITY]] as [$method, $uri]) {
            $this->api('OPTIONS', $uri, null, null, ['HTTP_ORIGIN' => 'chrome-extension://functional']);
            self::assertResponseStatusCodeSame(204, $uri);
            $this->api($method, $uri);
            self::assertResponseStatusCodeSame(401, $uri);
            $this->api($method, $uri, null, $this->token(null, ClientScope::TimerRead));
            self::assertResponseStatusCodeSame(403, $uri);
            $this->assertPrivacyHeaders();
        }
    }

    private function renew(callable $grant, string $key, string $image): string
    {
        return $grant($key, $image);
    }

    /**
     * Signs the intent again under a policy that allows it (the current one is set back to `$current` afterwards).
     */
    private function renewWith(string $token, string $key, string $timer, string $image, int $taken): string
    {
        $current                      = $this->policySource()->policy;
        $this->policySource()->policy = MonitoringFixtures::policy();
        $url                          = self::url($this->intent($token, $this->intentBody($timer, $image, takenAt: $taken), $key));
        $this->policySource()->policy = $current;

        return $url;
    }

    private function assertPrivacyHeaders(): void
    {
        $headers = $this->client->getResponse()->headers;
        self::assertSame('no-store, private', $headers->get('Cache-Control'));
        self::assertSame('no-cache', $headers->get('Pragma'));
        self::assertSame('nosniff', $headers->get('X-Content-Type-Options'));
        self::assertStringContainsString('Authorization', implode(', ', $headers->all('vary')));
    }
}

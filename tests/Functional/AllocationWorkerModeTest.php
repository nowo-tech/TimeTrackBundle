<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Functional;

use DateTimeImmutable;
use Nowo\TimeTrackBundle\Entity\TimeEntry;
use Nowo\TimeTrackBundle\Enum\TimeEntrySource;
use Nowo\TimeTrackBundle\Port\TimeTrackUserRefs;
use Nowo\TimeTrackBundle\Query\TimeEntryRecord;
use Nowo\TimeTrackBundle\Subject\TimeSubjectContext;
use Nowo\TimeTrackBundle\Subject\TimeSubjectLabelResolver;
use Nowo\TimeTrackBundle\Subject\TimeSubjectRef;
use Nowo\TimeTrackBundle\Tests\Fixtures\FunctionalEntity\AppUser;
use Nowo\TimeTrackBundle\Tests\Support\CustomerTestSubjectProvider;
use Nowo\TimeTrackBundle\Tests\Support\FunctionalKernel;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\HttpKernel\TerminableInterface;

use function array_column;
use function array_map;
use function array_slice;
use function assert;
use function count;
use function is_array;
use function json_decode;
use function json_encode;
use function sprintf;

use const JSON_THROW_ON_ERROR;

/**
 * Since 2.1.0 (Beacon spec 256, design §13 worker mode, REQ-RUNTIME-002): subject labels in FrankenPHP worker mode
 * with the kernel kept alive and **not reset** (no services resetter between requests). Two viewers take turns on one
 * kernel: each page is labelled for its own viewer only (one `describe()` per page with that viewer as actor), and the
 * second viewer never sees a label, id or URL the first one was allowed to read.
 */
final class AllocationWorkerModeTest extends FunctionalTestCase
{
    private AppUser $other;

    /**
     * @param array<string, mixed> $options
     */
    protected static function createKernel(array $options = []): KernelInterface
    {
        return new FunctionalKernel(self::timeTrackConfig(), false, false, false, allocationProviders: true);
    }

    protected static function timeTrackConfig(): array
    {
        return ['client_api' => ['rate_limit' => ['per_token' => 0, 'per_ip' => 0]]];
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->other = $this->createUser('other@example.com');
        $provider    = $this->provider();
        $provider->reset();
        // The person reads both customers; the other person reads neither (not even the one their own entry is on).
        $provider->readableBy = [(string) $this->user->getId() => ['c-1', 'c-2']];
        $this->entry($this->user, 'c-1', 3);
        $this->entry($this->user, 'c-2', 2);
        $this->entry($this->other, 'c-1', 1);
    }

    public function testTwoViewersInOneKernelNeverSeeEachOthersLabels(): void
    {
        $tokens = ['person' => $this->login(), 'other' => $this->login('other@example.com')];
        $ids    = ['person' => (string) $this->user->getId(), 'other' => (string) $this->other->getId()];

        for ($round = 0; $round < 40; ++$round) {
            $calls  = count($this->provider()->describeCalls);
            $mine   = $this->call('/api/time-track/entries', $tokens['person']);
            $theirs = $this->call('/api/time-track/entries', $tokens['other']);

            $subjects = array_column($mine['entries'] ?? [], 'subject');
            self::assertSame([
                ['type' => 'customer', 'id' => 'c-2', 'label' => 'customer c-2'],
                ['type' => 'customer', 'id' => 'c-1', 'label' => 'customer c-1'],
            ], array_map(static fn (array $s): array => ['type' => $s['type'] ?? null, 'id' => $s['id'] ?? null, 'label' => $s['label'] ?? null], $subjects), sprintf('round %d: the person reads both', $round));

            $masked = array_column($theirs['entries'] ?? [], 'subject');
            self::assertCount(1, $masked);
            self::assertSame('customer', $masked[0]['type'] ?? null);
            self::assertTrue($masked[0]['hidden'] ?? null, sprintf('round %d: masked for the other viewer', $round));
            self::assertArrayNotHasKey('id', $masked[0], 'no id when masked');
            self::assertArrayNotHasKey('url', $masked[0]);
            $raw = json_encode($theirs, JSON_THROW_ON_ERROR);
            // Quoted: an entry UUID may contain the bytes "c-2" (e.g. "…b22c-2a0f…").
            foreach (['customer c-1', 'customer c-2', '"c-1"', '"c-2"', 'crm.example.test'] as $leak) {
                self::assertStringNotContainsString($leak, $raw, sprintf('round %d: nothing of the first viewer\'s page', $round));
            }

            // One describe() per page, each with that page's viewer as actor.
            $calls = array_slice($this->provider()->describeCalls, $calls);
            self::assertSame([$ids['person'], $ids['other']], array_column($calls, 'actor'));
        }
    }

    public function testThePublicResolverLabelsEachViewersPageForThatViewerOnly(): void
    {
        $resolver = self::getContainer()->get(TimeSubjectLabelResolver::class);
        self::assertInstanceOf(TimeSubjectLabelResolver::class, $resolver);
        $views = [];
        foreach ($this->bundleEntityManager()->getRepository(TimeEntry::class)->findAll() as $entry) {
            $views[] = TimeEntryRecord::fromEntry($entry)->entry;
        }
        $c1 = new TimeSubjectRef('customer', 'c-1');

        for ($round = 0; $round < 100; ++$round) {
            $first  = $resolver->forPage(new TimeSubjectContext(TimeTrackUserRefs::fromUser($this->user), ''), $views);
            $second = $resolver->forPage(new TimeSubjectContext(TimeTrackUserRefs::fromUser($this->other), ''), $views);

            self::assertSame('customer c-1', $first->get($c1)?->label);
            self::assertSame([], $second->all(), sprintf('round %d: the second viewer sees nothing of the first', $round));
            self::assertFalse($resolver->canRead(new TimeSubjectContext(TimeTrackUserRefs::fromUser($this->other), ''), $c1));
        }
    }

    private function provider(): CustomerTestSubjectProvider
    {
        $provider = self::getContainer()->get(CustomerTestSubjectProvider::class);
        self::assertInstanceOf(CustomerTestSubjectProvider::class, $provider);

        return $provider;
    }

    /**
     * A committed task entry of the owner on the customer, `$hoursAgo` hours ago.
     */
    private function entry(AppUser $owner, string $customer, int $hoursAgo): void
    {
        $at    = new DateTimeImmutable(sprintf('-%d hours', $hoursAgo));
        $start = $at->setTime((int) $at->format('H'), 0);
        $entry = (new TimeEntry($owner, 'task-1', 'Task', null, $start, $start->modify('+30 minutes'), 1800, TimeEntrySource::Web))->allocate(new TimeSubjectRef('customer', $customer), null);
        $this->entityManager()->persist($entry);
        $this->entityManager()->flush();
    }

    /**
     * One GET straight through the container's `http_kernel` (no services resetter), 200 expected; the JSON body.
     *
     * @return array<mixed>
     */
    private function call(string $uri, string $token): array
    {
        $kernel = self::getContainer()->get('http_kernel');
        assert($kernel instanceof HttpKernelInterface && $kernel instanceof TerminableInterface);
        $request  = Request::create($uri, 'GET', [], [], [], ['HTTP_AUTHORIZATION' => 'Bearer ' . $token, 'REMOTE_ADDR' => '203.0.113.7']);
        $response = $kernel->handle($request);
        $kernel->terminate($request, $response);
        self::assertSame(Response::HTTP_OK, $response->getStatusCode(), $uri . ': ' . $response->getContent());
        $body = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertTrue(is_array($body));

        return $body;
    }
}

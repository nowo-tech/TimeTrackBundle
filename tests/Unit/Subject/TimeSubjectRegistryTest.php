<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Unit\Subject;

use Nowo\TimeTrackBundle\Subject\TimeSubjectContext;
use Nowo\TimeTrackBundle\Subject\TimeSubjectLabel;
use Nowo\TimeTrackBundle\Subject\TimeSubjectRef;
use Nowo\TimeTrackBundle\Subject\TimeSubjectRegistry;
use Nowo\TimeTrackBundle\Subject\TimeSubjectSearch;
use Nowo\TimeTrackBundle\Tests\Support\RecordingLogger;
use Nowo\TimeTrackBundle\Tests\Support\SpyTimeSubjectProvider;
use Nowo\TimeTrackBundle\ValueObject\TimeTrackUserRef;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use stdClass;
use Symfony\Component\DependencyInjection\ServiceLocator;

use function array_map;
use function count;
use function range;

/**
 * Since 2.1.0 (Beacon spec 256): the registry denies unknown types and provider failures, batches describe calls and
 * keeps only what was asked.
 */
final class TimeSubjectRegistryTest extends TestCase
{
    private SpyTimeSubjectProvider $provider;

    private RecordingLogger $logger;

    private TimeSubjectRegistry $registry;

    private TimeSubjectContext $context;

    protected function setUp(): void
    {
        $this->provider = new SpyTimeSubjectProvider('project', ['p-1', 'p-2'], ['p-1']);
        $this->logger   = new RecordingLogger();
        $provider       = $this->provider;
        $this->registry = new TimeSubjectRegistry(new ServiceLocator([
            'project' => static fn (): SpyTimeSubjectProvider => $provider,
            'broken'  => static fn (): stdClass => new stdClass(),
            'ghost'   => static fn (): SpyTimeSubjectProvider => $provider,
        ]), ['project' => false, 'broken' => true, 'missing' => true], $this->logger);
        $this->context = new TimeSubjectContext(TimeTrackUserRef::ofId('App\\User', '7'), 'org-a');
    }

    public function testNoneIsOff(): void
    {
        $none = TimeSubjectRegistry::none();

        self::assertFalse($none->isEnabled());
        self::assertSame([], $none->types());
        self::assertFalse($none->has('project'));
        self::assertNull($none->provider('project'));
        self::assertFalse($none->canLog($this->context, new TimeSubjectRef('project', 'p-1')));
    }

    public function testTypesAndTaskRules(): void
    {
        self::assertTrue($this->registry->isEnabled());
        self::assertSame(['project', 'broken', 'missing'], $this->registry->types());
        self::assertTrue($this->registry->has('project'));
        self::assertFalse($this->registry->has('missing'), 'declared but no provider in the locator');
        self::assertFalse($this->registry->has('ghost'), 'in the locator but not declared');
        self::assertFalse($this->registry->allowsTask('project'));
        self::assertTrue($this->registry->allowsTask('broken'));
        self::assertFalse($this->registry->allowsTask('ghost'));
        self::assertSame($this->provider, $this->registry->provider('project'));
        self::assertNull($this->registry->provider('broken'), 'a service that is not a provider is ignored');
    }

    public function testCanLogAsksTheProvider(): void
    {
        self::assertTrue($this->registry->canLog($this->context, new TimeSubjectRef('project', 'p-1')));
        self::assertFalse($this->registry->canLog($this->context, new TimeSubjectRef('project', 'p-2')));
        self::assertFalse($this->registry->canLog($this->context, new TimeSubjectRef('unknown', 'p-1')));
    }

    public function testProviderExceptionsAreDeniedAndLoggedWithTheClassOnly(): void
    {
        $this->provider->fail = true;
        $subject              = new TimeSubjectRef('project', 'p-1');

        self::assertFalse($this->registry->canLog($this->context, $subject));
        self::assertSame([], $this->registry->describe($this->context, 'project', [$subject])->all());
        self::assertSame([], $this->registry->search($this->context, new TimeSubjectSearch('project')));

        self::assertCount(3, $this->logger->records);
        self::assertSame(['subject_can_log', 'subject_describe', 'subject_search'], array_map(static fn (array $record): mixed => $record['context']['action'], $this->logger->records));
        foreach ($this->logger->records as $record) {
            self::assertSame('warning', $record['level']);
            self::assertSame(RuntimeException::class, $record['context']['exception']);
        }
        foreach ($this->logger->contextValues() as $value) {
            self::assertStringNotContainsString('secret', $value);
            self::assertStringNotContainsString('p-1', $value);
            self::assertNotSame('7', $value);
        }
    }

    public function testDescribeKeepsOnlyAskedRefsOfTheType(): void
    {
        $p1                          = new TimeSubjectRef('project', 'p-1');
        $this->provider->extraLabels = [new TimeSubjectLabel(new TimeSubjectRef('project', 'p-9'), 'Not asked')];

        $labels = $this->registry->describe($this->context, 'project', [$p1, new TimeSubjectRef('client', 'p-2'), $p1]);

        self::assertSame(['Label p-1'], array_map(static fn (TimeSubjectLabel $label): string => $label->label, $labels->all()));
        self::assertSame([[$p1]], $this->provider->describeCalls, 'other types and duplicates are not sent');
    }

    public function testDescribeWithoutProviderOrAskedRefsAnswersNone(): void
    {
        self::assertSame([], $this->registry->describe($this->context, 'unknown', [new TimeSubjectRef('unknown', 'x')])->all());
        self::assertSame([], $this->registry->describe($this->context, 'project', [new TimeSubjectRef('client', 'x')])->all());
        self::assertSame(0, $this->provider->describeCount());
    }

    public function testDescribeBatchesBy1000(): void
    {
        $refs = array_map(static fn (int $i): TimeSubjectRef => new TimeSubjectRef('project', 'p-' . $i), range(1, 2001));

        $labels = $this->registry->describe($this->context, 'project', $refs);

        self::assertSame([1000, 1000, 1], array_map(count(...), $this->provider->describeCalls));
        self::assertCount(2, $labels->all());
    }

    public function testDescribeKeepsTheOtherBatchesWhenOneFails(): void
    {
        $provider                     = new SpyTimeSubjectProvider();
        $provider->failOnDescribeCall = 2;
        $provider->readable           = ['p-1', 'p-1500'];
        $registry                     = new TimeSubjectRegistry(new ServiceLocator(['project' => static fn (): SpyTimeSubjectProvider => $provider]), ['project' => true], $this->logger);

        $labels = $registry->describe($this->context, 'project', array_map(static fn (int $i): TimeSubjectRef => new TimeSubjectRef('project', 'p-' . $i), range(1, 1500)));

        self::assertTrue($labels->readable(new TimeSubjectRef('project', 'p-1')));
        self::assertFalse($labels->readable(new TimeSubjectRef('project', 'p-1500')));
        self::assertCount(1, $this->logger->records);
    }

    public function testSearchKeepsTheTypeDedupsAndCutsToTheLimit(): void
    {
        $this->provider->searchResults = [
            'not a label',
            new TimeSubjectLabel(new TimeSubjectRef('client', 'c-1'), 'Other type'),
            new TimeSubjectLabel(new TimeSubjectRef('project', 'p-1'), 'One'),
            new TimeSubjectLabel(new TimeSubjectRef('project', 'p-1'), 'One again'),
            new TimeSubjectLabel(new TimeSubjectRef('project', 'p-2'), 'Two'),
            new TimeSubjectLabel(new TimeSubjectRef('project', 'p-3'), 'Three'),
        ];

        $found = $this->registry->search($this->context, new TimeSubjectSearch('project', '', 2));

        self::assertSame(['One', 'Two'], array_map(static fn (TimeSubjectLabel $label): string => $label->label, $found));
        self::assertCount(3, $this->registry->search($this->context, new TimeSubjectSearch('project')));
        self::assertSame([], $this->registry->search($this->context, new TimeSubjectSearch('unknown')));
    }
}

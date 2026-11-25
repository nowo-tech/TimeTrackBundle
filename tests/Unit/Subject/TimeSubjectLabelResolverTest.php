<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Unit\Subject;

use DateTimeImmutable;
use Nowo\TimeTrackBundle\Dto\TimeEntryView;
use Nowo\TimeTrackBundle\Enum\TimeEntrySource;
use Nowo\TimeTrackBundle\Subject\TimeSubjectContext;
use Nowo\TimeTrackBundle\Subject\TimeSubjectLabelResolver;
use Nowo\TimeTrackBundle\Subject\TimeSubjectRef;
use Nowo\TimeTrackBundle\Subject\TimeSubjectRegistry;
use Nowo\TimeTrackBundle\Tests\Support\SpyTimeSubjectProvider;
use Nowo\TimeTrackBundle\ValueObject\TimeTrackUserRef;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ServiceLocator;

use function array_map;

/**
 * Since 2.1.0 (Beacon spec 256): labels are resolved with one describe call per type per page, in the entry's scope.
 */
final class TimeSubjectLabelResolverTest extends TestCase
{
    private SpyTimeSubjectProvider $projects;

    private SpyTimeSubjectProvider $clients;

    private TimeSubjectLabelResolver $resolver;

    private TimeTrackUserRef $viewer;

    protected function setUp(): void
    {
        $this->projects = new SpyTimeSubjectProvider('project', ['p-1', 'p-2']);
        $this->clients  = new SpyTimeSubjectProvider('client', ['c-1']);
        $projects       = $this->projects;
        $clients        = $this->clients;
        $this->resolver = new TimeSubjectLabelResolver(new TimeSubjectRegistry(new ServiceLocator([
            'project' => static fn (): SpyTimeSubjectProvider => $projects,
            'client'  => static fn (): SpyTimeSubjectProvider => $clients,
        ]), ['project' => true, 'client' => false]));
        $this->viewer = TimeTrackUserRef::ofId('App\\User', '7');
    }

    public function testEnabledFollowsTheRegistry(): void
    {
        self::assertTrue($this->resolver->isEnabled());
        self::assertFalse((new TimeSubjectLabelResolver(TimeSubjectRegistry::none()))->isEnabled());
    }

    public function testForPageDescribesOncePerTypeAndOnlyTheContextScope(): void
    {
        $context = new TimeSubjectContext($this->viewer, 'org-a');
        $entries = [
            $this->entry('e1', 'org-a', new TimeSubjectRef('project', 'p-1')),
            $this->entry('e2', 'org-a', new TimeSubjectRef('project', 'p-1')),
            $this->entry('e3', 'org-a', new TimeSubjectRef('project', 'p-2')),
            $this->entry('e4', 'org-a', new TimeSubjectRef('client', 'c-1')),
            $this->entry('e5', 'org-b', new TimeSubjectRef('project', 'p-3')),
            $this->entry('e6', null, new TimeSubjectRef('project', 'p-4')),
            $this->entry('e7', 'org-a', null),
        ];

        $labels = $this->resolver->forPage($context, $entries);

        self::assertSame(1, $this->projects->describeCount());
        self::assertSame(1, $this->clients->describeCount());
        self::assertSame(['p-1', 'p-2'], array_map(static fn (TimeSubjectRef $ref): string => $ref->id, $this->projects->describeCalls[0]));
        self::assertSame($context, $this->projects->describeContexts[0]);
        self::assertTrue($labels->readable(new TimeSubjectRef('project', 'p-2')));
        self::assertTrue($labels->readable(new TimeSubjectRef('client', 'c-1')));
    }

    public function testForPageWithoutScopeLabelsUnscopedEntries(): void
    {
        $labels = $this->resolver->forPage(new TimeSubjectContext($this->viewer, ''), [$this->entry('e1', null, new TimeSubjectRef('project', 'p-1')), $this->entry('e2', 'org-a', new TimeSubjectRef('project', 'p-2'))]);

        self::assertTrue($labels->readable(new TimeSubjectRef('project', 'p-1')));
        self::assertFalse($labels->readable(new TimeSubjectRef('project', 'p-2')));
    }

    public function testCanReadMeansTheProviderDescribedIt(): void
    {
        $context = new TimeSubjectContext($this->viewer, 'org-a');

        self::assertTrue($this->resolver->canRead($context, new TimeSubjectRef('project', 'p-1')));
        self::assertFalse($this->resolver->canRead($context, new TimeSubjectRef('project', 'p-9')));
        self::assertFalse($this->resolver->canRead($context, new TimeSubjectRef('unknown', 'p-1')));
    }

    public function testForViewerDescribesPerScope(): void
    {
        $labels = $this->resolver->forViewer($this->viewer, [
            ['org-a', new TimeSubjectRef('project', 'p-1')],
            ['org-b', new TimeSubjectRef('project', 'p-2')],
            [null, new TimeSubjectRef('project', 'p-1')],
            ['org-a', null],
        ]);

        self::assertSame(3, $this->projects->describeCount());
        self::assertSame(['org-a', 'org-b', ''], array_map(static fn (TimeSubjectContext $context): string => $context->scopeKey, $this->projects->describeContexts));
        self::assertSame('Label p-1', $labels->get('org-a', new TimeSubjectRef('project', 'p-1'))?->label);
        self::assertNull($labels->get('org-a', new TimeSubjectRef('project', 'p-2')), 'labels never cross scopes');
        self::assertSame('Label p-2', $labels->get('org-b', new TimeSubjectRef('project', 'p-2'))?->label);
        self::assertNotNull($labels->get(null, new TimeSubjectRef('project', 'p-1')));
        self::assertCount(0, $this->clients->describeCalls);
    }

    /**
     * An entry view in a scope.
     */
    private function entry(string $id, ?string $scopeKey, ?TimeSubjectRef $subject): TimeEntryView
    {
        $at = new DateTimeImmutable('2026-11-02 09:00:00');

        return new TimeEntryView($id, '7', 't-1', 'Task', null, $scopeKey, $at, $at->modify('+1 hour'), 3600, TimeEntrySource::Web, null, null, subject: $subject);
    }
}

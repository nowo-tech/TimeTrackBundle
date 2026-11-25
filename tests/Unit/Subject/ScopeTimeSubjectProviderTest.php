<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Unit\Subject;

use Nowo\TimeTrackBundle\Port\Internal\TimesheetScopeProviderInterface;
use Nowo\TimeTrackBundle\Port\TimeTrackUserRefs;
use Nowo\TimeTrackBundle\Subject\ScopeTimeSubjectProvider;
use Nowo\TimeTrackBundle\Subject\TimeSubjectContext;
use Nowo\TimeTrackBundle\Subject\TimeSubjectLabel;
use Nowo\TimeTrackBundle\Subject\TimeSubjectRef;
use Nowo\TimeTrackBundle\Subject\TimeSubjectSearch;
use Nowo\TimeTrackBundle\Tests\Stub\TestUser;
use Nowo\TimeTrackBundle\Tests\Support\RecordingLogger;
use Nowo\TimeTrackBundle\Timesheet\TimesheetScope;
use Nowo\TimeTrackBundle\ValueObject\TimeTrackUserRef;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\User\UserInterface;

use function array_map;

/**
 * Since 2.1.0 (Beacon spec 256): the built-in provider exposes the actor's own tenant scopes as subjects.
 */
final class ScopeTimeSubjectProviderTest extends TestCase
{
    private RecordingLogger $logger;

    private ScopeTimeSubjectProvider $provider;

    private TimeSubjectContext $context;

    protected function setUp(): void
    {
        $this->logger   = new RecordingLogger();
        $this->provider = new ScopeTimeSubjectProvider(new class implements TimesheetScopeProviderInterface {
            /**
             * Acme, Beta (no label), an empty key and a key with an edge blank.
             */
            public function scopesFor(UserInterface $user): array
            {
                return [new TimesheetScope('org-a', 'Acme'), new TimesheetScope('org-b', ''), new TimesheetScope('', 'No key'), new TimesheetScope(' org-c', 'Bad')];
            }

            /**
             * Not used.
             */
            public function scopeKeyForTask(UserInterface $user, string $taskId, ?string $boardId): ?string
            {
                return null;
            }

            /**
             * Not used.
             */
            public function scopeKeysForEntries(array $entries): array
            {
                return [];
            }
        }, 'organisation', $this->logger);
        $this->context = new TimeSubjectContext(TimeTrackUserRefs::fromUser(new TestUser('7', 'ana')), 'org-a');
    }

    public function testStaticDescription(): void
    {
        self::assertSame(ScopeTimeSubjectProvider::DEFAULT_TYPE, ScopeTimeSubjectProvider::subjectType());
        self::assertFalse(ScopeTimeSubjectProvider::allowsTask());
    }

    public function testOnlyTheCurrentScopeOfTheRightTypeIsLoggable(): void
    {
        self::assertTrue($this->provider->canLog($this->context, new TimeSubjectRef('organisation', 'org-a')));
        self::assertFalse($this->provider->canLog($this->context, new TimeSubjectRef('organisation', 'org-b')), 'a member scope that is not the entry scope');
        self::assertFalse($this->provider->canLog($this->context, new TimeSubjectRef('client', 'org-a')));
        self::assertFalse($this->provider->canLog(new TimeSubjectContext($this->context->actor, 'org-x'), new TimeSubjectRef('organisation', 'org-x')));
    }

    public function testDescribeLabelsMemberScopesOnly(): void
    {
        $labels = $this->provider->describe($this->context, [
            new TimeSubjectRef('organisation', 'org-a'),
            new TimeSubjectRef('organisation', 'org-b'),
            new TimeSubjectRef('organisation', 'org-x'),
            new TimeSubjectRef('client', 'org-a'),
        ]);

        self::assertSame(['Acme', 'org-b'], array_map(static fn (TimeSubjectLabel $label): string => $label->label, $labels->all()));
        self::assertSame(['TimeTrack left out {count} scope(s) the scope provider returned: their key has an edge space or an invisible character, or is invalid UTF-8 (REQ-SEC-006).'], $this->logger->messages());
    }

    public function testSearchMatchesKeyOrLabelAndCuts(): void
    {
        $ids = static fn (array $labels): array => array_map(static fn (TimeSubjectLabel $label): string => $label->subject->id, $labels);

        self::assertSame(['org-a', 'org-b'], $ids($this->provider->search($this->context, new TimeSubjectSearch('organisation'))));
        self::assertSame(['org-a'], $ids($this->provider->search($this->context, new TimeSubjectSearch('organisation', 'acm'))));
        self::assertSame(['org-b'], $ids($this->provider->search($this->context, new TimeSubjectSearch('organisation', 'ORG-B'))));
        self::assertSame(['org-a'], $ids($this->provider->search($this->context, new TimeSubjectSearch('organisation', 'org', 1))));
        self::assertSame([], $this->provider->search($this->context, new TimeSubjectSearch('organisation', 'zzz')));
    }

    public function testTheSubjectTypeIsConfigurable(): void
    {
        $provider = new ScopeTimeSubjectProvider(new class implements TimesheetScopeProviderInterface {
            /**
             * One scope.
             */
            public function scopesFor(UserInterface $user): array
            {
                return [new TimesheetScope('org-a', 'Acme')];
            }

            /**
             * Not used.
             */
            public function scopeKeyForTask(UserInterface $user, string $taskId, ?string $boardId): ?string
            {
                return null;
            }

            /**
             * Not used.
             */
            public function scopeKeysForEntries(array $entries): array
            {
                return [];
            }
        }, 'tenant');

        self::assertTrue($provider->canLog($this->context, new TimeSubjectRef('tenant', 'org-a')));
        self::assertFalse($provider->canLog($this->context, new TimeSubjectRef('organisation', 'org-a')));
        self::assertSame('tenant', $provider->search($this->context, new TimeSubjectSearch('tenant'))[0]->subject->type);
    }

    public function testWithoutScopesOrSecurityUserThereIsNothing(): void
    {
        $noScopes = new ScopeTimeSubjectProvider(null);
        self::assertFalse($noScopes->canLog($this->context, new TimeSubjectRef('organisation', 'org-a')));
        self::assertSame([], $noScopes->search($this->context, new TimeSubjectSearch('organisation')));

        $idOnly = new TimeSubjectContext(TimeTrackUserRef::ofId('App\\User', '7'), 'org-a');
        self::assertSame([], $this->provider->describe($idOnly, [new TimeSubjectRef('organisation', 'org-a')])->all());
    }
}

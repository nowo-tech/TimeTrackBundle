<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Unit\Calendar;

use Nowo\TimeTrackBundle\Calendar\CalendarSettings;
use Nowo\TimeTrackBundle\Calendar\CalendarSuggestionService;
use Nowo\TimeTrackBundle\Calendar\TaskKeyResolverInterface;
use Nowo\TimeTrackBundle\Dto\TaskReference;
use Nowo\TimeTrackBundle\Repository\CalendarSuggestionRepositoryInterface;
use Nowo\TimeTrackBundle\Service\ManualEntryService;
use Nowo\TimeTrackBundle\Tests\Stub\RecordingTaskAccessChecker;
use Nowo\TimeTrackBundle\Tests\Stub\TestUser;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Symfony\Component\Security\Core\User\UserInterface;

use function str_repeat;

/**
 * Since 1.14 (contract §6.6): `resolve-keys` never answers more than was asked, and nothing without a resolver.
 */
final class CalendarSuggestionServiceTest extends TestCase
{
    public function testResolveKeysKeepsToTheRequestedKeys(): void
    {
        $resolver = new class implements TaskKeyResolverInterface {
            /** @var list<list<string>> */
            public array $asked = [];

            public function extractKeys(string $title): array
            {
                return [];
            }

            public function resolve(UserInterface $user, string $scopeKey, array $keys): array
            {
                $this->asked[] = $keys;

                /** @var array<string, TaskReference> $answer a misbehaving host: extra keys and values */
                $answer = ['#a' => new TaskReference('task-a', 'A'), '#extra' => new TaskReference('task-x', 'X'), '#b' => 'not a task'];

                return $answer;
            }
        };
        $entries = (new ReflectionClass(ManualEntryService::class))->newInstanceWithoutConstructor();
        $repo    = $this->createMock(CalendarSuggestionRepositoryInterface::class);
        $user    = new TestUser('1', 'u@example.com');
        $service = new CalendarSuggestionService($repo, $entries, new RecordingTaskAccessChecker(), new CalendarSettings(true), $resolver);

        self::assertSame(['#a' => ['taskId' => 'task-a', 'title' => 'A', 'boardId' => null, 'trackable' => true]], $service->resolveKeys($user, 'org-a', ['#a', '#b', '', str_repeat('k', 33)]));
        self::assertSame([['#a', '#b']], $resolver->asked);
        self::assertSame([], $service->resolveKeys($user, 'org-a', ['']), 'nothing to ask');
        self::assertSame([], (new CalendarSuggestionService($repo, $entries, new RecordingTaskAccessChecker(), new CalendarSettings(true)))->resolveKeys($user, 'org-a', ['#a']), 'no resolver');
    }
}

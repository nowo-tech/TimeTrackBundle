<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Unit\Controller;

use DateTimeImmutable;
use Nowo\TimeTrackBundle\Client\ClientAuthenticatorInterface;
use Nowo\TimeTrackBundle\Client\ClientAuthResult;
use Nowo\TimeTrackBundle\Client\ClientLoginRateLimiter;
use Nowo\TimeTrackBundle\Client\ClientResponseFactory;
use Nowo\TimeTrackBundle\Controller\TimeTrackClientApiController;
use Nowo\TimeTrackBundle\Dto\TaskListQuery;
use Nowo\TimeTrackBundle\Enum\ClientType;
use Nowo\TimeTrackBundle\Integration\TaskProviderInterface;
use Nowo\TimeTrackBundle\Repository\TimeEntryRepositoryInterface;
use Nowo\TimeTrackBundle\Service\ClientAuthService;
use Nowo\TimeTrackBundle\Service\TeamAccessGuard;
use Nowo\TimeTrackBundle\Service\TimerService;
use Nowo\TimeTrackBundle\Support\QueryPeriodResolver;
use Nowo\TimeTrackBundle\Tests\Stub\StubTeamContextProvider;
use Nowo\TimeTrackBundle\Tests\Stub\TestUser;
use Nowo\TimeTrackBundle\Tests\Support\InMemoryActiveTimerRepository;
use Nowo\TimeTrackBundle\Tests\Support\InMemoryClientTokenRepository;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class TimeTrackClientApiControllerTest extends TestCase
{
    private ?TaskListQuery $capturedQuery = null;

    /** @var list<array{DateTimeImmutable, DateTimeImmutable}> */
    private array $capturedPeriods = [];

    public function testTasksLimitIsClamped(): void
    {
        [$controller, $token] = $this->createController();

        $response = $controller->tasks($this->bearerRequest('/tasks?limit=1000000', $token));

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertNotNull($this->capturedQuery);
        self::assertSame(TaskListQuery::MAX_LIMIT, $this->capturedQuery->limit);
    }

    public function testEntriesWithUnboundedRangeIsCapped(): void
    {
        [$controller, $token] = $this->createController();

        $response = $controller->entries($this->bearerRequest('/entries?from=1970-01-01T00:00:00%2B00:00&to=2026-01-01T00:00:00%2B00:00', $token));

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertCount(1, $this->capturedPeriods);
        [$from, $to] = $this->capturedPeriods[0];
        self::assertSame(QueryPeriodResolver::MAX_SPAN_DAYS, (int) $from->diff($to)->days);
    }

    public function testEntriesWithGarbageDatesFallsBackToDefaults(): void
    {
        [$controller, $token] = $this->createController();

        $response = $controller->entries($this->bearerRequest('/entries?from=garbage&to=%00nope', $token));

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        [$from, $to] = $this->capturedPeriods[0];
        self::assertEqualsWithDelta(7 * 86400, $to->getTimestamp() - $from->getTimestamp(), 5);
    }

    /**
     * @return array{TimeTrackClientApiController, string}
     */
    private function createController(): array
    {
        $user = new TestUser('user-1', 'demo@example.com');

        $authenticator = $this->createStub(ClientAuthenticatorInterface::class);
        $authenticator->method('authenticate')->willReturn(ClientAuthResult::success($user));
        $authService = new ClientAuthService($authenticator, new InMemoryClientTokenRepository(), 3600);
        $login       = $authService->login('demo@example.com', 'secret', ClientType::Extension);
        self::assertNotNull($login);

        $taskProvider = $this->createStub(TaskProviderInterface::class);
        $taskProvider->method('listTrackableForUser')->willReturnCallback(function ($u, TaskListQuery $query): array {
            $this->capturedQuery = $query;

            return [];
        });

        $entryRepository = $this->createStub(TimeEntryRepositoryInterface::class);
        $entryRepository->method('findByUserAndPeriod')->willReturnCallback(function (string $userId, DateTimeImmutable $from, DateTimeImmutable $to): array {
            $this->capturedPeriods[] = [$from, $to];

            return [];
        });

        $controller = new TimeTrackClientApiController(
            $authService,
            new TimerService($taskProvider, new InMemoryActiveTimerRepository(), $entryRepository, new EventDispatcher()),
            new TeamAccessGuard(new StubTeamContextProvider(), new EventDispatcher(), ['ROLE_ADMIN'], true, true),
            new ClientResponseFactory([]),
            new ClientLoginRateLimiter(new ArrayAdapter(), 5, 60, true),
            300,
        );

        return [$controller, $login['token']];
    }

    private function bearerRequest(string $uri, string $token): Request
    {
        $request = Request::create($uri);
        $request->headers->set('Authorization', 'Bearer ' . $token);

        return $request;
    }
}

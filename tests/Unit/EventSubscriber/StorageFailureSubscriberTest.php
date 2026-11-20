<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Unit\EventSubscriber;

use Doctrine\DBAL\Driver\AbstractException;
use Doctrine\DBAL\Exception\DeadlockException;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use LogicException;
use Nowo\TimeTrackBundle\Client\ClientResponseFactory;
use Nowo\TimeTrackBundle\EventSubscriber\StorageFailureSubscriber;
use Nowo\TransactionContract\TransientStorageFailure;
use Nowo\TransactionDoctrine\Symfony\Conflict\ConflictResponsePolicy;
use Nowo\TransactionDoctrine\TransientFailureKind;
use Nowo\TransactionDoctrine\WriteUnit\CommitLedger;
use Nowo\TransactionDoctrine\WriteUnit\CommitMark;
use Nowo\TransactionDoctrine\WriteUnit\ConcurrencyConflict;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Throwable;

use function json_decode;

/**
 * 1.27.0 (Beacon spec 239): 409 + Retry-After (the shared policy's status; 503 opt-in) for transient storage failures, on the
 * bundle's routes only, with no storage detail in the answer. 1.31.0 (REQ-RUNTIME-003): CONFLICT_001 / CONFLICT_002 on
 * every answer; a unique violation is left alone (it is not a lock conflict).
 */
final class StorageFailureSubscriberTest extends TestCase
{
    private const API  = 'Nowo\\TimeTrackBundle\\Controller\\TimeTrackClientApiController::timerStart';
    private const PAGE = 'Nowo\\TimeTrackBundle\\Controller\\TimeTrackManageController::index';

    public function testItListensAfterTheApplicationsListeners(): void
    {
        self::assertSame([KernelEvents::EXCEPTION => ['onKernelException', -64]], StorageFailureSubscriber::getSubscribedEvents());
    }

    public function testATransientFailureOfTheApiIs409WithTheRunnersHint(): void
    {
        $event = $this->dispatch(new TransientStorageFailure(7, TransientStorageFailure::DEFAULT_MESSAGE, $this->deadlock()), ['_controller' => self::API]);

        $response = $event->getResponse();
        self::assertNotNull($response);
        self::assertSame(409, $response->getStatusCode());
        self::assertSame('7', $response->headers->get('Retry-After'));
        self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        self::assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        self::assertSame('chrome-extension://functional', $response->headers->get('Access-Control-Allow-Origin'));
        $body = json_decode((string) $response->getContent(), true);
        self::assertIsArray($body);
        self::assertSame('unavailable', $body['error']);
        self::assertSame('CONFLICT_001', $body['code']);
        self::assertStringNotContainsString('SELECT', (string) $response->getContent());
    }

    public function testARetryableDbalFailureWrappedByTheApplicationIs409(): void
    {
        $event = $this->dispatch(new RuntimeException('wrapped', 0, $this->deadlock()), ['_route' => 'nowo_time_track_api_timer_start'], json: true, responses: false);

        $response = $event->getResponse();
        self::assertNotNull($response);
        self::assertSame(409, $response->getStatusCode());
        self::assertSame('1', $response->headers->get('Retry-After'));

        $event = $this->dispatch(new RuntimeException('wrapped', 0, $this->deadlock()), ['_route' => 'nowo_time_track_index']);
        $page  = $event->getThrowable();
        self::assertInstanceOf(HttpException::class, $page);
        self::assertSame('1', (string) ($page->getHeaders()['Retry-After'] ?? ''));
    }

    public function testATransientFailureWithoutAHintWaitsOneSecond(): void
    {
        $event = $this->dispatch(new TransientStorageFailure(), ['_controller' => self::API]);

        self::assertSame('1', $event->getResponse()?->headers->get('Retry-After'));
    }

    public function testAUniqueViolationIsNotAConflictAndIsLeftToTheApplication(): void
    {
        $unique = $this->unique();
        $event  = $this->dispatch($unique, ['_controller' => self::API]);
        self::assertNull($event->getResponse());
        self::assertSame($unique, $event->getThrowable());

        $wrapped = new LogicException('domain', 0, $unique);
        $event   = $this->dispatch($wrapped, ['_controller' => self::PAGE]);
        self::assertNull($event->getResponse());
        self::assertSame($wrapped, $event->getThrowable());

        self::assertNull($this->dispatch($unique, ['_route' => 'nowo_time_track_index'], json: true)->getResponse());
    }

    public function testThePagesGetHttpExceptionsWithTheStorageErrorAsPrevious(): void
    {
        $failure = new TransientStorageFailure(3);
        $event   = $this->dispatch($failure, ['_controller' => self::PAGE]);
        self::assertNull($event->getResponse());
        $exception = $event->getThrowable();
        self::assertInstanceOf(HttpException::class, $exception);
        self::assertSame('3', (string) ($exception->getHeaders()['Retry-After'] ?? ''));
        self::assertSame($failure, $exception->getPrevious());
        self::assertSame('CONFLICT_001', $exception->getHeaders()['X-Conflict-Code'] ?? null);
    }

    public function testAPageAskedForJsonGetsJson(): void
    {
        $event = $this->dispatch(new TransientStorageFailure(2), ['_controller' => self::PAGE], json: true);
        self::assertSame(409, $event->getResponse()?->getStatusCode());
    }

    public function testTheApplicationsRoutesAndOtherFailuresAreLeftAlone(): void
    {
        $failure = new TransientStorageFailure(2);
        $event   = $this->dispatch($failure, ['_controller' => 'App\\Controller\\HomeController::index', '_route' => 'app_home']);
        self::assertNull($event->getResponse());
        self::assertSame($failure, $event->getThrowable());

        $other = new RuntimeException('not storage');
        $event = $this->dispatch($other, ['_controller' => self::API]);
        self::assertNull($event->getResponse());
        self::assertSame($other, $event->getThrowable());

        $event = $this->dispatch($failure, []);
        self::assertNull($event->getResponse());
    }

    public function testThe503OptInOfThePolicyKeepsTheOldStatusForJsonAndHtml(): void
    {
        $policy = new ConflictResponsePolicy(ConflictResponsePolicy::STATUS_SERVICE_UNAVAILABLE, 4);

        $event    = $this->dispatch(new TransientStorageFailure(), ['_controller' => self::API], policy: $policy);
        $response = $event->getResponse();
        self::assertSame(503, $response?->getStatusCode());
        self::assertSame('4', $response->headers->get('Retry-After'));

        $event = $this->dispatch(new TransientStorageFailure(9), ['_controller' => self::PAGE], policy: $policy);
        $page  = $event->getThrowable();
        self::assertInstanceOf(HttpException::class, $page);
        self::assertSame(503, $page->getStatusCode());
        self::assertSame('9', (string) ($page->getHeaders()['Retry-After'] ?? ''));
    }

    public function testACommittedConflictHasNoRetryAfterOnJsonAndHtml(): void
    {
        $conflict = new ConcurrencyConflict(3, TransientFailureKind::Deadlock, null, $this->deadlock(), true);

        $event    = $this->dispatch(new RuntimeException('wrapped', 0, $conflict), ['_controller' => self::API]);
        $response = $event->getResponse();
        self::assertSame(409, $response?->getStatusCode());
        self::assertFalse($response->headers->has('Retry-After'));
        $body = json_decode((string) $response->getContent(), true);
        self::assertIsArray($body);
        self::assertSame('unavailable', $body['error']);
        self::assertSame('CONFLICT_002', $body['code']);
        self::assertStringContainsString('Reload', $body['message']);
        self::assertSame('chrome-extension://functional', $response->headers->get('Access-Control-Allow-Origin'));

        $page = $this->dispatch($conflict, ['_controller' => self::PAGE])->getThrowable();
        self::assertInstanceOf(HttpException::class, $page);
        self::assertSame(409, $page->getStatusCode());
        self::assertArrayNotHasKey('Retry-After', $page->getHeaders());
        self::assertSame('CONFLICT_002', $page->getHeaders()['X-Conflict-Code'] ?? null);
        self::assertSame($conflict, $page->getPrevious());

        // Not committed: the retry is invited.
        $open = new ConcurrencyConflict(3, TransientFailureKind::Deadlock, 5, $this->deadlock(), false);
        self::assertSame('5', $this->dispatch($open, ['_controller' => self::API])->getResponse()?->headers->get('Retry-After'));
    }

    public function testALostSavepointWithNoDeadlockBehindItIsCommittedUnknownAndGetsCode002(): void
    {
        // Rule 6: the outcome of the commit is unknown (MySQL 1305 naming a SAVEPOINT, nothing transient behind it):
        // CONFLICT_002 and no Retry-After.
        $driver = new class('SAVEPOINT DOCTRINE_2 does not exist', '42000', 1305) extends AbstractException {};
        $lost   = new TransientStorageFailure(2, TransientStorageFailure::DEFAULT_MESSAGE, new DriverException($driver, null));
        self::assertTrue(StorageFailureSubscriber::committed($lost));

        $response = $this->dispatch($lost, ['_controller' => self::API])->getResponse();
        self::assertSame(409, $response?->getStatusCode());
        self::assertFalse($response->headers->has('Retry-After'));
        $body = json_decode((string) $response->getContent(), true);
        self::assertIsArray($body);
        self::assertSame('CONFLICT_002', $body['code']);
    }

    public function testACommitEarlierInTheRequestGivesCode002WithoutRetryAfterOnJsonAndHtml(): void
    {
        $ledger = new CommitLedger();
        $mark   = new CommitMark($ledger);
        $ledger->record(); // the host committed after the request's mark, before the conflict

        $event    = $this->dispatch(new TransientStorageFailure(5, TransientStorageFailure::DEFAULT_MESSAGE, $this->deadlock()), ['_controller' => self::API, CommitMark::REQUEST_ATTRIBUTE => $mark]);
        $response = $event->getResponse();
        self::assertNotNull($response);
        self::assertSame(409, $response->getStatusCode());
        self::assertFalse($response->headers->has('Retry-After'));
        $body = json_decode((string) $response->getContent(), true);
        self::assertIsArray($body);
        self::assertSame('CONFLICT_002', $body['code']);
        self::assertStringContainsString('Reload', $body['message']);

        $event = $this->dispatch(new TransientStorageFailure(5, TransientStorageFailure::DEFAULT_MESSAGE, $this->deadlock()), ['_controller' => self::PAGE, CommitMark::REQUEST_ATTRIBUTE => $mark]);
        $page  = $event->getThrowable();
        self::assertInstanceOf(HttpException::class, $page);
        self::assertSame('CONFLICT_002', $page->getHeaders()['X-Conflict-Code'] ?? null);
        self::assertArrayNotHasKey('Retry-After', $page->getHeaders());
    }

    public function testARequestMarkWithNoCommitKeepsCode001WithRetryAfter(): void
    {
        $mark  = new CommitMark(new CommitLedger());
        $event = $this->dispatch(new TransientStorageFailure(5, TransientStorageFailure::DEFAULT_MESSAGE, $this->deadlock()), ['_controller' => self::API, CommitMark::REQUEST_ATTRIBUTE => $mark]);

        $response = $event->getResponse();
        self::assertNotNull($response);
        self::assertSame('5', $response->headers->get('Retry-After'));
        $body = json_decode((string) $response->getContent(), true);
        self::assertIsArray($body);
        self::assertSame('CONFLICT_001', $body['code']);
    }

    public function testThePolicysRetryAfterIsTheFallbackHint(): void
    {
        $event = $this->dispatch($this->deadlock(), ['_route' => 'nowo_time_track_api_timer_start'], json: true, policy: new ConflictResponsePolicy(409, 6));
        self::assertSame('6', $event->getResponse()?->headers->get('Retry-After'));
    }

    /**
     * @param array<string, mixed> $attributes
     */
    private function dispatch(Throwable $failure, array $attributes, bool $json = false, bool $responses = true, ?ConflictResponsePolicy $policy = null): ExceptionEvent
    {
        $request = Request::create('/x', 'POST', [], [], [], ['HTTP_ORIGIN' => 'chrome-extension://functional'] + ($json ? ['HTTP_ACCEPT' => 'application/json'] : []));
        $request->attributes->add($attributes);
        $event = new ExceptionEvent($this->createMock(HttpKernelInterface::class), $request, HttpKernelInterface::MAIN_REQUEST, $failure);
        (new StorageFailureSubscriber($responses ? new ClientResponseFactory(['chrome-extension://functional']) : null, $policy))->onKernelException($event);

        return $event;
    }

    private function deadlock(): DeadlockException
    {
        return new DeadlockException(new class('Deadlock found; SELECT secret') extends AbstractException {}, null);
    }

    private function unique(): UniqueConstraintViolationException
    {
        return new UniqueConstraintViolationException(new class('Duplicate entry') extends AbstractException {}, null);
    }
}

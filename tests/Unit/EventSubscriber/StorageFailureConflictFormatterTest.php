<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Unit\EventSubscriber;

use Doctrine\DBAL\Driver\AbstractException;
use Doctrine\DBAL\Exception\DeadlockException;
use Nowo\TimeTrackBundle\Client\ClientResponseFactory;
use Nowo\TimeTrackBundle\EventSubscriber\StorageFailureConflictFormatter;
use Nowo\TransactionDoctrine\Symfony\Conflict\ConflictResponseContext;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

use function json_decode;

/**
 * 1.30.0: the formatter answers the bundle's own routes in the client API's error shape and leaves the rest alone.
 */
final class StorageFailureConflictFormatterTest extends TestCase
{
    public function testItAnswersTheClientApiShapeWithCors(): void
    {
        $formatter = new StorageFailureConflictFormatter(new ClientResponseFactory(['chrome-extension://functional']));

        $request = Request::create('/x', 'POST', [], [], [], ['HTTP_ORIGIN' => 'chrome-extension://functional']);
        $request->attributes->set('_controller', 'Nowo\\TimeTrackBundle\\Controller\\TimeTrackClientApiController::timerStart');

        $response = $formatter->format($request, $this->context(false));
        self::assertNotNull($response);
        self::assertSame(409, $response->getStatusCode());
        self::assertSame('chrome-extension://functional', $response->headers->get('Access-Control-Allow-Origin'));
        $body = json_decode((string) $response->getContent(), true);
        self::assertSame(['error' => 'unavailable', 'message' => 'Retry later.'], $body);
    }

    public function testItFallsBackToAPlainBodyAndKeepsThePolicyStatus(): void
    {
        $request = Request::create('/x', 'GET', [], [], [], ['HTTP_ACCEPT' => 'application/json']);
        $request->attributes->set('_route', 'nowo_time_track_api_timer_start');

        $response = (new StorageFailureConflictFormatter())->format($request, $this->context(true, 503));
        self::assertSame(503, $response?->getStatusCode());
        self::assertSame(['error' => 'unavailable', 'message' => 'Retry later.'], json_decode((string) $response->getContent(), true));
    }

    public function testOtherRoutesAndTheHtmlPagesFallThrough(): void
    {
        $formatter = new StorageFailureConflictFormatter();

        $own = Request::create('/x');
        $own->attributes->set('_controller', 'App\\Controller\\HomeController::index');
        self::assertNull($formatter->format($own, $this->context(false)));

        $page = Request::create('/x');
        $page->attributes->set('_controller', 'Nowo\\TimeTrackBundle\\Controller\\TimeTrackManageController::index');
        self::assertNull($formatter->format($page, $this->context(false)));
    }

    private function context(bool $committed, int $status = 409): ConflictResponseContext
    {
        $failure = new DeadlockException(new class('Deadlock') extends AbstractException {}, null);

        return new ConflictResponseContext($status, $committed ? null : 1, 'concurrency_conflict', 'Retry later.', 3, $failure, $committed);
    }
}

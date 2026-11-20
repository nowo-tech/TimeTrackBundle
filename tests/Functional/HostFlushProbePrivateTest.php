<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Functional;

use Nowo\TimeTrackBundle\Doctrine\TimeTrackHostFlushProbe;
use Nowo\TimeTrackBundle\Tests\Fixtures\FunctionalEntity\AppUser;
use Nowo\TimeTrackBundle\Tests\Support\FunctionalKernel;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\HttpKernel\TerminableInterface;

use function array_filter;
use function array_values;
use function assert;
use function restore_error_handler;
use function set_error_handler;
use function str_contains;

use const E_USER_DEPRECATED;

/**
 * 1.33.0 (REQ-ARCH-001 rule 6): with `database.private_entity_manager: true` no probe is registered, no TimeTrack write
 * flushes the application's manager and nothing is reported; the application's pending change stays pending.
 */
final class HostFlushProbePrivateTest extends FunctionalTestCase
{
    /**
     * @param array<string, mixed> $options
     */
    protected static function createKernel(array $options = []): KernelInterface
    {
        return new FunctionalKernel(self::timeTrackConfig(), false, false, false);
    }

    /**
     * @return array<string, mixed>
     */
    protected static function timeTrackConfig(): array
    {
        return ['database' => ['private_entity_manager' => true]];
    }

    public function testNothingIsReportedAndThePendingChangeStaysPending(): void
    {
        self::assertFalse(self::getContainer()->has(TimeTrackHostFlushProbe::class));
        $host    = $this->entityManager();
        $pending = new AppUser('private-pending@example.com', 'secret');
        $seen    = [];
        // @phpstan-ignore frankenphp.worker.noSetErrorExceptionHandler (test only: restored in the finally block below)
        set_error_handler(static function (int $level, string $message) use (&$seen): bool {
            $seen[] = $message;

            return true;
        }, E_USER_DEPRECATED);
        try {
            $host->persist($pending);
            $kernel = self::getContainer()->get('http_kernel');
            assert($kernel instanceof HttpKernelInterface && $kernel instanceof TerminableInterface);
            $request  = Request::create('/host-timer/start', 'POST', [], [], [], ['HTTP_X_TEST_TOKEN' => 'bt_person@example.com', 'CONTENT_TYPE' => 'application/json'], '{"taskId":"task-1"}');
            $response = $kernel->handle($request);
            $kernel->terminate($request, $response);
            self::assertSame(Response::HTTP_CREATED, $response->getStatusCode(), (string) $response->getContent());
        } finally {
            restore_error_handler();
        }

        self::assertSame([], array_values(array_filter($seen, static fn (string $message): bool => str_contains($message, 'NowoTimeTrackBundle') || str_contains($message, 'with its own entity manager'))));
        self::assertTrue($host->getUnitOfWork()->isScheduledForInsert($pending), 'the TimeTrack write did not flush the application\'s manager');
        self::assertSame(0, (int) $host->getConnection()->fetchOne("SELECT COUNT(*) FROM app_user WHERE email = 'private-pending@example.com'"));
    }
}

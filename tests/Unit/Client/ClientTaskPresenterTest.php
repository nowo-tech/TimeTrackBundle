<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Unit\Client;

use Nowo\TimeTrackBundle\Client\ClientTaskPresenter;
use Nowo\TimeTrackBundle\Dto\TaskReference;
use Nowo\TimeTrackBundle\Integration\TaskProviderInterface;
use Nowo\TimeTrackBundle\Service\TimerService;
use Nowo\TimeTrackBundle\Tests\Support\InMemoryActiveTimerRepository;
use Nowo\TimeTrackBundle\Tests\Support\InMemoryTimeEntryRepository;
use Nowo\TimeTrackBundle\Tests\Support\RecordingLogger;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpFoundation\Request;

use function str_repeat;

/**
 * Output rules of the 1.10 task details (contract v0.3 §1.3).
 */
final class ClientTaskPresenterTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string|null}>
     */
    public static function urls(): iterable
    {
        yield 'same origin' => ['https://beacon.test/tasks/1', 'https://beacon.test/tasks/1'];
        yield 'explicit default port' => ['https://beacon.test:443/tasks/1', 'https://beacon.test:443/tasks/1'];
        yield 'upper-case host' => ['https://BEACON.test/tasks/1?tab=time#x', 'https://BEACON.test/tasks/1?tab=time#x'];
        yield 'http' => ['http://beacon.test/tasks/1', null];
        yield 'other host' => ['https://evil.test/tasks/1', null];
        yield 'sub domain' => ['https://a.beacon.test/tasks/1', null];
        yield 'other port' => ['https://beacon.test:8443/tasks/1', null];
        yield 'userinfo' => ['https://user:pw@beacon.test/tasks/1', null];
        yield 'user only' => ['https://user@beacon.test/tasks/1', null];
        yield 'javascript' => ['javascript:alert(1)', null];
        yield 'data' => ['data:text/html,x', null];
        yield 'relative' => ['/tasks/1', null];
        yield 'protocol relative' => ['//beacon.test/tasks/1', null];
        yield 'whitespace' => ['https://beacon.test/tasks/ 1', null];
        yield 'control char' => ["https://beacon.test/tasks/1\n", null];
        yield 'too long' => ['https://beacon.test/' . str_repeat('a', 2100), null];
        yield 'not a url' => ['https://', null];
        yield 'no-break space in the path' => ["https://beacon.test/tasks/\u{00A0}1", null];
        yield 'line separator in the path' => ["https://beacon.test/tasks/\u{2028}1", null];
        yield 'C1 control in the path' => ["https://beacon.test/tasks/\u{0085}1", null];
        yield 'invalid UTF-8' => ["https://beacon.test/tasks/\xC3\x281", null];
    }

    #[DataProvider('urls')]
    public function testUrlRules(string $url, ?string $expected): void
    {
        $logger = new RecordingLogger();
        $result = $this->presenter(null, $logger)->safeUrl($url, Request::create('https://beacon.test/api/time-track/tasks'));

        self::assertSame($expected, $result);
        if ($expected === null) {
            self::assertSame('task_url_dropped', $logger->records[0]['context']['action'] ?? null);
            self::assertNotContains($url, $logger->contextValues());
        }
    }

    public function testConfiguredOriginWinsOverTheRequestOrigin(): void
    {
        $presenter = $this->presenter('https://beacon.example.com');
        $request   = Request::create('http://internal:8080/api/time-track/tasks');

        self::assertSame('https://beacon.example.com/t/1', $presenter->safeUrl('https://beacon.example.com/t/1', $request));
        self::assertNull($presenter->safeUrl('http://internal:8080/t/1', $request));
        self::assertNull($this->presenter()->safeUrl('https://beacon.example.com/t/1', $request), 'an http request origin publishes nothing');
        self::assertNull($this->presenter()->safeUrl(null, $request));
        self::assertNull($this->presenter()->safeUrl('', $request));
    }

    public function testOrigin(): void
    {
        self::assertSame('https://host.test', ClientTaskPresenter::origin('HTTPS://Host.Test:443/x'));
        self::assertSame('https://host.test:8443', ClientTaskPresenter::origin('https://host.test:8443'));
        self::assertNull(ClientTaskPresenter::origin('http://host.test'));
        self::assertNull(ClientTaskPresenter::origin('https:///x'));
        self::assertNull(ClientTaskPresenter::origin('/x'));
    }

    public function testProjectRules(): void
    {
        $request = Request::create('https://beacon.test/api');
        $task    = $this->presenter()->task(new TaskReference('t', 'T', null, null, str_repeat('p', 65), '  ' . str_repeat('é', 300) . ' '), $request);
        self::assertNull($task['projectId']);
        self::assertSame(str_repeat('é', 255), $task['projectTitle']);

        $blank = $this->presenter()->task(new TaskReference('t', 'T', null, null, '  ', '  '), $request);
        self::assertNull($blank['projectId']);
        self::assertNull($blank['projectTitle']);

        $ok = $this->presenter()->task(new TaskReference('t', 'T', null, null, ' p-1 ', 'Web'), $request);
        self::assertSame('p-1', $ok['projectId']);
    }

    private function presenter(?string $origin = null, ?RecordingLogger $logger = null): ClientTaskPresenter
    {
        $timers = new TimerService($this->createStub(TaskProviderInterface::class), new InMemoryActiveTimerRepository(), new InMemoryTimeEntryRepository(), new EventDispatcher());

        return new ClientTaskPresenter($timers, $origin, $logger);
    }
}

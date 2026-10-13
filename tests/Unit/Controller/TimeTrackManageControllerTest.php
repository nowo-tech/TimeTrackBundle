<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Unit\Controller;

use DateTimeImmutable;
use Nowo\TimeTrackBundle\Bridge\StubTaskProvider;
use Nowo\TimeTrackBundle\Controller\TimeTrackManageController;
use Nowo\TimeTrackBundle\Security\TimeTrackAccessCheckerInterface;
use Nowo\TimeTrackBundle\Service\TimerService;
use Nowo\TimeTrackBundle\Support\QueryPeriodResolver;
use Nowo\TimeTrackBundle\Tests\Stub\TestUser;
use Nowo\TimeTrackBundle\Tests\Support\InMemoryActiveTimerRepository;
use Nowo\TimeTrackBundle\Tests\Support\InMemoryTimeEntryRepository;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

final class TimeTrackManageControllerTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $renderedContext = [];

    public function testReportsWithGarbageDatesFallsBackToDefaults(): void
    {
        $controller = $this->createController();

        $response = $controller->reports(Request::create('/reports?from=garbage&to=%00nope'));

        self::assertSame(200, $response->getStatusCode());
        $from = $this->renderedContext['from'];
        $to   = $this->renderedContext['to'];
        self::assertInstanceOf(DateTimeImmutable::class, $from);
        self::assertInstanceOf(DateTimeImmutable::class, $to);
        self::assertEqualsWithDelta(7 * 86400, $to->getTimestamp() - $from->getTimestamp(), 5);
    }

    public function testReportsSpanIsCapped(): void
    {
        $controller = $this->createController();

        $controller->reports(Request::create('/reports?from=1970-01-01&to=2026-01-01'));

        $from = $this->renderedContext['from'];
        self::assertInstanceOf(DateTimeImmutable::class, $from);
        self::assertSame(
            (new DateTimeImmutable('2026-01-01'))->modify('-' . QueryPeriodResolver::MAX_SPAN_DAYS . ' days')->format('Y-m-d'),
            $from->format('Y-m-d'),
        );
    }

    public function testFlashMessagesAreTranslated(): void
    {
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id, array $p = [], ?string $domain = null): string => $domain . ':' . $id);

        $session    = new Session(new MockArraySessionStorage());
        $controller = $this->createController($translator, $session);

        $request = Request::create('/', 'POST', ['_token' => 't', 'action' => 'stop']);
        $controller->index($request);

        self::assertSame(['NowoTimeTrackBundle:flash.no_active_timer'], $session->getFlashBag()->get('warning'));
    }

    private function createController(?TranslatorInterface $translator = null, ?Session $session = null): TimeTrackManageController
    {
        $tokenStorage = new TokenStorage();
        $tokenStorage->setToken(new UsernamePasswordToken(new TestUser('user-1', 'demo@example.com'), 'main', ['ROLE_USER']));

        $twig = $this->createStub(Environment::class);
        $twig->method('render')->willReturnCallback(function (string $name, array $context = []): string {
            $this->renderedContext = $context;

            return 'ok';
        });

        $csrf = $this->createStub(CsrfTokenManagerInterface::class);
        $csrf->method('isTokenValid')->willReturn(true);

        $router = $this->createStub(UrlGeneratorInterface::class);
        $router->method('generate')->willReturn('/');

        $requestStack = new RequestStack();
        $current      = new Request();
        $current->setSession($session ?? new Session(new MockArraySessionStorage()));
        $requestStack->push($current);

        $container = new Container();
        $container->set('security.token_storage', $tokenStorage);
        $container->set('twig', $twig);
        $container->set('security.csrf.token_manager', $csrf);
        $container->set('router', $router);
        $container->set('request_stack', $requestStack);

        $controller = new TimeTrackManageController(
            new TimerService(new StubTaskProvider(), new InMemoryActiveTimerRepository(), new InMemoryTimeEntryRepository(), new EventDispatcher()),
            $this->createStub(TimeTrackAccessCheckerInterface::class),
            ['index' => 'index.html.twig', 'reports' => 'reports.html.twig'],
            true,
            $translator,
        );
        $controller->setContainer($container);

        return $controller;
    }
}

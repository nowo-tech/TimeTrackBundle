<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Functional;

use Nowo\TimeTrackBundle\Enum\ClientType;
use Nowo\TimeTrackBundle\Service\TimerService;
use Nowo\TimeTrackBundle\Subject\ActivityType;
use Nowo\TimeTrackBundle\Subject\TimeAllocation;
use Nowo\TimeTrackBundle\Tests\Fixtures\FunctionalEntity\AppUser;
use Nowo\TimeTrackBundle\Tests\Support\FixedActivityTypeProvider;
use Nowo\TimeTrackBundle\Tests\Support\FunctionalKernel;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpKernel\KernelInterface;

/**
 * Since 2.1.0 (audit ZBQ-L4): with an effective `entries.require_activity_type` (the option on and an activity type
 * provider) the timer page's Stop form asks for the activity type — the active types of the timer's scope, labelled
 * "Label (key)" — so the web UI can stop the timer at all. A stop without one is refused (flash, the timer keeps
 * running); a key the form does not offer, or one retired meanwhile, is refused with a flash, never a 403. No choice
 * while the timer already has a type (and none without the option: {@see ManageUiTest}).
 */
final class ManageUiActivityTypeTest extends FunctionalTestCase
{
    /**
     * @param array<string, mixed> $options
     */
    protected static function createKernel(array $options = []): KernelInterface
    {
        return new FunctionalKernel(self::timeTrackConfig(), allocationProviders: true);
    }

    protected static function timeTrackConfig(): array
    {
        return ['entries' => ['require_activity_type' => true]];
    }

    /**
     * The first request a process handles drops a session cookie set by loginUser() before it (also seen when
     * {@see ManageUiTest} runs alone): one throw-away request first, so this class passes on its own too.
     */
    public static function setUpBeforeClass(): void
    {
        self::createClient()->request('GET', '/tools/time-track');
        self::ensureKernelShutdown();
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->activities()->reset();
        $this->client->loginUser($this->user, 'main');
    }

    protected function tearDown(): void
    {
        $this->activities()->reset();
        parent::tearDown();
    }

    public function testTheStopFormOffersTheActiveTypesAndStopsWithTheChosenOne(): void
    {
        $crawler = $this->startFromThePage();

        $select = $crawler->filter('select[name="activity_type"]');
        self::assertCount(1, $select);
        self::assertSame('required', $select->attr('required'));
        self::assertSame(['', 'dev', 'support', 'meeting'], $select->filter('option')->each(static fn (Crawler $option): string => (string) $option->attr('value')), 'the retired legacy is not offered');
        self::assertSame(['Choose an activity type', 'Development (dev)', 'Support (support)', 'Meeting (meeting)'], $select->filter('option')->each(static fn (Crawler $option): string => $option->text()));
        self::assertSame('Activity type', $crawler->filter('label[for="' . $select->attr('id') . '"]')->text());

        $form = $crawler->selectButton('Stop')->form();
        $form->setValues(['activity_type' => 'support']);
        $this->client->submit($form);
        self::assertResponseRedirects('/tools/time-track');
        $this->client->followRedirect();
        self::assertSelectorTextContains('.nowo-ui-flash--success', 'Timer stopped.');
        self::assertNull($this->timers()->getActive($this->managedUser()));
        self::assertSame(['support'], $this->entryActivityTypes());
    }

    public function testAStopWithoutAnActivityTypeIsRefusedAndTheTimerKeepsRunning(): void
    {
        $crawler = $this->startFromThePage();

        $this->client->submit($crawler->selectButton('Stop')->form());
        $this->client->followRedirect();

        self::assertSelectorTextContains('.nowo-ui-flash--warning', 'Choose an activity type before you stop the timer.');
        self::assertNotNull($this->timers()->getActive($this->managedUser()));
        self::assertSame([], $this->entryActivityTypes());
    }

    public function testAKeyTheFormDoesNotOfferIsRefusedWithAFlash(): void
    {
        $crawler = $this->startFromThePage();
        $token   = (string) $crawler->selectButton('Stop')->closest('form')?->filter('input[name="_token"]')->attr('value');

        foreach (['legacy', 'nope'] as $key) {
            $this->client->request('POST', '/tools/time-track', ['action' => 'stop', 'task_id' => '', '_token' => $token, 'activity_type' => $key]);
            self::assertResponseRedirects('/tools/time-track', null, $key);
            $this->client->followRedirect();
            self::assertSelectorTextContains('.nowo-ui-flash--error', 'That activity type cannot be used. Choose another one.');
        }
        self::assertNotNull($this->timers()->getActive($this->managedUser()), 'the timer keeps running');
        self::assertSame([], $this->entryActivityTypes());

        // A forged token is still a 403, whatever the key.
        $this->client->request('POST', '/tools/time-track', ['action' => 'stop', 'task_id' => '', '_token' => 'forged', 'activity_type' => 'legacy']);
        self::assertResponseStatusCodeSame(403);
    }

    public function testATypeRetiredBetweenTheFormAndTheStopIsRefusedWithAFlash(): void
    {
        $crawler    = $this->startFromThePage();
        $form       = $crawler->selectButton('Stop')->form();
        $activities = $this->activities();
        // First list of the POST (the form's choices): dev still active; the next one (the stop's check): retired.
        $activities->onList = static function () use ($activities): void {
            $activities->onList = static function (string $scope) use ($activities): void {
                $activities->perScope[$scope] = [new ActivityType('dev', 'Development', true, false)];
            };
        };

        $form->setValues(['activity_type' => 'dev']);
        $this->client->submit($form);
        $this->client->followRedirect();

        self::assertSelectorTextContains('.nowo-ui-flash--error', 'That activity type cannot be used. Choose another one.');
        self::assertNotNull($this->timers()->getActive($this->managedUser()));
        self::assertSame([], $this->entryActivityTypes());
    }

    public function testAStaleStopFormAfterTheTimerStoppedSaysSo(): void
    {
        $crawler = $this->startFromThePage();
        $form    = $crawler->selectButton('Stop')->form();
        $form->setValues(['activity_type' => 'dev']);
        $this->timers()->stop($this->managedUser());

        // The form has no choice any more (no timer): the posted key is ignored, as a stale 2.0 Stop form was.
        $this->client->submit($form);
        $this->client->followRedirect();
        self::assertSelectorTextContains('.nowo-ui-flash--warning', 'No active timer.');
    }

    public function testNoChoiceWhileTheTimerAlreadyHasAType(): void
    {
        $this->timers()->start($this->managedUser(), TimeAllocation::task('task-1', null, 'meeting'), ClientType::Web);

        $crawler = $this->client->request('GET', '/tools/time-track');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-testid="time-track-stop"]');
        self::assertCount(0, $crawler->filter('select[name="activity_type"]'));

        $this->client->submit($crawler->selectButton('Stop')->form());
        $this->client->followRedirect();
        self::assertSelectorTextContains('.nowo-ui-flash--success', 'Timer stopped.');
        self::assertSame(['meeting'], $this->entryActivityTypes());
    }

    /**
     * Starts the first task from the timer page; the page that follows (with the Stop form).
     */
    private function startFromThePage(): Crawler
    {
        $crawler = $this->client->request('GET', '/tools/time-track');
        self::assertResponseIsSuccessful();
        $this->client->submit($crawler->selectButton('Start')->first()->form());
        self::assertResponseRedirects('/tools/time-track');

        return $this->client->followRedirect();
    }

    /**
     * The activity type key of every stored entry.
     *
     * @return list<mixed>
     */
    private function entryActivityTypes(): array
    {
        return $this->bundleEntityManager()->getConnection()->fetchFirstColumn('SELECT activity_type_key FROM time_track_entries');
    }

    private function managedUser(): AppUser
    {
        $user = $this->entityManager()->find(AppUser::class, $this->user->getId());
        self::assertInstanceOf(AppUser::class, $user);

        return $user;
    }

    private function timers(): TimerService
    {
        $timers = self::getContainer()->get(TimerService::class);
        self::assertInstanceOf(TimerService::class, $timers);

        return $timers;
    }

    private function activities(): FixedActivityTypeProvider
    {
        $provider = self::getContainer()->get(FixedActivityTypeProvider::class);
        self::assertInstanceOf(FixedActivityTypeProvider::class, $provider);

        return $provider;
    }
}

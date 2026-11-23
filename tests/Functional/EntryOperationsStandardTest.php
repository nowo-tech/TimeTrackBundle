<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Functional;

use DateTimeImmutable;
use Nowo\TimeTrackBundle\Entry\ManualTimeEntryDraft;
use Nowo\TimeTrackBundle\Exception\ManualEntryException;
use Nowo\TimeTrackBundle\Tests\Fixtures\FunctionalEntity\AppUser;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * 1.40.0: the id-based operations on a standard host (`user_mapping: relation`, integer user ids; the application's
 * entity manager, or the private one when the suite runs with it).
 */
final class EntryOperationsStandardTest extends EntryOperationsTestCase
{
    /**
     * An integer id names the user; a value an integer identifier column cannot hold names nobody (never sent to SQL).
     */
    public function testIntegerIds(): void
    {
        $start = (new DateTimeImmutable('today -1 day'))->setTime(8, 0);
        $id    = $this->user->getId();
        self::assertIsInt($id);

        self::assertSame((string) $id, $this->operations()->createManual($id, new ManualTimeEntryDraft('task-1', $start, $start->modify('+10 minutes')))->entry->userId);
        foreach (['abc', '07', '-1', '99999999999999999999', '3000000000'] as $owner) {
            try {
                $this->operations()->createManual($owner, new ManualTimeEntryDraft('task-1', $start->modify('+1 hour'), $start->modify('+2 hours')));
                self::fail('created for ' . $owner);
            } catch (ManualEntryException $exception) {
                self::assertSame(ManualEntryException::UNKNOWN_USER, $exception->errorCode, $owner);
            }
        }
    }

    /**
     * @param non-empty-string $name
     */
    protected function person(string $name): UserInterface
    {
        return $name === 'ana' ? $this->user : $this->createUser($name . '@example.com');
    }

    protected function idOf(UserInterface $user): string
    {
        self::assertInstanceOf(AppUser::class, $user);

        return (string) $user->getId();
    }

    protected function unknownId(): string
    {
        return '987654';
    }
}

<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Unit\Import;

use LogicException;
use Nowo\TimeTrackBundle\Import\ImportedTimeEntryClaim;
use Nowo\TimeTrackBundle\Tests\Stub\TestUser;
use Nowo\TimeTrackBundle\ValueObject\TimeTrackUserRef;
use PHPUnit\Framework\TestCase;

/**
 * 1.23.0 (REQ-ARCH-001 rule 3): a claim built from a user reference equals the one built from the user.
 */
final class ImportedTimeEntryClaimTest extends TestCase
{
    public function testAClaimOfAReference(): void
    {
        $user  = new TestUser('42', 'ana@example.com');
        $claim = ImportedTimeEntryClaim::ofRef(TimeTrackUserRef::of($user, TestUser::class, '42', 'ana@example.com'), 'former:abc', 'org-a');

        self::assertEquals(new ImportedTimeEntryClaim($user, 'former:abc', 'org-a'), $claim);
        self::assertSame($user, $claim->user);
        self::assertSame(['42', $user], [$claim->userRef()->id, $claim->userRef()->user()]);
    }

    public function testAReferenceWithoutTheSecurityUserIsRefused(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('ImportedTimeEntryClaim::ofRef() returned a user reference without a Symfony security user object');

        ImportedTimeEntryClaim::ofRef(TimeTrackUserRef::ofId(TestUser::class, '42'), 'former:abc');
    }
}

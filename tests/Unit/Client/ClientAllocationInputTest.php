<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Unit\Client;

use InvalidArgumentException;
use Nowo\TimeTrackBundle\Client\ClientAllocationInput;
use Nowo\TimeTrackBundle\Subject\TimeSubjectRef;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Since 2.1.0 (Beacon spec 256): client API allocation input, strict shapes and tri-state patches.
 */
final class ClientAllocationInputTest extends TestCase
{
    public function testSubjectTriState(): void
    {
        self::assertSame([false, null], ClientAllocationInput::subject([]));
        self::assertSame([true, null], ClientAllocationInput::subject(['subject' => null]));
        [$given, $subject] = ClientAllocationInput::subject(['subject' => ['id' => 'c-1', 'type' => 'client']]);
        self::assertTrue($given);
        self::assertInstanceOf(TimeSubjectRef::class, $subject);
        self::assertSame(['type' => 'client', 'id' => 'c-1'], $subject->toArray());
    }

    public function testActivityTypeTriState(): void
    {
        self::assertSame([false, null], ClientAllocationInput::activityTypeKey([]));
        self::assertSame([true, null], ClientAllocationInput::activityTypeKey(['activityTypeKey' => null]));
        self::assertSame([true, 'dev'], ClientAllocationInput::activityTypeKey(['activityTypeKey' => 'dev']));
    }

    public function testScopeKey(): void
    {
        self::assertNull(ClientAllocationInput::scopeKey([]));
        self::assertNull(ClientAllocationInput::scopeKey(['scopeKey' => null]));
        self::assertSame('org-a', ClientAllocationInput::scopeKey(['scopeKey' => 'org-a']));
    }

    /**
     * @param array<string, mixed> $payload
     */
    #[DataProvider('malformed')]
    public function testMalformedShapesAreRefused(string $method, array $payload): void
    {
        $this->expectException(InvalidArgumentException::class);
        match ($method) {
            'subject'  => ClientAllocationInput::subject($payload),
            'activity' => ClientAllocationInput::activityTypeKey($payload),
            'scope'    => ClientAllocationInput::scopeKey($payload),
            default    => ClientAllocationInput::patch($payload),
        };
    }

    /**
     * @return iterable<string, array{string, array<string, mixed>}>
     */
    public static function malformed(): iterable
    {
        yield 'subject string' => ['subject', ['subject' => 'client:c-1']];
        yield 'subject list' => ['subject', ['subject' => ['client', 'c-1']]];
        yield 'subject missing id' => ['subject', ['subject' => ['type' => 'client']]];
        yield 'subject extra key' => ['subject', ['subject' => ['type' => 'client', 'id' => 'c-1', 'label' => 'x']]];
        yield 'subject int id' => ['subject', ['subject' => ['type' => 'client', 'id' => 7]]];
        yield 'subject int type' => ['subject', ['subject' => ['type' => 7, 'id' => 'c-1']]];
        yield 'subject bad type' => ['subject', ['subject' => ['type' => 'Client', 'id' => 'c-1']]];
        yield 'subject blank id' => ['subject', ['subject' => ['type' => 'client', 'id' => ' c-1']]];
        yield 'activity int' => ['activity', ['activityTypeKey' => 7]];
        yield 'activity bad' => ['activity', ['activityTypeKey' => 'Dev']];
        yield 'activity empty' => ['activity', ['activityTypeKey' => '']];
        yield 'scope int' => ['scope', ['scopeKey' => 7]];
        yield 'scope empty' => ['scope', ['scopeKey' => '']];
        yield 'scope blank' => ['scope', ['scopeKey' => 'org-a ']];
        yield 'patch bad subject' => ['patch', ['subject' => []]];
        yield 'patch bad activity' => ['patch', ['activityTypeKey' => false]];
    }

    public function testPatchBuilding(): void
    {
        self::assertTrue(ClientAllocationInput::patch([])->isKeep());

        $set = ClientAllocationInput::patch(['subject' => ['type' => 'client', 'id' => 'c-1'], 'activityTypeKey' => 'dev']);
        self::assertSame('c-1', $set->subjectAfter(null)?->id);
        self::assertSame('dev', $set->activityTypeAfter(null));

        $clear   = ClientAllocationInput::patch(['subject' => null, 'activityTypeKey' => null]);
        $current = new TimeSubjectRef('client', 'c-1');
        self::assertTrue($clear->changesSubjectOf($current));
        self::assertTrue($clear->changesActivityTypeOf('dev'));
        self::assertNull($clear->subjectAfter($current));

        $onlyActivity = ClientAllocationInput::patch(['activityTypeKey' => 'qa']);
        self::assertSame($current, $onlyActivity->subjectAfter($current));
        self::assertSame('qa', $onlyActivity->activityTypeAfter('dev'));
    }
}

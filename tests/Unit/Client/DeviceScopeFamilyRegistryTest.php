<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Unit\Client;

use InvalidArgumentException;
use LogicException;
use Nowo\TimeTrackBundle\Client\ConfigDeviceScopeFamilyProvider;
use Nowo\TimeTrackBundle\Client\DeviceScopeFamily;
use Nowo\TimeTrackBundle\Client\DeviceScopeFamilyProviderInterface;
use Nowo\TimeTrackBundle\Client\DeviceScopeFamilyRegistry;
use Nowo\TimeTrackBundle\Client\DeviceScopeRequest;
use Nowo\TimeTrackBundle\Enum\ClientScope;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use stdClass;

/**
 * Since 2.2.0 (Beacon Desktop BD-2): scope families — validation of a family, the registry (time first, deny by
 * default, one owner per key and per scope) and the parser of a device request's family and scopes.
 */
final class DeviceScopeFamilyRegistryTest extends TestCase
{
    public function testAFamilyIsValidatedOnConstruction(): void
    {
        $family = new DeviceScopeFamily('chat', 'Chat', ['chat:read' => 'Read', 'chat:write' => 'Send']);

        self::assertTrue($family->has('chat:read'));
        self::assertFalse($family->has('timer:read'));
        self::assertSame(['chat:read', 'chat:write'], $family->scopeValues());
        self::assertSame('Send', $family->labelOf('chat:write'));
        self::assertSame('chat:x', $family->labelOf('chat:x'));
        self::assertTrue(DeviceScopeFamily::isKey('chat_2'));
        self::assertFalse(DeviceScopeFamily::isKey('2chat'));
    }

    /**
     * @return iterable<string, array{string, string, array<mixed>}>
     */
    public static function invalidFamilies(): iterable
    {
        yield 'key' => ['Chat', 'Chat', ['chat:read' => 'Read']];
        yield 'label empty' => ['chat', ' ', ['chat:read' => 'Read']];
        yield 'label control' => ['chat', "Ch\nat", ['chat:read' => 'Read']];
        yield 'no scope' => ['chat', 'Chat', []];
        yield 'scope shape' => ['chat', 'Chat', ['chat' => 'Read']];
        yield 'scope integer key' => ['chat', 'Chat', [7 => 'Read']];
        yield 'scope label' => ['chat', 'Chat', ['chat:read' => '']];
        yield 'scope label type' => ['chat', 'Chat', ['chat:read' => 5]];
    }

    /**
     * @param array<mixed> $scopes
     */
    #[DataProvider('invalidFamilies')]
    public function testAMalformedFamilyIsRefused(string $key, string $label, array $scopes): void
    {
        $this->expectException(InvalidArgumentException::class);
        // @phpstan-ignore argument.type (malformed on purpose)
        new DeviceScopeFamily($key, $label, $scopes);
    }

    public function testTheRegistryListsTimeFirstThenTheProviders(): void
    {
        $registry = $this->registry();

        self::assertSame(['time', 'chat'], array_keys($registry->all()));
        self::assertTrue($registry->has('chat'));
        self::assertFalse($registry->has('voice'));
        self::assertSame('Chat', $registry->labelOf('chat'));
        self::assertSame(DeviceScopeFamilyRegistry::TIME_LABEL, $registry->labelOf('time'));
        self::assertSame('voice', $registry->labelOf('voice'));
        self::assertNull($registry->get('voice'));
        self::assertSame(ClientScope::values(ClientScope::cases()), DeviceScopeFamilyRegistry::timeFamily()->scopeValues());
        self::assertSame(['time'], array_keys((new DeviceScopeFamilyRegistry())->all()));
    }

    public function testAProviderCannotTakeTimeAKeyTwiceOrAnotherFamilysScope(): void
    {
        foreach ([
            [new DeviceScopeFamily('time', 'Mine', ['mine:read' => 'x'])],
            [new DeviceScopeFamily('chat', 'Chat', ['chat:read' => 'x']), new DeviceScopeFamily('chat', 'Chat 2', ['chat:write' => 'x'])],
            [new DeviceScopeFamily('chat', 'Chat', ['timer:read' => 'x'])],
            [new DeviceScopeFamily('chat', 'Chat', ['chat:read' => 'x']), new DeviceScopeFamily('voice', 'Voice', ['chat:read' => 'x'])],
            [new stdClass()],
        ] as $families) {
            $provider = new class($families) implements DeviceScopeFamilyProviderInterface {
                /** @param list<mixed> $families */
                public function __construct(private array $families)
                {
                }

                public function scopeFamilies(): iterable
                {
                    // @phpstan-ignore return.type (a broken provider on purpose)
                    return $this->families;
                }
            };
            try {
                (new DeviceScopeFamilyRegistry([$provider]))->all();
                self::fail('refused');
            } catch (LogicException $e) {
                self::assertStringContainsString('nowo_time_track', $e->getMessage());
            }
        }
    }

    public function testNoFamilyAndNoScopesIsTheTimeDefault(): void
    {
        $request = $this->registry()->resolve(null, null);

        self::assertTrue($request->isTime());
        self::assertSame(ClientScope::values(ClientScope::defaults()), $request->scopes);
        self::assertSame(ClientScope::defaults(), $request->timeScopes());
        self::assertSame(ClientScope::values(ClientScope::defaults()), $this->registry()->resolve('time', null)->scopes);
    }

    public function testTheFamilyFollowsFromTheScopesInDeclarationOrder(): void
    {
        $request = $this->registry()->resolve(null, ['chat:write', 'chat:read', 'chat:write']);

        self::assertSame('chat', $request->family);
        self::assertSame(['chat:read', 'chat:write'], $request->scopes);
        self::assertFalse($request->isTime());
        self::assertSame([], $request->timeScopes());
        self::assertSame(['timer:read', 'monitoring:write'], $this->registry()->resolve('time', ['monitoring:write', 'timer:read'])->scopes);
        self::assertEquals(new DeviceScopeRequest('chat', ['chat:read']), $this->registry()->resolve('chat', ['chat:read']));
    }

    /**
     * @return iterable<string, array{mixed, mixed}>
     */
    public static function refusedRequests(): iterable
    {
        yield 'family type' => [['chat'], ['chat:read']];
        yield 'family shape' => ['Chat', ['chat:read']];
        yield 'family unknown' => ['voice', ['voice:call']];
        yield 'scopes type' => [null, 'chat:read'];
        yield 'family without scopes' => ['chat', null];
        yield 'empty list' => [null, []];
        yield 'unknown scope' => [null, ['chat:delete']];
        yield 'scope type' => [null, [1]];
        yield 'mixed families' => [null, ['timer:read', 'chat:read']];
        yield 'scope of another family' => ['time', ['chat:read']];
    }

    #[DataProvider('refusedRequests')]
    public function testARequestOutsideOneRegisteredFamilyIsRefused(mixed $family, mixed $scopes): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->registry()->resolve($family, $scopes);
    }

    private function registry(): DeviceScopeFamilyRegistry
    {
        return new DeviceScopeFamilyRegistry([new ConfigDeviceScopeFamilyProvider(['chat' => ['label' => 'Chat', 'scopes' => ['chat:read' => 'Read', 'chat:write' => 'Send']]])]);
    }
}

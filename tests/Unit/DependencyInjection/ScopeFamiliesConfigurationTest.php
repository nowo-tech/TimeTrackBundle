<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Unit\DependencyInjection;

use App\Entity\User;
use Nowo\TimeTrackBundle\DependencyInjection\Configuration;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Config\Definition\Processor;

/**
 * Since 2.2.0: `client_api.device_flow.scope_families` and `client_api.context` — defaults, a valid family and every
 * refused shape (the build fails instead of registering a family that could never be checked).
 */
final class ScopeFamiliesConfigurationTest extends TestCase
{
    public function testDefaults(): void
    {
        $config = $this->process([]);

        self::assertSame([], $config['client_api']['device_flow']['scope_families']);
        self::assertSame(['policy' => null, 'header' => 'X-Client-Context', 'required' => false], $config['client_api']['context']);
    }

    public function testAFamilyAndAContext(): void
    {
        $config = $this->process(['client_api' => [
            'device_flow' => ['scope_families' => ['chat' => ['label' => 'Chat', 'scopes' => ['chat:read' => 'Read', 'chat:write' => 'Send']]]],
            'context'     => ['policy' => 'app.context_policy', 'header' => 'X-Beacon-Context', 'required' => true],
        ]]);

        self::assertSame(['chat' => ['label' => 'Chat', 'scopes' => ['chat:read' => 'Read', 'chat:write' => 'Send']]], $config['client_api']['device_flow']['scope_families']);
        self::assertSame(['policy' => 'app.context_policy', 'header' => 'X-Beacon-Context', 'required' => true], $config['client_api']['context']);
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function refused(): iterable
    {
        yield 'time family' => [['device_flow' => ['scope_families' => ['time' => ['label' => 'Mine', 'scopes' => ['mine:read' => 'x']]]]]];
        yield 'bad key' => [['device_flow' => ['scope_families' => ['Chat' => ['label' => 'Chat', 'scopes' => ['chat:read' => 'x']]]]]];
        yield 'bad scope' => [['device_flow' => ['scope_families' => ['chat' => ['label' => 'Chat', 'scopes' => ['chat' => 'x']]]]]];
        yield 'time scope' => [['device_flow' => ['scope_families' => ['chat' => ['label' => 'Chat', 'scopes' => ['timer:read' => 'x']]]]]];
        yield 'no scope' => [['device_flow' => ['scope_families' => ['chat' => ['label' => 'Chat', 'scopes' => []]]]]];
        yield 'header shape' => [['context' => ['header' => 'X Beacon']]];
        yield 'standard header' => [['context' => ['header' => 'Authorization']]];
    }

    /**
     * @param array<string, mixed> $clientApi
     */
    #[DataProvider('refused')]
    public function testARefusedShapeFailsTheBuild(array $clientApi): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->process(['client_api' => $clientApi]);
    }

    /**
     * @param array<string, mixed> $config
     *
     * @return array<string, mixed>
     */
    private function process(array $config): array
    {
        /* @var array<string, mixed> */
        return (new Processor())->processConfiguration(new Configuration(), [['user_class' => User::class] + $config]);
    }
}

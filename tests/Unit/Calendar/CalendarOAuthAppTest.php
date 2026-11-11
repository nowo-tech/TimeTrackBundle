<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Unit\Calendar;

use DateTimeImmutable;
use LogicException;
use Monolog\Formatter\JsonFormatter;
use Monolog\Formatter\LineFormatter;
use Monolog\Formatter\NormalizerFormatter;
use Monolog\Level;
use Monolog\LogRecord;
use Nowo\TimeTrackBundle\Calendar\CalendarOAuthApp;
use Nowo\TimeTrackBundle\Calendar\SensitiveValue;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Serializer\Encoder\JsonEncoder;
use Symfony\Component\Serializer\Normalizer\JsonSerializableNormalizer;
use Symfony\Component\Serializer\Normalizer\ObjectNormalizer;
use Symfony\Component\Serializer\Serializer;
use Symfony\Component\VarDumper\Cloner\VarCloner;
use Symfony\Component\VarDumper\Dumper\CliDumper;
use Symfony\Component\VarDumper\Dumper\HtmlDumper;

use function get_object_vars;
use function json_encode;
use function ob_get_clean;
use function ob_start;
use function print_r;
use function serialize;
use function str_repeat;
use function unserialize;
use function var_dump;
use function var_export;

use const JSON_THROW_ON_ERROR;

/**
 * Since 1.20: the runtime OAuth app keeps its client secret out of every generic channel — `__toString()`,
 * `serialize()`, `var_export()`, `var_dump()` / `print_r()`, VarDumper (dump, profiler), `json_encode()`, casts,
 * Serializer and Monolog. Only {@see CalendarOAuthApp::revealClientSecret()} returns it. The secret is a fake marker
 * built at run time.
 */
final class CalendarOAuthAppTest extends TestCase
{
    public function testTheSecretIsReadOnlyThroughItsAccessor(): void
    {
        $secret = $this->secret();
        $app    = new CalendarOAuthApp('client-id', $secret, 'organizations');

        self::assertSame($secret, $app->revealClientSecret());
        self::assertSame(['client-id', 'organizations'], [$app->clientId, $app->tenant]);
        self::assertTrue($app->isComplete());
        self::assertSame('CalendarOAuthApp(clientId: client-id, clientSecret: [redacted], tenant: organizations)', (string) $app);
        self::assertSame('CalendarOAuthApp(clientId: id, clientSecret: [redacted], tenant: null)', (string) new CalendarOAuthApp('id', $secret));
        self::assertSame(['clientId' => 'client-id', 'clientSecret' => SensitiveValue::REDACTED, 'tenant' => 'organizations'], $app->jsonSerialize());
    }

    public function testBlankValuesAreIncomplete(): void
    {
        self::assertFalse((new CalendarOAuthApp('', $this->secret()))->isComplete());
        self::assertFalse((new CalendarOAuthApp('  ', $this->secret()))->isComplete());
        self::assertFalse((new CalendarOAuthApp('id', ''))->isComplete());
        self::assertFalse((new CalendarOAuthApp('id', " \t"))->isComplete());
    }

    public function testNoGenericChannelPrintsTheSecret(): void
    {
        $secret = $this->secret();
        $app    = new CalendarOAuthApp('client-id', $secret, 'common');
        $record = new LogRecord(new DateTimeImmutable(), 'app', Level::Info, 'oauth', ['app' => $app, 'list' => [$app]]);
        ob_start();
        var_dump($app);
        $varDump = (string) ob_get_clean();
        $cloner  = new VarCloner();

        $channels = [
            'string'              => (string) $app,
            'serialize'           => serialize($app),
            'var_export'          => var_export($app, true),
            'var_export (array)'  => var_export((array) $app, true),
            'var_dump'            => $varDump,
            'print_r'             => print_r($app, true),
            'print_r (array)'     => print_r((array) $app, true),
            'json_encode'         => json_encode($app, JSON_THROW_ON_ERROR),
            'json_encode (array)' => json_encode((array) $app, JSON_THROW_ON_ERROR),
            'get_object_vars'     => json_encode(get_object_vars($app), JSON_THROW_ON_ERROR),
            'var-dumper cli'      => (string) (new CliDumper())->dump($cloner->cloneVar($app), true),
            'var-dumper html'     => (string) (new HtmlDumper())->dump($cloner->cloneVar($app), true),
            'var-dumper (array)'  => (string) (new CliDumper())->dump($cloner->cloneVar((array) $app), true),
            'serializer (json)'   => (new Serializer([new JsonSerializableNormalizer(), new ObjectNormalizer()], [new JsonEncoder()]))->serialize($app, 'json'),
            'serializer (object)' => (new Serializer([new ObjectNormalizer()], [new JsonEncoder()]))->serialize($app, 'json'),
            'monolog normaliser'  => json_encode((new NormalizerFormatter())->format($record), JSON_THROW_ON_ERROR),
            'monolog json'        => (new JsonFormatter())->format($record),
            'monolog line'        => (new LineFormatter())->format($record),
        ];

        foreach ($channels as $channel => $output) {
            self::assertStringNotContainsString($secret, $output, $channel . ' leaks the client secret');
        }
        self::assertStringContainsString(SensitiveValue::REDACTED, $channels['serialize']);
    }

    public function testARedactedCopyCannotBeRestored(): void
    {
        $this->expectException(LogicException::class);

        unserialize(serialize(new CalendarOAuthApp('id', $this->secret())));
    }

    /**
     * A fake secret, built so no credential-like literal is committed.
     */
    private function secret(): string
    {
        return 'REDACTED-TEST-secret-' . str_repeat('s', 6);
    }
}

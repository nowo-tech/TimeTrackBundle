<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Unit\Exception;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Symfony\Component\Yaml\Yaml;

use function array_keys;
use function dirname;
use function file_get_contents;
use function glob;
use function is_array;
use function preg_match_all;
use function sort;
use function str_ends_with;

/**
 * Every ledger refusal code thrown in src/ (`new LedgerRefusedException('…')`) has a `timesheet.error.<code>`
 * message in every shipped locale, so hosts can show `timesheet.error.<code>` for both exception kinds.
 */
final class LedgerRefusedTranslationsTest extends TestCase
{
    public function testEveryLedgerCodeIsTranslatedInEveryLocale(): void
    {
        $root  = dirname(__DIR__, 3);
        $codes = [];
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/src')) as $file) {
            if (!str_ends_with((string) $file, '.php')) {
                continue;
            }
            preg_match_all("/new LedgerRefusedException\\('([a-z_]+)'\\)/", (string) file_get_contents((string) $file), $m);
            foreach ($m[1] as $code) {
                $codes[$code] = true;
            }
        }
        $codes = array_keys($codes);
        sort($codes);
        self::assertSame(['already_reversed', 'conflict', 'forbidden', 'invalid_date', 'invalid_request', 'invalid_seconds', 'not_found', 'not_reversible', 'opening_exists', 'ratify_only', 'reason_required'], $codes);

        $files = glob($root . '/src/Resources/translations/NowoTimeTrackBundle.*.yaml') ?: [];
        self::assertCount(7, $files);
        foreach ($files as $file) {
            $messages = Yaml::parseFile($file);
            self::assertIsArray($messages);
            $errors = $messages['timesheet']['error'] ?? null;
            self::assertTrue(is_array($errors), $file);
            foreach ($codes as $code) {
                self::assertArrayHasKey($code, $errors, $file . ': timesheet.error.' . $code);
                self::assertNotSame('', $errors[$code]);
            }
        }
    }
}

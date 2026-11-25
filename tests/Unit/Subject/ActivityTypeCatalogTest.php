<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Unit\Subject;

use Nowo\TimeTrackBundle\Subject\ActivityType;
use Nowo\TimeTrackBundle\Subject\ActivityTypeCatalog;
use Nowo\TimeTrackBundle\Subject\ActivityTypeList;
use Nowo\TimeTrackBundle\Subject\ActivityTypeProviderInterface;
use Nowo\TimeTrackBundle\Tests\Support\RecordingLogger;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Since 2.1.0 (Beacon spec 256): without a provider or when it fails, no activity type is accepted.
 */
final class ActivityTypeCatalogTest extends TestCase
{
    public function testWithoutProviderTheCatalogIsOff(): void
    {
        $catalog = new ActivityTypeCatalog();

        self::assertFalse($catalog->isEnabled());
        self::assertSame([], $catalog->listFor('org-a')->all());
    }

    public function testTheProviderIsAskedPerScope(): void
    {
        $provider = new class implements ActivityTypeProviderInterface {
            /** @var list<string> */
            public array $asked = [];

            /**
             * One type named after the scope.
             */
            public function activityTypesFor(string $scopeKey): ActivityTypeList
            {
                $this->asked[] = $scopeKey;

                return ActivityTypeList::of([new ActivityType('dev', 'Dev ' . $scopeKey)]);
            }
        };
        $catalog = new ActivityTypeCatalog($provider);

        self::assertTrue($catalog->isEnabled());
        self::assertSame('Dev org-a', $catalog->listFor('org-a')->find('dev')?->label);
        $catalog->listFor(null);
        self::assertSame(['org-a', ''], $provider->asked);
    }

    public function testAFailingProviderAnswersEmptyWithAWarning(): void
    {
        $logger  = new RecordingLogger();
        $catalog = new ActivityTypeCatalog(new class implements ActivityTypeProviderInterface {
            /**
             * Always fails.
             */
            public function activityTypesFor(string $scopeKey): ActivityTypeList
            {
                throw new RuntimeException('down for org-a');
            }
        }, $logger);

        self::assertSame([], $catalog->listFor('org-a')->all());
        self::assertCount(1, $logger->records);
        self::assertSame('warning', $logger->records[0]['level']);
        self::assertSame(['bundle' => 'time_track', 'action' => 'activity_types', 'exception' => RuntimeException::class], $logger->records[0]['context']);
    }
}

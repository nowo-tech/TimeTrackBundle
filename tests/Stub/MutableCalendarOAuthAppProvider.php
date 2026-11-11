<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Stub;

use Nowo\TimeTrackBundle\Calendar\CalendarOAuthApp;
use Nowo\TimeTrackBundle\Calendar\CalendarOAuthAppProviderInterface;
use RuntimeException;

/**
 * Since 1.20: a host app store the tests change between calls (an admin rotating or removing an app). Counts the
 * lookups; `$failing` simulates a store that is down. Every secret here is a fake marker built at run time.
 */
final class MutableCalendarOAuthAppProvider implements CalendarOAuthAppProviderInterface
{
    /** @var array<string, CalendarOAuthApp|null> provider => app */
    public array $apps = [];

    /** @var list<string> */
    public array $asked = [];

    public bool $failing = false;

    public function app(string $provider): ?CalendarOAuthApp
    {
        $this->asked[] = $provider;
        if ($this->failing) {
            throw new RuntimeException('store down: ' . ($this->apps[$provider]?->revealClientSecret() ?? 'none'));
        }

        return $this->apps[$provider] ?? null;
    }
}

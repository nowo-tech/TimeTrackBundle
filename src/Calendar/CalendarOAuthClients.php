<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Calendar;

use Nowo\TimeTrackBundle\Enum\CalendarProvider;

/**
 * Since 1.20 (Beacon request 2026-11-11): provider discovery for a host Integrations page, its connect buttons and its
 * token refresh job. Every answer asks the OAuth clients, which ask the {@see CalendarOAuthAppProviderInterface} (or
 * read the static `calendar.oauth.*`) at that moment: a provider whose app was removed disappears on the next call,
 * nothing is cached.
 */
final readonly class CalendarOAuthClients
{
    /** @var array<string, CalendarOAuthClientInterface> */
    private array $clients;

    /**
     * Receives the bundle's Google and Microsoft OAuth helpers.
     */
    public function __construct(GoogleCalendarOAuthClient $google, MicrosoftCalendarOAuthClient $microsoft)
    {
        $this->clients = [CalendarProvider::Google->value => $google, CalendarProvider::Microsoft->value => $microsoft];
    }

    /**
     * The OAuth helper of a provider (configured or not; check {@see self::isConfigured()} first).
     */
    public function client(CalendarProvider $provider): CalendarOAuthClientInterface
    {
        return $this->clients[$provider->value];
    }

    /**
     * Whether the provider has an OAuth app right now.
     */
    public function isConfigured(CalendarProvider $provider): bool
    {
        return $this->client($provider)->isConfigured();
    }

    /**
     * The providers with an OAuth app right now, in a stable order (Google, Microsoft).
     *
     * @return list<CalendarProvider>
     */
    public function configuredProviders(): array
    {
        $providers = [];
        foreach ($this->clients as $client) {
            if ($client->isConfigured()) {
                $providers[] = $client->provider();
            }
        }

        return $providers;
    }
}

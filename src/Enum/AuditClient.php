<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Enum;

/**
 * Since 1.13: channel a timesheet transition came from (`time_track_timesheet_events.client`).
 */
enum AuditClient: string
{
    case Web       = 'web';
    case Desktop   = 'desktop';
    case Extension = 'extension';
    case System    = 'system';
    case Api       = 'api';

    /**
     * The channel of a client API token type.
     */
    public static function fromClientType(ClientType $type): self
    {
        return match ($type) {
            ClientType::Desktop   => self::Desktop,
            ClientType::Extension => self::Extension,
            ClientType::Web       => self::Api,
        };
    }
}

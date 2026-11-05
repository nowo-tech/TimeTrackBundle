<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Workday;

use RuntimeException;

/**
 * Since 1.13: the user may not act on the register (403 `forbidden`).
 */
final class WorkdayForbiddenException extends RuntimeException
{
}

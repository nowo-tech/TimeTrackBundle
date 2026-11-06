<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Meeting;

use RuntimeException;

/**
 * Since 1.14 (contract §22.5): a meetings request was invalid. Carries the code and the field, never the submitted
 * value.
 */
final class MeetingRequestException extends RuntimeException
{
    /**
     * @param string $errorCode `invalid_datetime`, `invalid_timezone`, `invalid_range`, `invalid_limit` or `unknown_parameter`
     * @param string $field The query parameter at fault
     */
    public function __construct(
        public readonly string $errorCode,
        public readonly string $field,
    ) {
        parent::__construct($errorCode);
    }
}

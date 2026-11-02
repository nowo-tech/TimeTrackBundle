<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Dto;

/**
 * Answer of `POST device/code`: the secret device code (kept by the client), the user code (typed by the user in the
 * host) and the polling parameters. Only hashes of both codes are stored.
 */
final readonly class DeviceCodeGrant
{
    /**
     * Creates the grant.
     */
    public function __construct(
        public string $deviceCode,
        public string $userCode,
        public int $expiresIn,
        public int $interval,
    ) {
    }

    /**
     * JSON body (`verificationUri` is added by the controller).
     *
     * @return array{deviceCode: string, userCode: string, expiresIn: int, interval: int}
     */
    public function toArray(): array
    {
        return [
            'deviceCode' => $this->deviceCode,
            'userCode'   => $this->userCode,
            'expiresIn'  => $this->expiresIn,
            'interval'   => $this->interval,
        ];
    }
}

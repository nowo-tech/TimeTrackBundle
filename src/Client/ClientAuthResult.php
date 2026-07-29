<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Client;

use LogicException;
use Symfony\Component\Security\Core\User\UserInterface;

final readonly class ClientAuthResult
{
    private function __construct(
        private bool $success,
        private ?UserInterface $user = null,
    ) {
    }

    public static function success(UserInterface $user): self
    {
        return new self(true, $user);
    }

    public static function failure(): self
    {
        return new self(false);
    }

    public function isSuccess(): bool
    {
        return $this->success;
    }

    public function getUser(): ?UserInterface
    {
        return $this->user;
    }

    /**
     * @throws LogicException when called on a failed result
     */
    public function requireUser(): UserInterface
    {
        if (!$this->success || !$this->user instanceof UserInterface) {
            throw new LogicException('ClientAuthResult has no authenticated user.');
        }

        return $this->user;
    }
}

<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Client;

use Nowo\TimeTrackBundle\Enum\ClientScope;

/**
 * Since 2.2.0: the scopes one device request asks for, all of one family (built by
 * {@see DeviceScopeFamilyRegistry::resolve()}; a request never mixes families). Value object.
 */
final readonly class DeviceScopeRequest
{
    /**
     * @param string $family Family key
     * @param list<string> $scopes Requested scope values of that family (non-empty, no duplicate)
     */
    public function __construct(
        public string $family,
        public array $scopes,
    ) {
    }

    /**
     * Whether the request is for the time family.
     */
    public function isTime(): bool
    {
        return $this->family === DeviceScopeFamily::TIME;
    }

    /**
     * The time scopes of the request (empty for another family).
     *
     * @return list<ClientScope>
     */
    public function timeScopes(): array
    {
        return $this->isTime() ? ClientScope::normalize($this->scopes) : [];
    }
}

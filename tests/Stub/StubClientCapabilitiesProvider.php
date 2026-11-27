<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Stub;

use Nowo\TimeTrackBundle\Client\ClientCapabilitiesProviderInterface;
use Nowo\TimeTrackBundle\ValueObject\TimeTrackUserRef;
use RuntimeException;

use function in_array;

/**
 * Test capabilities provider (2.4.0): the families in `$allowed` are usable by everyone, any other is refused (deny by
 * default); `$deniedInContext` refuses one context; `$fail` makes every call throw. Records each question so a test can
 * prove it is asked on every request, with the context.
 */
final class StubClientCapabilitiesProvider implements ClientCapabilitiesProviderInterface
{
    /** @var list<string> */
    public array $allowed = ['time', 'chat'];

    public ?string $deniedInContext = null;

    public bool $fail = false;

    /** @var list<array{string, string, string|null}> identifier, family, context */
    public array $asked = [];

    public function canUse(TimeTrackUserRef $user, string $scopeFamily, ?string $context): bool
    {
        $this->asked[] = [$user->identifier, $scopeFamily, $context];
        if ($this->fail) {
            throw new RuntimeException('provider down');
        }

        return in_array($scopeFamily, $this->allowed, true) && ($context === null || $context !== $this->deniedInContext);
    }

    public function reset(): void
    {
        $this->allowed         = ['time', 'chat'];
        $this->deniedInContext = null;
        $this->fail            = false;
        $this->asked           = [];
    }
}

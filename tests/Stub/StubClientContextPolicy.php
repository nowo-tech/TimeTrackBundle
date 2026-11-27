<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Stub;

use Nowo\TimeTrackBundle\Client\ClientContextOption;
use Nowo\TimeTrackBundle\Client\ClientContextPolicyInterface;
use Nowo\TimeTrackBundle\ValueObject\TimeTrackUserRef;
use RuntimeException;

use function in_array;

/**
 * Test context policy (2.2.0): everyone has `personal`; the identifiers in `$members` also have `org:acme`; `$fail`
 * makes every call throw. Records each question so a test can prove it is asked on every request.
 */
final class StubClientContextPolicy implements ClientContextPolicyInterface
{
    /** @var list<string> user identifiers that are members of org:acme */
    public array $members = ['person@example.com'];

    public bool $fail = false;

    /** Since 2.4.0: a family for which only `personal` is accepted (per-family policies, audit ZDB-v-2). */
    public ?string $personalOnlyFamily = null;

    /** @var list<array{string, string, string}> identifier, requested, family */
    public array $asked = [];

    public function contextFor(TimeTrackUserRef $user, string $requested, string $scopeFamily): ?string
    {
        $this->asked[] = [$user->identifier, $requested, $scopeFamily];
        if ($this->fail) {
            throw new RuntimeException('policy down');
        }

        if ($scopeFamily === $this->personalOnlyFamily && $requested !== 'personal') {
            return null;
        }

        return match (true) {
            $requested === 'personal'                                                      => 'personal',
            $requested === 'org:acme' && in_array($user->identifier, $this->members, true) => 'org:acme',
            default                                                                        => null,
        };
    }

    public function contextsOf(TimeTrackUserRef $user, string $scopeFamily): array
    {
        if ($this->fail) {
            throw new RuntimeException('policy down');
        }
        $contexts = [new ClientContextOption('personal', 'Personal')];
        if (in_array($user->identifier, $this->members, true)) {
            $contexts[] = new ClientContextOption('org:acme', 'Acme');
        }

        return $contexts;
    }

    public function reset(): void
    {
        $this->members            = ['person@example.com'];
        $this->fail               = false;
        $this->personalOnlyFamily = null;
        $this->asked              = [];
    }
}

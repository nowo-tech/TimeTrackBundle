<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Support\Legacy;

use DateTimeImmutable;
use Nowo\TimeTrackBundle\Client\ClientDaysOffNormalizer;
use Nowo\TimeTrackBundle\Client\ClientPrivacyNormalizer;
use Nowo\TimeTrackBundle\Client\ClientUserDaysOffProviderInterface;
use Nowo\TimeTrackBundle\Client\ClientUserLocaleProviderInterface;
use Nowo\TimeTrackBundle\Client\ClientUserPrivacyProviderInterface;
use Nowo\TimeTrackBundle\Tests\Unit\ClientApi\ProfileExtrasEquivalenceTest;
use Psr\Log\NullLogger;
use ReflectionMethod;
use Symfony\Component\Security\Core\User\UserInterface;

use function array_key_exists;
use function date_default_timezone_get;
use function is_string;
use function method_exists;
use function preg_match;

/**
 * LITERAL copies of the v1.22.1 (e8b18c4) decisions of `TimeTrackClientApiController` about the profile provider's
 * extras, for the 1.23.0 equivalence test ({@see ProfileExtrasEquivalenceTest}). Each body is the 1.22.1 method body
 * verbatim; only `$this->x` fields became parameters. Never edit a body: a change here hides a behaviour change.
 *
 * Sources: `me()` (the `privacy` block), `userLocale()`, `daysOff()`, `isArgumentless()`.
 */
final class Legacy1221MeDecisions
{
    /** `TimeTrackClientApiController::LOCALE_PATTERN` of 1.22.1. */
    private const LOCALE_PATTERN = '/^[A-Za-z]{2,3}(?:[_-][A-Za-z0-9]{2,8}){0,3}$/D';

    /**
     * The `privacy` key of `me()`: [whether the key is present, its value].
     *
     * @return array{0: bool, 1: mixed}
     */
    public static function privacy(?object $profileProvider, UserInterface $user, ?string $privacyLocale): array
    {
        $body = [];
        if ($profileProvider instanceof ClientUserPrivacyProviderInterface) {
            $body['privacy'] = ClientPrivacyNormalizer::normalize($profileProvider->getPrivacy($user, $privacyLocale), new NullLogger());
        }

        return [array_key_exists('privacy', $body), $body['privacy'] ?? null];
    }

    public static function userLocale(?object $profileProvider, UserInterface $user): ?string
    {
        if ($profileProvider instanceof ClientUserLocaleProviderInterface) {
            $locale = $profileProvider->getLocale($user);
        } else {
            $locale = method_exists($user, 'getLocale') && self::isArgumentless(new ReflectionMethod($user, 'getLocale')) ? $user->getLocale() : null;
        }

        return is_string($locale) && preg_match(self::LOCALE_PATTERN, $locale) === 1 ? $locale : null;
    }

    /**
     * @return list<array{date: string, kind: string, label: string|null}>|null
     */
    public static function daysOff(?object $profileProvider, UserInterface $user, ?string $timezone, DateTimeImmutable $now, bool $reportsLeave): ?array
    {
        if (!$profileProvider instanceof ClientUserDaysOffProviderInterface) {
            return null;
        }

        [$from, $to] = ClientDaysOffNormalizer::range($now, $timezone ?? date_default_timezone_get());

        // Since 1.22: the `leave` kind only when the host reports leave (audit NG-1; without it the 1.21 output).
        return ClientDaysOffNormalizer::normalize($profileProvider->getDaysOff($user, $from, $to), $from->format('Y-m-d'), $to->format('Y-m-d'), new NullLogger(), $reportsLeave);
    }

    private static function isArgumentless(ReflectionMethod $method): bool
    {
        return $method->isPublic() && !$method->isStatic() && $method->getNumberOfRequiredParameters() === 0;
    }
}

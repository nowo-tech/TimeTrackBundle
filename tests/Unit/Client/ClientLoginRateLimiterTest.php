<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Unit\Client;

use Nowo\TimeTrackBundle\Client\ClientLoginRateLimiter;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

final class ClientLoginRateLimiterTest extends TestCase
{
    public function testDisabledLimiterNeverLimits(): void
    {
        $limiter = new ClientLoginRateLimiter(new ArrayAdapter(), 3, 300, false);

        $limiter->registerFailedAttempt('1.1.1.1', 'user');
        self::assertFalse($limiter->isLimited('1.1.1.1', 'user'));
    }

    public function testLimitsAfterMaxAttempts(): void
    {
        $limiter = new ClientLoginRateLimiter(new ArrayAdapter(), 2, 300, true);

        $limiter->registerFailedAttempt('1.1.1.1', 'user');
        self::assertFalse($limiter->isLimited('1.1.1.1', 'user'));

        $limiter->registerFailedAttempt('1.1.1.1', 'user');
        self::assertTrue($limiter->isLimited('1.1.1.1', 'user'));
    }

    public function testResetClearsAttempts(): void
    {
        $limiter = new ClientLoginRateLimiter(new ArrayAdapter(), 1, 300, true);

        $limiter->registerFailedAttempt('1.1.1.1', 'user');
        self::assertTrue($limiter->isLimited('1.1.1.1', 'user'));

        $limiter->reset('1.1.1.1', 'user');
        self::assertFalse($limiter->isLimited('1.1.1.1', 'user'));
    }

    public function testZeroMaxAttemptsNeverLimits(): void
    {
        $limiter = new ClientLoginRateLimiter(new ArrayAdapter(), 0, 300, true);

        $limiter->registerFailedAttempt('1.1.1.1', 'user');
        self::assertFalse($limiter->isLimited('1.1.1.1', 'user'));
    }

    public function testIsLimitedIgnoresInvalidCachePayload(): void
    {
        $cache = new ArrayAdapter();
        $key   = 'nowo_time_track_client_login_' . hash('sha256', '1.1.1.1|' . mb_strtolower('user'));
        $item  = $cache->getItem($key);
        $item->set('not-an-array');
        $cache->save($item);

        $limiter = new ClientLoginRateLimiter($cache, 1, 300, true);

        self::assertFalse($limiter->isLimited('1.1.1.1', 'user'));
    }

    public function testRegisterFailedAttemptUsesMinimumInterval(): void
    {
        $limiter = new ClientLoginRateLimiter(new ArrayAdapter(), 2, 30, true);
        $limiter->registerFailedAttempt('1.1.1.1', 'user');

        self::assertFalse($limiter->isLimited('1.1.1.1', 'user'));
    }

    public function testResetNoOpWhenDisabled(): void
    {
        $limiter = new ClientLoginRateLimiter(new ArrayAdapter(), 2, 300, false);
        $limiter->reset('1.1.1.1', 'user');

        self::assertFalse($limiter->isLimited('1.1.1.1', 'user'));
    }

    public function testIpBucketBlocksUsernameRotation(): void
    {
        $limiter = new ClientLoginRateLimiter(new ArrayAdapter(), 2, 300, true);

        for ($i = 0; $i < 10; ++$i) {
            self::assertFalse($limiter->isLimited('1.1.1.1', 'user' . $i));
            $limiter->registerFailedAttempt('1.1.1.1', 'user' . $i);
        }

        // Default per-IP budget is 5 × max_attempts = 10: every username on this IP is blocked now.
        self::assertTrue($limiter->isLimited('1.1.1.1', 'fresh-user'));
        self::assertFalse($limiter->isLimited('2.2.2.2', 'fresh-user'));
    }

    public function testCustomPerIpBudget(): void
    {
        $limiter = new ClientLoginRateLimiter(new ArrayAdapter(), 5, 300, true, 3);

        $limiter->registerFailedAttempt('1.1.1.1', 'a');
        $limiter->registerFailedAttempt('1.1.1.1', 'b');
        self::assertFalse($limiter->isLimited('1.1.1.1', 'c'));

        $limiter->registerFailedAttempt('1.1.1.1', 'c');
        self::assertTrue($limiter->isLimited('1.1.1.1', 'd'));
    }

    public function testSuccessfulLoginDoesNotResetIpBucket(): void
    {
        $limiter = new ClientLoginRateLimiter(new ArrayAdapter(), 5, 300, true, 2);

        $limiter->registerFailedAttempt('1.1.1.1', 'victim');
        $limiter->registerFailedAttempt('1.1.1.1', 'victim');
        $limiter->reset('1.1.1.1', 'attacker');

        self::assertTrue($limiter->isLimited('1.1.1.1', 'victim'));
        self::assertTrue($limiter->isLimited('1.1.1.1', 'attacker'));
    }

    public function testUsernameIsNormalized(): void
    {
        $limiter = new ClientLoginRateLimiter(new ArrayAdapter(), 2, 300, true);

        $limiter->registerFailedAttempt('1.1.1.1', 'Admin');
        $limiter->registerFailedAttempt('1.1.1.1', ' admin ');

        self::assertTrue($limiter->isLimited('1.1.1.1', 'admin'));
    }
}

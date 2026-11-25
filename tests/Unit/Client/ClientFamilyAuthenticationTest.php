<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Unit\Client;

use Nowo\TimeTrackBundle\Client\ClientFamilyAuthentication;
use Nowo\TimeTrackBundle\Client\ClientFamilyPrincipal;
use Nowo\TimeTrackBundle\Enum\ClientType;
use PHPUnit\Framework\TestCase;
use stdClass;

/**
 * Since 2.2.0: the framework-free answer of the family authenticator maps to one HTTP status and header set per error.
 */
final class ClientFamilyAuthenticationTest extends TestCase
{
    public function testEveryErrorHasItsStatusAndHeaders(): void
    {
        $cases = [
            ['invalid_token', 401, ['WWW-Authenticate' => 'Bearer error="invalid_token"']],
            ['insufficient_scope', 403, ['WWW-Authenticate' => 'Bearer error="insufficient_scope", scope="chat:write"']],
            ['context_forbidden', 403, []],
            ['context_required', 400, []],
            ['rate_limited', 429, ['Retry-After' => '12']],
        ];
        foreach ($cases as [$error, $status, $headers]) {
            $answer = ClientFamilyAuthentication::refused($error, 12, 'chat:write');
            self::assertFalse($answer->isAuthenticated());
            self::assertNull($answer->principal);
            self::assertSame($status, $answer->status(), $error);
            self::assertSame($headers, $answer->headers(), $error);
        }
    }

    public function testAnAuthenticatedAnswerCarriesThePrincipal(): void
    {
        $principal = new ClientFamilyPrincipal('7', new stdClass(), 'chat', ['chat:read'], 'tok', 'cred', ClientType::Desktop, 'dev', 'org:acme');
        $answer    = ClientFamilyAuthentication::authenticated($principal);

        self::assertTrue($answer->isAuthenticated());
        self::assertSame(200, $answer->status());
        self::assertSame([], $answer->headers());
        self::assertTrue($principal->hasScope('chat:read'));
        self::assertFalse($principal->hasScope('chat:write'));
    }
}

<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Unit\Client;

use Nowo\TimeTrackBundle\Client\ClientIdempotencyStore;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

/**
 * Since 2.1.0 (audit ZBQ-L5): {@see ClientIdempotencyStore::confirm()} with a final body replaces the pending body
 * (resolved after the commit) only for the request whose nonce stored it; without a body it keeps the pending one.
 */
final class ClientIdempotencyStoreConfirmTest extends TestCase
{
    public function testTheFinalBodyReplacesThePendingOneOfTheSameRequest(): void
    {
        $store = new ClientIdempotencyStore(new ArrayAdapter());
        $store->savePending('7', 'k', 'h', 201, ['entry' => ['subject' => ['hidden' => true]]], 'nonce-1');
        self::assertSame(['status' => 201, 'body' => ['entry' => ['subject' => ['hidden' => true]]], 'pending' => 'nonce-1'], $store->find('7', 'k', 'h'));

        $store->confirm('7', 'k', 'nonce-1', ['entry' => ['subject' => ['id' => 'c-1', 'label' => 'ACME']]]);

        self::assertSame(['status' => 201, 'body' => ['entry' => ['subject' => ['id' => 'c-1', 'label' => 'ACME']]]], $store->find('7', 'k', 'h'), 'committed, with the final body');
    }

    public function testWithoutABodyThePendingBodyIsKept(): void
    {
        $store = new ClientIdempotencyStore(new ArrayAdapter());
        $store->savePending('7', 'k', 'h', 201, ['entry' => ['id' => 'e-1']], 'nonce-1');

        $store->confirm('7', 'k', 'nonce-1');

        self::assertSame(['status' => 201, 'body' => ['entry' => ['id' => 'e-1']]], $store->find('7', 'k', 'h'));
    }

    public function testAnotherRequestsAnswerIsLeftAlone(): void
    {
        $store = new ClientIdempotencyStore(new ArrayAdapter());
        $store->savePending('7', 'k', 'h', 201, ['entry' => ['id' => 'e-2']], 'nonce-2');

        // A late confirmation of an earlier request (other nonce) changes neither the body nor the pending mark.
        $store->confirm('7', 'k', 'nonce-1', ['entry' => ['id' => 'e-1']]);
        self::assertSame(['status' => 201, 'body' => ['entry' => ['id' => 'e-2']], 'pending' => 'nonce-2'], $store->find('7', 'k', 'h'));

        // A committed answer has no pending mark: nothing to confirm.
        $store->save('7', 'done', 'h', 201, ['entry' => ['id' => 'e-3']]);
        $store->confirm('7', 'done', 'nonce-2', ['entry' => ['id' => 'other']]);
        self::assertSame(['status' => 201, 'body' => ['entry' => ['id' => 'e-3']]], $store->find('7', 'done', 'h'));

        // Nothing stored under the key: nothing is created.
        $store->confirm('7', 'missing', 'nonce-2', ['entry' => ['id' => 'other']]);
        self::assertNull($store->find('7', 'missing', 'h'));
    }
}

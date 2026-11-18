<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\EventSubscriber;

use Nowo\TimeTrackBundle\Client\ClientResponseFactory;
use Nowo\TransactionDoctrine\Symfony\Conflict\ConflictResponseContext;
use Nowo\TransactionDoctrine\Symfony\Conflict\ConflictResponseFormatterInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * 1.30.0: shapes the lock-conflict answer of nowo-tech/transaction-doctrine's responder for the bundle's own routes.
 *
 * A host that lists TimeTrack routes in `nowo_transaction_doctrine.controller_routes` gets the client API's usual error
 * body, `{"error": "unavailable", "message": …, "code": "CONFLICT_001|CONFLICT_002"}` (1.31.0: the code is additive), with its CORS headers and `Cache-Control: no-store`, instead of the
 * library's `{"error": "concurrency_conflict"}`. The status, `Retry-After` (none when the conflict already committed)
 * and `Cache-Control` stay the responder's. The bundle's routes are recognised the way {@see StorageFailureSubscriber}
 * does; the HTML pages and every other request fall through (`null`).
 *
 * Stateless.
 */
final readonly class StorageFailureConflictFormatter implements ConflictResponseFormatterInterface
{
    /**
     * @param ClientResponseFactory|null $responses The client API's JSON answers (CORS headers); a plain JSON body without it
     */
    public function __construct(private ?ClientResponseFactory $responses = null)
    {
    }

    public function format(Request $request, ConflictResponseContext $context): ?Response
    {
        if (!StorageFailureSubscriber::isBundleRequest($request) || !StorageFailureSubscriber::wantsJson($request)) {
            return null;
        }

        $response = $this->responses instanceof ClientResponseFactory
            ? $this->responses->error('unavailable', $context->message, $context->status, $request, [], ['code' => $context->code->value])
            : new JsonResponse(['error' => 'unavailable', 'message' => $context->message, 'code' => $context->code->value], $context->status);
        // @igor-ignore - Request-scoped value (the response of this exception); no worker state.
        $response->headers->set('X-Content-Type-Options', 'nosniff');

        return $response;
    }
}

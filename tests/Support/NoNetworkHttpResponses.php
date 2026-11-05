<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Support;

use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * `framework.http_client.mock_response_factory` of the functional kernel: no test ever reaches the network. Answers
 * 503 and remembers the URLs it was asked for.
 */
final class NoNetworkHttpResponses
{
    /** @var list<string> */
    public array $urls = [];

    public function __invoke(string $method, string $url): MockResponse
    {
        $this->urls[] = $url;

        return new MockResponse('', ['http_code' => 503]);
    }
}

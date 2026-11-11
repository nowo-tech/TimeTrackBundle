<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Support;

use Symfony\Component\HttpClient\Response\MockResponse;

use function is_string;

/**
 * `framework.http_client.mock_response_factory` of the functional kernel: no test ever reaches the network. Answers
 * 503 and remembers the URLs it was asked for (since 1.20 also the form bodies, for the OAuth app worker test).
 */
final class NoNetworkHttpResponses
{
    /** @var list<string> */
    public array $urls = [];

    /** @var list<string> */
    public array $bodies = [];

    /**
     * @param array<string, mixed> $options
     */
    public function __invoke(string $method, string $url, array $options = []): MockResponse
    {
        $this->urls[]   = $url;
        $body           = $options['body'] ?? '';
        $this->bodies[] = is_string($body) ? $body : '';

        return new MockResponse('', ['http_code' => 503]);
    }
}

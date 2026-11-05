<?php

declare(strict_types=1);

namespace ExeQue\LumberjackAgent;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\RequestOptions;
use Throwable;

/**
 * Talks to the Lumberjack server.
 */
class Sender
{
    public const int TIMEOUT = 5;

    public const int CONNECT_TIMEOUT = 2;

    private readonly ClientInterface $http;

    public function __construct(
        private readonly string $url,
        private readonly string $token,
        private readonly int|float $timeout = self::TIMEOUT,
        ?ClientInterface $http = null,
    ) {
        $this->http = $http ?? new Client();
    }

    public function send(string $gzippedBody): SendResult
    {
        try {
            $response = $this->http->request('POST', rtrim($this->url, '/').'/api/ingest', [
                ...$this->options(),
                RequestOptions::HEADERS => [...$this->headers(), 'Content-Type' => 'application/json', 'Content-Encoding' => 'gzip'],
                RequestOptions::BODY => $gzippedBody,
            ]);
        } catch (Throwable) {
            return SendResult::Retry;
        }

        $status = $response->getStatusCode();

        if ($status >= 200 && $status < 300) {
            return SendResult::Sent;
        }

        // A batch the server refuses as such will never succeed; anything else might.
        return $status >= 400 && $status < 500 && ! in_array($status, [401, 408, 429], true)
            ? SendResult::Rejected
            : SendResult::Retry;
    }

    /**
     * @param  array<string, array{name: string, version: string}>  $client  what the agent is, see AgentInfo
     * @param  array<string, mixed>  $status  e.g. queue sizes
     * @return array<mixed>|null the server's answer, e.g. the health states; null when it failed
     */
    public function heartbeat(string $host, array $client, ?string $name = null, array $status = []): ?array
    {
        try {
            $response = $this->http->request('POST', rtrim($this->url, '/').'/api/ingest/heartbeat', [
                ...$this->options(),
                RequestOptions::HEADERS => $this->headers(),
                // version too, for servers from before agents described themselves.
                RequestOptions::JSON => [...$status, ...array_filter([
                    'host' => $host,
                    'client' => $client,
                    'version' => $client['sdk']['version'] ?? $client['agent']['version'] ?? null,
                    'name' => $name,
                ])],
            ]);

            $status = $response->getStatusCode();

            if ($status < 200 || $status >= 300) {
                return null;
            }

            $body = json_decode((string) $response->getBody(), true);

            return is_array($body) ? $body : [];
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function options(): array
    {
        return [
            RequestOptions::TIMEOUT => $this->timeout,
            RequestOptions::CONNECT_TIMEOUT => min($this->timeout, self::CONNECT_TIMEOUT),
            // The status decides what happens to a batch, not an exception.
            RequestOptions::HTTP_ERRORS => false,
        ];
    }

    /**
     * @return array<string, string>
     */
    private function headers(): array
    {
        return ['Authorization' => 'Bearer '.$this->token, 'Accept' => 'application/json'];
    }
}

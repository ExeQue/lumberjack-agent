<?php

declare(strict_types=1);

namespace Tests\Support;

use Closure;
use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Stands in for the Lumberjack server: records what the agent sends and answers it,
 * by default accepting every batch and every heartbeat.
 */
final class FakeLumberjack
{
    /** @var list<RequestInterface> */
    public array $requests = [];

    /**
     * @param  (Closure(RequestInterface): ResponseInterface)|null  $respond
     */
    public function __construct(private ?Closure $respond = null)
    {
    }

    public function client(): Client
    {
        return new Client(['handler' => HandlerStack::create(function (RequestInterface $request): PromiseInterface {
            $this->requests[] = $request;

            return Create::promiseFor(($this->respond ?? self::accept(...))($request));
        })]);
    }

    /**
     * Each batch sent to /api/ingest, decoded.
     *
     * @return list<array<string, mixed>>
     */
    public function batches(): array
    {
        return array_values(array_map(
            fn (RequestInterface $request): array => (array) json_decode((string) gzdecode((string) $request->getBody()), true),
            array_filter($this->requests, fn (RequestInterface $request): bool => str_ends_with($request->getUri()->getPath(), '/api/ingest')),
        ));
    }

    /**
     * Each heartbeat, decoded.
     *
     * @return list<array<string, mixed>>
     */
    public function heartbeats(): array
    {
        return array_values(array_map(
            fn (RequestInterface $request): array => (array) json_decode((string) $request->getBody(), true),
            array_filter($this->requests, fn (RequestInterface $request): bool => str_ends_with($request->getUri()->getPath(), '/heartbeat')),
        ));
    }

    public static function accept(RequestInterface $request): ResponseInterface
    {
        return str_ends_with($request->getUri()->getPath(), '/heartbeat')
            ? new Response(200, [], '{"ok":true}')
            : new Response(202, [], '{"accepted":1}');
    }
}

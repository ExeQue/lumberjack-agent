<?php

declare(strict_types=1);

use ExeQue\LumberjackAgent\Sender;
use ExeQue\LumberjackAgent\SendResult;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Tests\Support\FakeLumberjack;

it('tells a batch to retry or to drop by what the server answers', function (int $status, SendResult $result) {
    $lumberjack = new FakeLumberjack(fn () => new Response($status));

    expect((new Sender('https://lumberjack.test', 'token', http: $lumberjack->client()))->send('body'))->toBe($result);
})->with([
    'accepted' => [202, SendResult::Sent],
    'refused as such' => [422, SendResult::Rejected],
    'too large' => [413, SendResult::Rejected],
    'wrong token, may be fixed' => [401, SendResult::Retry],
    'rate limited' => [429, SendResult::Retry],
    'timed out' => [408, SendResult::Retry],
    'server error' => [503, SendResult::Retry],
]);

it('retries when Lumberjack cannot be reached', function () {
    $lumberjack = new FakeLumberjack(fn ($request) => throw new ConnectException('refused', new Request('POST', '/')));

    expect((new Sender('https://lumberjack.test', 'token', http: $lumberjack->client()))->send('body'))->toBe(SendResult::Retry)
        ->and((new Sender('https://lumberjack.test', 'token', http: $lumberjack->client()))->heartbeat('web-1', []))->toBeNull();
});

it('posts to the address without a doubled slash', function () {
    $lumberjack = new FakeLumberjack();

    (new Sender('https://lumberjack.test/', 'token', http: $lumberjack->client()))->send('body');

    expect((string) $lumberjack->requests[0]->getUri())->toBe('https://lumberjack.test/api/ingest');
});

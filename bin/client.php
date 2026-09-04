<?php

declare(strict_types=1);

use App\Contract\Calculate\CalculateRequest;
use App\Protocol\Request;
use App\Sdk\WorkerPoolClient;

require __DIR__ . '/../vendor/autoload.php';

// Both ends of these calls share App\Contract\Calculate - that's what the
// namespace is for: the caller builds the very CalculateRequest the worker
// hydrates, so a wrong field name or type is a static error here rather
// than an invalid_payload at runtime, and the two can never drift apart.
//
// Sharing the class is a convenience of living in one codebase, not a
// requirement of the protocol: what actually crosses the socket is the JSON
// shape {a, b}, so a consumer in another codebase (or another language)
// only needs to match that.
$client = new WorkerPoolClient('/tmp/php-worker-pool.sock');

// send() writes each request out and returns straight away, so all three
// are in the pool at once - on a pool with spare workers they run in
// parallel instead of one after another. all() is where this process
// blocks, collecting the answers in the order asked for however they come
// back. Use call() instead when there's only one request to make.
$response1 = $client->send(new Request('calculate', new CalculateRequest(a: 10, b: 20)));
$response2 = $client->send(new Request('calculate', new CalculateRequest(a: 30, b: 40)));
$response3 = $client->send(new Request('calculate', new CalculateRequest(a: 50, b: 60)));

echo json_encode($client->all($response1, $response2, $response3)) . "\n";

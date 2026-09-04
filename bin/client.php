<?php

declare(strict_types=1);

use App\Contract\Calculate\CalculateRequest;
use App\Protocol\Request;
use App\Sdk\WorkerPoolClient;

require __DIR__ . '/../vendor/autoload.php';

// Both ends of this call share App\Contract\Calculate - that's what the
// namespace is for: the caller builds the very CalculateRequest the worker
// hydrates, so a wrong field name or type is a static error here rather
// than an invalid_payload at runtime, and the two can never drift apart.
//
// Sharing the class is a convenience of living in one codebase, not a
// requirement of the protocol: what actually crosses the socket is the JSON
// shape {a, b}, so a consumer in another codebase (or another language)
// only needs to match that.
$client = new WorkerPoolClient('/tmp/php-worker-pool.sock');

$response = $client->call(new Request('calculate', new CalculateRequest(a: 10, b: 20)));

echo json_encode($response) . "\n";

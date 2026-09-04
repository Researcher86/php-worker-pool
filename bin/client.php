<?php

use App\Protocol\Request;
use App\Sdk\WorkerPoolClient;

require __DIR__ . '/../vendor/autoload.php';

// A request DTO on the CALLER's side - deliberately not the server's own
// CalculateRequest: the two processes share the wire shape ({a, b}), not a
// class. Request turns any object into its params payload, so the call site
// stays typed without the client having to know how the worker
// deserializes it.
final readonly class Operands
{
    public function __construct(
        public int $a,
        public int $b,
    ) {
    }
}

$client = new WorkerPoolClient('/tmp/php-worker-pool.sock');

$response = $client->call(new Request('calculate', new Operands(a: 10, b: 20)));

echo json_encode($response) . "\n";

<?php

use App\Sdk\WorkerPoolClient;

require __DIR__ . '/../vendor/autoload.php';

$client = new WorkerPoolClient('/tmp/php-worker-pool.sock');

$response = $client->call('calculate', ['a' => 10, 'b' => 20]);

echo json_encode($response) . "\n";

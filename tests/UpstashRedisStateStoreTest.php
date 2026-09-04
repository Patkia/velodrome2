<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use App\Exceptions\StateStoreUnavailableException;
use App\Services\DefiLlamaPriceService;
use App\Services\PositionInitialValueService;
use App\Services\RpcService;
use App\Services\UpstashRedisStateStore;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;

function assertTrue(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$history = [];
$mock = new MockHandler([
    new Response(200, [], '{"result":"PONG"}'),
    new Response(200, [], '{"result":null}'),
    new Response(200, [], '{"result":"OK"}'),
    new Response(200, [], '{"result":1}'),
]);
$stack = HandlerStack::create($mock);
$stack->push(Middleware::history($history));
$store = new UpstashRedisStateStore(
    'https://upstash.example.test',
    'test-token',
    new Client(['handler' => $stack])
);

$store->assertAvailable();
assertTrue($store->exists('position') === false, 'GET null should mean state does not exist.');
$store->write('position', 'content');
$store->delete('position');

$expectedBodies = [
    '["PING"]',
    '["GET","velodrome2:position"]',
    '["SET","velodrome2:position","content"]',
    '["DEL","velodrome2:position"]',
];

foreach ($expectedBodies as $index => $expectedBody) {
    assertTrue(
        (string) $history[$index]['request']->getBody() === $expectedBody,
        'Unexpected Upstash command payload.'
    );
}

$failingStore = new UpstashRedisStateStore(
    'https://upstash.example.test',
    'test-token',
    new Client(['handler' => HandlerStack::create(new MockHandler([
        new Response(200, [], '{"error":"WRONGPASS"}'),
    ]))])
);

try {
    $failingStore->exists('position');
    throw new RuntimeException('State backend failures must throw.');
} catch (StateStoreUnavailableException) {
}

$cacheDirectory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'velodrome2-cache-test-' . bin2hex(random_bytes(4));
mkdir($cacheDirectory, 0700, true);
$cachePath = $cacheDirectory . DIRECTORY_SEPARATOR . '10-position.initial-value.json';
file_put_contents($cachePath, '{"initial_value":1}');
$cachelessService = new PositionInitialValueService(
    new RpcService(['rpc' => ['url' => 'https://rpc.example.test']]),
    new DefiLlamaPriceService(),
    $cacheDirectory,
    persistentCache: false
);
$cachelessService->removeMissingPositionCaches(10, []);
assertTrue(is_file($cachePath), 'Vercel cache-disabled mode must not delete persistent cache files.');
unlink($cachePath);
rmdir($cacheDirectory);

echo "UpstashRedisStateStoreTest: PASS\n";

<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use App\Services\CronHealthCheck;
use App\Services\UpstashRedisStateStore;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;

function assertCronHealthTrue(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$history = [];
$healthyStack = HandlerStack::create(new MockHandler([
    new Response(200, [], '{"result":"PONG"}'),
]));
$healthyStack->push(Middleware::history($history));
$healthyStore = new UpstashRedisStateStore(
    'https://upstash.example.test',
    'test-token',
    new Client(['handler' => $healthyStack])
);
$configValidated = false;
$healthyResult = (new CronHealthCheck(
    static function () use (&$configValidated): array {
        $configValidated = true;

        return [];
    },
    static function () use ($healthyStore): void {
        $healthyStore->assertAvailable();
    }
))->check();

assertCronHealthTrue($configValidated, 'Health mode must validate required configuration.');
assertCronHealthTrue($healthyResult['statusCode'] === 200, 'Healthy Upstash must return HTTP 200.');
assertCronHealthTrue($healthyResult['body'] === [
    'status' => 'ok',
    'runtimeMode' => 'vercel',
    'stateBackend' => 'upstash',
    'notificationsSent' => 0,
], 'Healthy response must not include monitor results or notifications.');
assertCronHealthTrue(count($history) === 1, 'Health mode must make exactly one Upstash request.');
assertCronHealthTrue(
    (string) $history[0]['request']->getBody() === '["PING"]',
    'Health mode must only use the non-mutating Upstash PING command.'
);

$unavailableStore = new UpstashRedisStateStore(
    'https://upstash.example.test',
    'test-token',
    new Client(['handler' => HandlerStack::create(new MockHandler([
        new Response(200, [], '{"error":"unavailable"}'),
    ]))])
);
$unavailableResult = (new CronHealthCheck(
    static fn (): array => [],
    static function () use ($unavailableStore): void {
        $unavailableStore->assertAvailable();
    }
))->check();

assertCronHealthTrue($unavailableResult['statusCode'] === 500, 'Unavailable Upstash must return HTTP 500.');
assertCronHealthTrue($unavailableResult['body']['status'] === 'error', 'Unavailable Upstash must return error status.');
assertCronHealthTrue(
    $unavailableResult['body']['notificationsSent'] === 0,
    'Unavailable Upstash must report zero notifications.'
);

$preflightCalled = false;
$invalidConfigResult = (new CronHealthCheck(
    static function (): array {
        throw new RuntimeException('Configuration is unavailable.');
    },
    static function () use (&$preflightCalled): void {
        $preflightCalled = true;
    }
))->check();

assertCronHealthTrue($invalidConfigResult['statusCode'] === 500, 'Invalid configuration must return HTTP 500.');
assertCronHealthTrue(
    $invalidConfigResult['body']['notificationsSent'] === 0,
    'Invalid configuration must report zero notifications.'
);
assertCronHealthTrue(!$preflightCalled, 'Upstash preflight must not run after configuration failure.');

$source = file_get_contents(__DIR__ . '/../src/Services/CronHealthCheck.php');
assertCronHealthTrue($source !== false, 'Unable to inspect health check source.');

foreach (['MonitorRunner', 'buildTelegramNotifier', '->exists(', '->write(', '->delete('] as $forbiddenCall) {
    assertCronHealthTrue(
        !str_contains($source, $forbiddenCall),
        'Health mode must not use ' . $forbiddenCall . '.'
    );
}

$endpointSource = file_get_contents(__DIR__ . '/../api/cron/monitor.php');
assertCronHealthTrue($endpointSource !== false, 'Unable to inspect cron endpoint source.');
$healthStart = strpos($endpointSource, "if ((\$_GET['health'] ?? null) === '1')");
$healthEnd = strpos($endpointSource, 'function buildTelegramNotifier(): callable');
assertCronHealthTrue(
    $healthStart !== false && $healthEnd !== false,
    'Unable to locate health branch in cron endpoint.'
);
$healthBranch = substr($endpointSource, $healthStart, $healthEnd - $healthStart);

foreach (['MonitorRunner', 'buildTelegramNotifier', 'DefiLlamaPriceService'] as $forbiddenDependency) {
    assertCronHealthTrue(
        !str_contains($healthBranch, $forbiddenDependency),
        'Health endpoint must not initialize ' . $forbiddenDependency . '.'
    );
}

echo "CronHealthCheckTest: PASS\n";

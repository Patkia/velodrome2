<?php

declare(strict_types=1);

function assertSiteEndpointTrue(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$endpointSource = file_get_contents(__DIR__ . '/../api/site/positions.php');
assertSiteEndpointTrue($endpointSource !== false, 'Unable to inspect Site positions endpoint.');

$allowedOrigin = 'https://velodrome2-sites-poc.patkia.chatgpt.site';
assertSiteEndpointTrue(str_contains($endpointSource, $allowedOrigin), 'Endpoint must use the exact Site origin.');
assertSiteEndpointTrue(str_contains($endpointSource, "header('Vary: Origin')"), 'Endpoint must vary by Origin.');
assertSiteEndpointTrue(
    str_contains($endpointSource, "header('Access-Control-Allow-Methods: GET, OPTIONS')"),
    'Endpoint must allow only GET and OPTIONS through CORS.'
);
assertSiteEndpointTrue(!str_contains($endpointSource, 'Access-Control-Allow-Origin: *'), 'Wildcard CORS is forbidden.');
assertSiteEndpointTrue(!str_contains($endpointSource, 'Access-Control-Allow-Credentials'), 'Credentialed CORS is forbidden.');
assertSiteEndpointTrue(str_contains($endpointSource, "header_remove('X-Powered-By')"), 'Runtime fingerprint header must be removed.');

assertSiteEndpointTrue(str_contains($endpointSource, "if (\$method === 'OPTIONS')"), 'OPTIONS handling is required.');
assertSiteEndpointTrue(str_contains($endpointSource, 'http_response_code(204)'), 'OPTIONS must return HTTP 204.');
assertSiteEndpointTrue(str_contains($endpointSource, "if (\$method !== 'GET')"), 'Non-GET methods must be rejected.');
assertSiteEndpointTrue(str_contains($endpointSource, 'siteJsonResponse(405'), 'Rejected methods must return HTTP 405.');
assertSiteEndpointTrue(str_contains($endpointSource, "header('Allow: GET, OPTIONS')"), '405 must advertise GET and OPTIONS.');

$runnerPattern = '/new MonitorRunner\(\s*\$config,\s*new DefiLlamaPriceService\(\),\s*null,\s*false,\s*null,\s*false\s*\)/s';
assertSiteEndpointTrue(
    preg_match($runnerPattern, $endpointSource) === 1,
    'MonitorRunner must hard-code null state/notifier and disabled notifications/persistent cache.'
);

foreach ([
    'UpstashRedisStateStore',
    'Telegram',
    'TransactionService',
    'WalletService',
    'eth_sendRawTransaction',
    'file_put_contents',
    'fopen(',
    'mkdir(',
    'unlink(',
    '$_GET',
] as $forbiddenWiring) {
    assertSiteEndpointTrue(
        !str_contains($endpointSource, $forbiddenWiring),
        'Endpoint must not contain forbidden wiring: ' . $forbiddenWiring
    );
}

assertSiteEndpointTrue(
    str_contains($endpointSource, 'Cache-Control: public, max-age=30, s-maxage=60, stale-while-revalidate=120'),
    'Successful responses must use bounded CDN caching.'
);
assertSiteEndpointTrue(str_contains($endpointSource, "catch (Throwable)"), 'Endpoint must sanitize unexpected failures.');
assertSiteEndpointTrue(!str_contains($endpointSource, 'getMessage('), 'Endpoint must not expose exception messages.');
assertSiteEndpointTrue(!str_contains($endpointSource, 'getBody('), 'Endpoint must not expose upstream response bodies.');

$vercelConfig = json_decode(
    (string) file_get_contents(__DIR__ . '/../vercel.json'),
    true,
    512,
    JSON_THROW_ON_ERROR
);
$matchingRoutes = array_values(array_filter(
    $vercelConfig['routes'] ?? [],
    static fn (array $route): bool => ($route['src'] ?? null) === '/api/site/positions'
        && ($route['dest'] ?? null) === '/api/site/positions.php'
));
assertSiteEndpointTrue(count($matchingRoutes) === 1, 'Vercel route must map exactly to the Site positions endpoint.');

$webMonitorSource = file_get_contents(__DIR__ . '/../api/web-monitor.php');
$cronSource = file_get_contents(__DIR__ . '/../api/cron/monitor.php');
assertSiteEndpointTrue($webMonitorSource !== false && $cronSource !== false, 'Unable to inspect legacy endpoints.');
$webMonitorPattern = '/new MonitorRunner\(\s*MonitorConfigFactory::fromEnvironment\(\),\s*new DefiLlamaPriceService\(\),\s*null,\s*false,\s*null,\s*false\s*\)/s';
assertSiteEndpointTrue(
    preg_match($webMonitorPattern, $webMonitorSource) === 1,
    'Web monitor read-only constructor wiring must remain unchanged.'
);
assertSiteEndpointTrue(
    str_contains($cronSource, 'new UpstashRedisStateStore()')
        && str_contains($cronSource, 'buildTelegramNotifier()'),
    'Cron production wiring must remain present.'
);

echo "SitePositionsEndpointSafetyTest: PASS\n";

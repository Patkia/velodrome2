<?php

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use App\Services\DefiLlamaPriceService;
use App\Services\MonitorConfigFactory;
use App\Services\MonitorRunner;
use App\Services\SitePositionsPresenter;

const SITE_ALLOWED_ORIGIN = 'https://velodrome2-sites-poc.patkia.chatgpt.site';

header_remove('X-Powered-By');

function siteJsonResponse(int $status, array $data, bool $cacheable = false): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header($cacheable
        ? 'Cache-Control: public, max-age=30, s-maxage=60, stale-while-revalidate=120'
        : 'Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

header('Vary: Origin');
header('Access-Control-Allow-Methods: GET, OPTIONS');

if (($_SERVER['HTTP_ORIGIN'] ?? '') === SITE_ALLOWED_ORIGIN) {
    header('Access-Control-Allow-Origin: ' . SITE_ALLOWED_ORIGIN);
}

$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

if ($method === 'OPTIONS') {
    http_response_code(204);
    exit;
}

$presenter = new SitePositionsPresenter();

if ($method !== 'GET') {
    header('Allow: GET, OPTIONS');
    siteJsonResponse(405, $presenter->error('METHOD_NOT_ALLOWED'));
}

try {
    $config = MonitorConfigFactory::fromEnvironment();
    $runner = new MonitorRunner(
        $config,
        new DefiLlamaPriceService(),
        null,
        false,
        null,
        false
    );
    $result = $runner->run();

    if (($result['positions'] ?? []) === [] && ($result['errors'] ?? []) !== []) {
        siteJsonResponse(503, $presenter->error('UPSTREAM_UNAVAILABLE'));
    }

    siteJsonResponse(
        200,
        $presenter->present($result, $config['wallet']['address']),
        true
    );
} catch (Throwable) {
    siteJsonResponse(503, $presenter->error('UPSTREAM_UNAVAILABLE'));
}

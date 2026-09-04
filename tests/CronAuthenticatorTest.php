<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use App\Services\CronAuthenticator;

function assertCronAuthTrue(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$authenticator = new CronAuthenticator();

$noAuthResult = $authenticator->validate('deterministic-test-secret', '');
assertCronAuthTrue($noAuthResult['statusCode'] === 401, 'Health request without auth must return HTTP 401.');
assertCronAuthTrue($noAuthResult['body']['alertsSent'] === 0, 'Unauthorized request must send zero alerts.');

$wrongAuthResult = $authenticator->validate('deterministic-test-secret', 'Bearer incorrect-test-secret');
assertCronAuthTrue($wrongAuthResult['statusCode'] === 401, 'Health request with wrong auth must return HTTP 401.');
assertCronAuthTrue($wrongAuthResult['body']['alertsSent'] === 0, 'Wrong-auth request must send zero alerts.');

$validAuthResult = $authenticator->validate('deterministic-test-secret', 'Bearer deterministic-test-secret');
assertCronAuthTrue($validAuthResult['statusCode'] === 200, 'Matching bearer token must authorize the request.');

$endpointSource = file_get_contents(__DIR__ . '/../api/cron/monitor.php');
assertCronAuthTrue($endpointSource !== false, 'Unable to inspect cron endpoint source.');
$authCallPosition = strpos($endpointSource, 'validateCronAuth();');
$healthBranchPosition = strpos($endpointSource, "if ((\$_GET['health'] ?? null) === '1')");
assertCronAuthTrue(
    $authCallPosition !== false && $healthBranchPosition !== false && $authCallPosition < $healthBranchPosition,
    'Cron authentication must run before the health branch.'
);

echo "CronAuthenticatorTest: PASS\n";

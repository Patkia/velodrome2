<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use App\Services\SitePositionsPresenter;

function assertSitePresenterTrue(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$walletAddress = '0x1234567890abcdef1234567890abcdef1234cdef';
$generatedAt = new DateTimeImmutable('2026-09-12T04:05:06+00:00');
$presenter = new SitePositionsPresenter();
$payload = $presenter->present([
    'positionsChecked' => 2,
    'errors' => ['Unable to monitor CELO.', 'sensitive upstream detail'],
    'positions' => [[
        'chain' => 'OPTIMISM',
        'positionId' => 123,
        'pool' => 'WETH/USDC',
        'initialValue' => '~$100.00',
        'currentValue' => '~$110.00',
        'profitLoss' => '+$10.00 (+10.00%)',
        'tokenLines' => ['0.03 WETH (~$90.00)', '20.00 USDC (~$20.00)'],
        'reward' => 'Reward 1.00 VELO (~$0.50)',
        'inRange' => true,
        'pair' => 'WETH/USDC',
        'status' => 'in-range',
        'tokens' => [[
            'symbol' => 'WETH',
            'amount' => '0.03',
            'value' => '~$90.00',
            'valueUsd' => 90.0,
        ]],
        'currentValueUsd' => 110.0,
        'initialValueUsd' => 100.0,
        'profitLossUsd' => 10.0,
        'profitLossPercent' => 10.0,
        'rewards' => 'Reward 1.00 VELO (~$0.50)',
        'rpcUrl' => 'https://must-not-leak.example.test',
    ]],
], $walletAddress, $generatedAt);

assertSitePresenterTrue($payload['schemaVersion'] === 1, 'Schema version must be 1.');
assertSitePresenterTrue($payload['status'] === 'partial', 'Unavailable chains must produce partial status.');
assertSitePresenterTrue($payload['generatedAt'] === '2026-09-12T04:05:06Z', 'Timestamp must be UTC ISO-8601.');
assertSitePresenterTrue($payload['walletAddress'] === '0x1234...cdef', 'Wallet address must be masked.');
assertSitePresenterTrue($payload['positionsChecked'] === 2, 'Position count must be preserved.');
assertSitePresenterTrue($payload['unavailableChains'] === ['Celo'], 'Only sanitized chain names may be exposed.');

$position = $payload['positions'][0];
$expectedPositionKeys = [
    'chain',
    'positionId',
    'pair',
    'status',
    'inRange',
    'tokens',
    'currentValue',
    'currentValueUsd',
    'initialValue',
    'initialValueUsd',
    'profitLoss',
    'profitLossUsd',
    'profitLossPercent',
    'rewards',
    'observedAt',
];
assertSitePresenterTrue(array_keys($position) === $expectedPositionKeys, 'Position schema keys are incorrect.');
assertSitePresenterTrue($position['chain'] === 'Optimism', 'Chain name must be display-safe.');
assertSitePresenterTrue($position['inRange'] === true, 'In-range state must be preserved.');
assertSitePresenterTrue($position['tokens'][0]['valueUsd'] === 90.0, 'Token numeric value must be preserved.');

$encodedPayload = json_encode($payload, JSON_THROW_ON_ERROR);
assertSitePresenterTrue(!str_contains($encodedPayload, $walletAddress), 'Full wallet address must not be exposed.');
assertSitePresenterTrue(!str_contains($encodedPayload, 'must-not-leak'), 'Unlisted configuration must not be exposed.');
assertSitePresenterTrue(!str_contains($encodedPayload, 'sensitive upstream detail'), 'Raw errors must not be exposed.');

$degraded = $presenter->present([
    'positionsChecked' => 1,
    'errors' => [],
    'positions' => [[
        'chain' => 'SONEIUM',
        'positionId' => 456,
        'pool' => 'WETH/ASTR',
        'inRange' => false,
        'currentValue' => 'Price unavailable',
        'initialValue' => 'unavailable',
        'profitLoss' => 'unavailable',
        'reward' => 'Reward unavailable',
        'tokens' => [],
        'currentValueUsd' => null,
        'initialValueUsd' => null,
        'profitLossUsd' => null,
        'profitLossPercent' => null,
    ]],
], $walletAddress, $generatedAt);
assertSitePresenterTrue($degraded['status'] === 'ok', 'Missing optional values must not fail the response.');
assertSitePresenterTrue($degraded['positions'][0]['initialValueUsd'] === null, 'Missing initial value must remain null.');
assertSitePresenterTrue($degraded['positions'][0]['status'] === 'out-of-range', 'Status must fall back from inRange.');

$errorPayload = $presenter->error('UPSTREAM_UNAVAILABLE');
assertSitePresenterTrue($errorPayload === [
    'schemaVersion' => 1,
    'status' => 'error',
    'error' => ['code' => 'UPSTREAM_UNAVAILABLE'],
], 'Error response must contain only the public error code.');

echo "SitePositionsPresenterTest: PASS\n";

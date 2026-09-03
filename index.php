<?php

declare(strict_types=1);

include __DIR__ . '/config/config_tg1.php';

require __DIR__ . '/vendor/autoload.php';

use App\Constants\Token;
use App\Contracts\ERC20Contract;
use App\Contracts\FactoryContract;
use App\Contracts\GaugeContract;
use App\Contracts\PoolContract;
use App\Contracts\PositionManagerContract;
use App\Services\ContractService;
use App\Services\DefiLlamaPriceService;
use App\Services\PositionInitialValueService;
use App\Services\RpcService;
use App\Utils\AbiDecoder;
use App\Utils\PositionValueCalculator;

$config = require __DIR__ . '/config/config.php';
$notificationsEnabled = filter_var(
    getenv('NOTIFICATIONS_ENABLED') ?: 'true',
    FILTER_VALIDATE_BOOL
);
$priceService = new DefiLlamaPriceService();

$chains = $config['chains'];

function stateKey(int $chainId, string $positionManagerAddress, int $positionId): string
{
    return sprintf('%d-%s-%d', $chainId, strtolower(substr($positionManagerAddress, 2)), $positionId);
}

function cleanupWithdrawnPositionStates(
    string $stateDirectory,
    int $chainId,
    array $stakedPositionKeys
): void
{
    foreach (glob($stateDirectory . DIRECTORY_SEPARATOR . $chainId . '-*.out-of-range') ?: [] as $stateFile) {
        $fileName = basename($stateFile);

        if (!preg_match('/^(\d+)-([a-f0-9]{40})-(\d+)\.out-of-range$/', $fileName, $matches)) {
            continue;
        }

        $key = $matches[1] . '-' . $matches[2] . '-' . $matches[3];

        if (!isset($stakedPositionKeys[$key])) {
            unlink($stateFile);
        }
    }

}

foreach ($chains as $chainName => $chain) {
    if (($chain['enabled'] ?? true) !== true) {
        continue;
    }

    $rpcService = new RpcService(['rpc' => $chain['rpc']]);
    $stateDirectory = __DIR__ . '/state';
    $initialValueService = isset($chain['position_history'])
        ? new PositionInitialValueService(
            $rpcService,
            $priceService,
            $stateDirectory . DIRECTORY_SEPARATOR . 'initial-values'
        )
        : null;

    if ($rpcService->getChainId() !== $chain['chain_id']) {
        throw new RuntimeException(sprintf('Unexpected chain ID for %s.', $chainName));
    }

    $contractService = new ContractService($rpcService);
    $erc20Contract = new ERC20Contract($rpcService);
    $poolContract = new PoolContract($contractService);
    $gaugeContract = new GaugeContract($contractService);
    $tokenDecimals = [];
    $stakedPositionKeys = [];

    $gauges = [];

    foreach ($chain['contracts']['gauges'] as $gaugeConfig) {
        $gaugeAddress = is_array($gaugeConfig) ? $gaugeConfig['address'] : $gaugeConfig;
        $gauges[] = [
            'address' => $gaugeAddress,
            'position_manager' => is_array($gaugeConfig)
                ? ($gaugeConfig['position_manager'] ?? $chain['contracts']['position_manager'])
                : $chain['contracts']['position_manager'],
            'factory' => is_array($gaugeConfig)
                ? ($gaugeConfig['factory'] ?? $chain['contracts']['factory'])
                : $chain['contracts']['factory'],
        ];
    }

    $stakedLengths = $gaugeContract->stakedLengths(
        array_column($gauges, 'address'),
        $config['wallet']['address']
    );

    foreach ($gauges as $gaugeIndex => $gauge) {
        $gaugeAddress = $gauge['address'];
        $positionManagerAddress = $gauge['position_manager'];
        $factoryAddress = $gauge['factory'];
        $positionManager = new PositionManagerContract($contractService, $positionManagerAddress);
        $factory = new FactoryContract($contractService, $factoryAddress);
        $count = $stakedLengths[$gaugeIndex];

        for ($index = 0; $index < $count; $index++) {
            $positionId = $gaugeContract->stakedByIndex(
                $gaugeAddress,
                $config['wallet']['address'],
                $index
            );
            $stakedPositionKeys[stateKey(
                $chain['chain_id'],
                $positionManagerAddress,
                $positionId
            )] = true;
            $position = $positionManager->positions($positionId);

            if (!$position['success']) {
                echo sprintf('[%s] Position %s: %s<br>', $chainName, $positionId, $position['message']);
                continue;
            }

            $pool = $factory->getPool(
                $position['token0'],
                $position['token1'],
                $position['tickSpacing']
            );

            if (!isset($pool['result']) || $pool['result'] === '0x') {
                echo sprintf('[%s] Position %s: pool not found<br>', $chainName, $positionId);
                continue;
            }

            $poolAddress = AbiDecoder::address(substr($pool['result'], 2));

            if ($poolAddress === '0x0000000000000000000000000000000000000000') {
                echo sprintf('[%s] Position %s: pool not found<br>', $chainName, $positionId);
                continue;
            }

            $slot0 = $poolContract->slot0($poolAddress);

            if (($slot0['success'] ?? false) !== true) {
                echo sprintf('[%s] Position %s: %s<br>', $chainName, $positionId, $slot0['message']);
                continue;
            }

            $currentTick = $slot0['tick'];
            $inRange = $currentTick >= $position['tickLower']
                && $currentTick <= $position['tickUpper'];
            $tokenPair = Token::symbol($position['token0']) . '/' . Token::symbol($position['token1']);
            $token0Address = strtolower($position['token0']);
            $token1Address = strtolower($position['token1']);
            $token0Symbol = Token::symbol($position['token0']);
            $token1Symbol = Token::symbol($position['token1']);
            $tokenLines = [];
            $rewardLine = null;

            try {
                $tokenDecimals[$token0Address] ??= $erc20Contract->decimals($position['token0']);
                $tokenDecimals[$token1Address] ??= $erc20Contract->decimals($position['token1']);
                $tokenAmounts = PositionValueCalculator::calculateTokenAmounts(
                    $position,
                    $slot0['sqrtPriceX96'],
                    $tokenDecimals[$token0Address],
                    $tokenDecimals[$token1Address]
                );
                $tokenPrices = $priceService->getPrices(
                    $chain['price_chain'],
                    [$position['token0'], $position['token1']]
                );
                $token0Price = $tokenPrices[$token0Address] ?? null;
                $token1Price = $tokenPrices[$token1Address] ?? null;
                $token0Value = $token0Price === null
                    ? null
                    : $tokenAmounts['token0'] * $token0Price;
                $token1Value = $token1Price === null
                    ? null
                    : $tokenAmounts['token1'] * $token1Price;
                $positionValue = $token0Price !== null && $token1Price !== null
                    ? $token0Value + $token1Value
                    : null;
                $formatAmount = static fn (float $amount, string $symbol): string => number_format(
                    $amount,
                    $symbol === 'WBTC' ? 8 : 2,
                    '.',
                    ','
                );
                $formatTokenLine = static fn (
                    float $amount,
                    string $symbol,
                    ?float $price
                ): string => $formatAmount($amount, $symbol)
                    . ' ' . $symbol
                    . ' (' . ($price === null ? 'Price unavailable' : '~$' . number_format($amount * $price, 2)) . ')';
                $tokenLines = [
                    $formatTokenLine($tokenAmounts['token0'], $token0Symbol, $token0Price),
                    $formatTokenLine($tokenAmounts['token1'], $token1Symbol, $token1Price),
                ];

                if ($token0Value !== null && $token1Value !== null && $token0Value !== $token1Value) {
                    $higherValueIndex = $token0Value > $token1Value ? 0 : 1;
                    $tokenLines[$higherValueIndex] = '<u>' . $tokenLines[$higherValueIndex] . '</u>';
                }
            } catch (Throwable) {
                $positionValue = null;
            }

            $valueText = $positionValue === null
                ? 'Price unavailable'
                : '~$' . number_format($positionValue, 2);
            $initialValue = $initialValueService === null
                || !isset($tokenDecimals[$token0Address], $tokenDecimals[$token1Address])
                ? null
                : $initialValueService->getInitialValue(
                    $chain['chain_id'],
                    $chain['position_history'],
                    $chain['price_chain'],
                    $positionManagerAddress,
                    $positionId,
                    $position['token0'],
                    $position['token1'],
                    $tokenDecimals[$token0Address],
                    $tokenDecimals[$token1Address]
                );
            $initialValueText = $initialValue === null
                ? 'unavailable'
                : '~$' . number_format($initialValue, 2);
            $profitLossText = $initialValue === null || $positionValue === null
                ? 'unavailable'
                : sprintf(
                    '%s$%s (%s%%)',
                    $positionValue >= $initialValue ? '+' : '-',
                    number_format(abs($positionValue - $initialValue), 2),
                    ($positionValue >= $initialValue ? '+' : '-')
                        . number_format(abs(($positionValue - $initialValue) / $initialValue * 100), 2)
                );

            try {
                $rewardTokenAddress = strtolower($gaugeContract->rewardToken($gaugeAddress));
                $rewardTokenSymbol = Token::symbol($rewardTokenAddress);
                $rewardDecimals = $erc20Contract->decimals($rewardTokenAddress);
                $earnedAmount = $gaugeContract->earned(
                    $gaugeAddress,
                    $config['wallet']['address'],
                    $positionId
                ) / (10 ** $rewardDecimals);
                $rewardPrices = $priceService->getPrices($chain['price_chain'], [$rewardTokenAddress]);
                $rewardPrice = $rewardPrices[$rewardTokenAddress] ?? null;

                if ($rewardPrice === null && $rewardTokenSymbol === 'VELO') {
                    $veloPrices = $priceService->getPrices('optimism', [Token::OPTIMISM_VELO]);
                    $rewardPrice = $veloPrices[Token::OPTIMISM_VELO] ?? null;
                }

                $rewardLine = 'Reward ' . number_format($earnedAmount, 2) . ' ' . $rewardTokenSymbol
                    . ' (' . ($rewardPrice === null
                        ? 'Price unavailable'
                        : '~$' . number_format($earnedAmount * $rewardPrice, 2)) . ')';
            } catch (Throwable) {
                $rewardLine = 'Reward unavailable';
            }

            echo sprintf('<h2>[%s] Position #%s</h2>', strtoupper($chainName), $positionId);
            echo '<b>Pool:</b> ' . $tokenPair . '<br><br>';
            echo '<b>Initial Value:</b> ' . $initialValueText . '<br>';
            echo '<b>Current Value:</b> ' . $valueText . '<br>';
            echo '<b>P/L:</b> ' . $profitLossText . '<br>';
            echo implode('<br>', $tokenLines) . '<br>';
            echo $rewardLine . '<br>';
            echo $inRange ? '<b>🟢 IN RANGE</b><hr>' : '<b>🔴 OUT OF RANGE</b><hr>';

            $stateFile = $stateDirectory . DIRECTORY_SEPARATOR
                . stateKey($chain['chain_id'], $positionManagerAddress, $positionId)
                . '.out-of-range';

            if (!$inRange && !file_exists($stateFile) && $notificationsEnabled) {
                if (!is_dir($stateDirectory) && !mkdir($stateDirectory, 0700, true) && !is_dir($stateDirectory)) {
                    throw new RuntimeException('Unable to create notification state directory.');
                }

                sendNotify2(sprintf(
                    "Out of range: [%s] %s%s%sCurrent Value: %s%s%s%s%s%s",
                    strtoupper($chainName),
                    $tokenPair,
                    PHP_EOL,
                    'Initial Value: ' . $initialValueText . PHP_EOL,
                    $valueText,
                    PHP_EOL,
                    'P/L: ' . $profitLossText . PHP_EOL,
                    implode(PHP_EOL, $tokenLines),
                    PHP_EOL,
                    $rewardLine
                ));
                file_put_contents($stateFile, sprintf('pair=%s%sgauge=%s%s', $tokenPair, PHP_EOL, $gaugeAddress, PHP_EOL));
            }

            if ($inRange && file_exists($stateFile)) {
                unlink($stateFile);
            }
        }
    }

    if (is_dir($stateDirectory)) {
        cleanupWithdrawnPositionStates(
            $stateDirectory,
            $chain['chain_id'],
            $stakedPositionKeys
        );

        $initialValueService?->removeMissingPositionCaches(
            $chain['chain_id'],
            $stakedPositionKeys
        );
    }
}

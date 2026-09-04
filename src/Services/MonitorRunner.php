<?php

declare(strict_types=1);

namespace App\Services;

use App\Constants\Token;
use App\Contracts\ERC20Contract;
use App\Contracts\FactoryContract;
use App\Contracts\GaugeContract;
use App\Contracts\PoolContract;
use App\Contracts\PositionManagerContract;
use App\Contracts\StateStoreInterface;
use App\Exceptions\StateStoreUnavailableException;
use App\Utils\AbiDecoder;
use App\Utils\PositionValueCalculator;

class MonitorRunner
{
    public function __construct(
        private array $config,
        private DefiLlamaPriceService $priceService,
        private ?StateStoreInterface $stateStore = null,
        private bool $notificationsEnabled = false,
        private mixed $notifier = null,
        private bool $persistentInitialValueCache = false
    ) {
    }

    public function run(): array
    {
        $result = [
            'positionsChecked' => 0,
            'alertsSent' => 0,
            'errors' => [],
            'positions' => [],
        ];
        $stateKeysToDelete = [];
        $pendingAlerts = [];

        foreach ($this->config['chains'] as $chainName => $chain) {
            if (($chain['enabled'] ?? true) !== true) {
                continue;
            }

            try {
                $this->monitorChain($chainName, $chain, $result, $stateKeysToDelete, $pendingAlerts);
            } catch (StateStoreUnavailableException $exception) {
                throw $exception;
            } catch (\Throwable) {
                $result['errors'][] = sprintf('Unable to monitor %s.', strtoupper($chainName));
            }
        }

        if ($result['errors'] !== []) {
            return $result;
        }

        $this->applyStateChangesAndSendAlerts($stateKeysToDelete, $pendingAlerts, $result);

        return $result;
    }

    private function monitorChain(
        string $chainName,
        array $chain,
        array &$result,
        array &$stateKeysToDelete,
        array &$pendingAlerts
    ): void {
        $rpcService = new RpcService(['rpc' => $chain['rpc']]);
        $initialValueService = isset($chain['position_history'])
            ? new PositionInitialValueService(
                $rpcService,
                $this->priceService,
                sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'velodrome2-initial-values',
                persistentCache: $this->persistentInitialValueCache
            )
            : null;

        if ($rpcService->getChainId() !== $chain['chain_id']) {
            throw new \RuntimeException('Unexpected chain ID.');
        }

        $contractService = new ContractService($rpcService);
        $erc20Contract = new ERC20Contract($rpcService);
        $poolContract = new PoolContract($contractService);
        $gaugeContract = new GaugeContract($contractService);
        $tokenDecimals = [];
        $gauges = $this->normalizeGauges($chain);
        $stakedLengths = $gaugeContract->stakedLengths(
            array_column($gauges, 'address'),
            $this->config['wallet']['address']
        );

        foreach ($gauges as $gaugeIndex => $gauge) {
            $positionManager = new PositionManagerContract($contractService, $gauge['position_manager']);
            $factory = new FactoryContract($contractService, $gauge['factory']);

            for ($index = 0; $index < $stakedLengths[$gaugeIndex]; $index++) {
                $positionId = $gaugeContract->stakedByIndex(
                    $gauge['address'],
                    $this->config['wallet']['address'],
                    $index
                );
                $result['positionsChecked']++;

                $position = $positionManager->positions($positionId);

                if (($position['success'] ?? false) !== true) {
                    continue;
                }

                $pool = $factory->getPool(
                    $position['token0'],
                    $position['token1'],
                    $position['tickSpacing']
                );

                if (!isset($pool['result']) || $pool['result'] === '0x') {
                    continue;
                }

                $poolAddress = AbiDecoder::address(substr($pool['result'], 2));

                if ($poolAddress === '0x0000000000000000000000000000000000000000') {
                    continue;
                }

                $slot0 = $poolContract->slot0($poolAddress);

                if (($slot0['success'] ?? false) !== true) {
                    continue;
                }

                $this->monitorPosition(
                    $chainName,
                    $chain,
                    $gauge,
                    $positionId,
                    $position,
                    $slot0,
                    $erc20Contract,
                    $gaugeContract,
                    $tokenDecimals,
                    $initialValueService,
                    $result,
                    $stateKeysToDelete,
                    $pendingAlerts
                );
            }
        }
    }

    private function monitorPosition(
        string $chainName,
        array $chain,
        array $gauge,
        int $positionId,
        array $position,
        array $slot0,
        ERC20Contract $erc20Contract,
        GaugeContract $gaugeContract,
        array &$tokenDecimals,
        ?PositionInitialValueService $initialValueService,
        array &$result,
        array &$stateKeysToDelete,
        array &$pendingAlerts
    ): void {
        $inRange = $slot0['tick'] >= $position['tickLower'] && $slot0['tick'] <= $position['tickUpper'];
        $tokenPair = Token::symbol($position['token0']) . '/' . Token::symbol($position['token1']);
        $token0Address = strtolower($position['token0']);
        $token1Address = strtolower($position['token1']);
        $token0Symbol = Token::symbol($position['token0']);
        $token1Symbol = Token::symbol($position['token1']);
        $tokenLines = [];
        $positionValue = null;

        try {
            $tokenDecimals[$token0Address] ??= $erc20Contract->decimals($position['token0']);
            $tokenDecimals[$token1Address] ??= $erc20Contract->decimals($position['token1']);
            $tokenAmounts = PositionValueCalculator::calculateTokenAmounts(
                $position,
                $slot0['sqrtPriceX96'],
                $tokenDecimals[$token0Address],
                $tokenDecimals[$token1Address]
            );
            $tokenPrices = $this->priceService->getPrices($chain['price_chain'], [$position['token0'], $position['token1']]);
            $token0Price = $tokenPrices[$token0Address] ?? null;
            $token1Price = $tokenPrices[$token1Address] ?? null;
            $token0Value = $token0Price === null ? null : $tokenAmounts['token0'] * $token0Price;
            $token1Value = $token1Price === null ? null : $tokenAmounts['token1'] * $token1Price;
            $positionValue = $token0Value !== null && $token1Value !== null ? $token0Value + $token1Value : null;
            $tokenLines = [
                $this->formatTokenLine($tokenAmounts['token0'], $token0Symbol, $token0Price),
                $this->formatTokenLine($tokenAmounts['token1'], $token1Symbol, $token1Price),
            ];
        } catch (\Throwable) {
            $token0Price = null;
            $token1Price = null;
        }

        $initialValue = $initialValueService === null
            || !isset($tokenDecimals[$token0Address], $tokenDecimals[$token1Address])
            ? null
            : $initialValueService->getInitialValue(
                $chain['chain_id'],
                $chain['position_history'],
                $chain['price_chain'],
                $gauge['position_manager'],
                $positionId,
                $position['token0'],
                $position['token1'],
                $tokenDecimals[$token0Address],
                $tokenDecimals[$token1Address]
            );
        $valueText = $positionValue === null ? 'Price unavailable' : '~$' . number_format($positionValue, 2);
        $initialValueText = $initialValue === null ? 'unavailable' : '~$' . number_format($initialValue, 2);
        $profitLossText = $initialValue === null || $positionValue === null
            ? 'unavailable'
            : sprintf(
                '%s$%s (%s%%)',
                $positionValue >= $initialValue ? '+' : '-',
                number_format(abs($positionValue - $initialValue), 2),
                ($positionValue >= $initialValue ? '+' : '-')
                    . number_format(abs(($positionValue - $initialValue) / $initialValue * 100), 2)
            );
        $rewardLine = $this->getRewardLine($gaugeContract, $erc20Contract, $gauge, $positionId, $chain['price_chain']);

        $result['positions'][] = [
            'chain' => strtoupper($chainName),
            'positionId' => $positionId,
            'pool' => $tokenPair,
            'initialValue' => $initialValueText,
            'currentValue' => $valueText,
            'profitLoss' => $profitLossText,
            'tokenLines' => $tokenLines,
            'reward' => $rewardLine,
            'inRange' => $inRange,
        ];

        if ($this->stateStore === null) {
            return;
        }

        $stateKey = self::stateKey($chain['chain_id'], $gauge['position_manager'], $positionId) . '.out-of-range';

        if ($inRange && $this->stateStore->exists($stateKey)) {
            $stateKeysToDelete[] = $stateKey;

            return;
        }

        if (!$inRange && $this->notificationsEnabled && !$this->stateStore->exists($stateKey)) {
            $pendingAlerts[] = [
                'key' => $stateKey,
                'content' => sprintf('pair=%s%sgauge=%s', $tokenPair, PHP_EOL, $gauge['address']),
                'message' => sprintf(
                    "Out of range: [%s] %s%sInitial Value: %s%sCurrent Value: %s%sP/L: %s%s%s%s%s",
                    strtoupper($chainName),
                    $tokenPair,
                    PHP_EOL,
                    $initialValueText,
                    PHP_EOL,
                    $valueText,
                    PHP_EOL,
                    $profitLossText,
                    PHP_EOL,
                    implode(PHP_EOL, $tokenLines),
                    PHP_EOL,
                    $rewardLine
                ),
            ];
        }
    }

    private function applyStateChangesAndSendAlerts(array $stateKeysToDelete, array $pendingAlerts, array &$result): void
    {
        if ($this->stateStore === null) {
            return;
        }

        foreach (array_unique($stateKeysToDelete) as $stateKey) {
            $this->stateStore->delete($stateKey);
        }

        foreach ($pendingAlerts as $alert) {
            $this->stateStore->write($alert['key'], $alert['content']);
        }

        foreach ($pendingAlerts as $alert) {
            try {
                ($this->notifier)($alert['message']);
                $result['alertsSent']++;
            } catch (\Throwable $exception) {
                $this->stateStore->delete($alert['key']);

                throw new \RuntimeException('Telegram notification failed.', 0, $exception);
            }
        }
    }

    private function normalizeGauges(array $chain): array
    {
        $gauges = [];

        foreach ($chain['contracts']['gauges'] as $gaugeConfig) {
            $gauges[] = [
                'address' => is_array($gaugeConfig) ? $gaugeConfig['address'] : $gaugeConfig,
                'position_manager' => is_array($gaugeConfig)
                    ? ($gaugeConfig['position_manager'] ?? $chain['contracts']['position_manager'])
                    : $chain['contracts']['position_manager'],
                'factory' => is_array($gaugeConfig)
                    ? ($gaugeConfig['factory'] ?? $chain['contracts']['factory'])
                    : $chain['contracts']['factory'],
            ];
        }

        return $gauges;
    }

    private function getRewardLine(
        GaugeContract $gaugeContract,
        ERC20Contract $erc20Contract,
        array $gauge,
        int $positionId,
        string $priceChain
    ): string {
        try {
            $rewardTokenAddress = strtolower($gaugeContract->rewardToken($gauge['address']));
            $rewardTokenSymbol = Token::symbol($rewardTokenAddress);
            $rewardDecimals = $erc20Contract->decimals($rewardTokenAddress);
            $earnedAmount = $gaugeContract->earned(
                $gauge['address'],
                $this->config['wallet']['address'],
                $positionId
            ) / (10 ** $rewardDecimals);
            $rewardPrices = $this->priceService->getPrices($priceChain, [$rewardTokenAddress]);
            $rewardPrice = $rewardPrices[$rewardTokenAddress] ?? null;

            if ($rewardPrice === null && $rewardTokenSymbol === 'VELO') {
                $veloPrices = $this->priceService->getPrices('optimism', [Token::OPTIMISM_VELO]);
                $rewardPrice = $veloPrices[Token::OPTIMISM_VELO] ?? null;
            }

            return 'Reward ' . number_format($earnedAmount, 2) . ' ' . $rewardTokenSymbol
                . ' (' . ($rewardPrice === null ? 'Price unavailable' : '~$' . number_format($earnedAmount * $rewardPrice, 2)) . ')';
        } catch (\Throwable) {
            return 'Reward unavailable';
        }
    }

    private function formatTokenLine(float $amount, string $symbol, ?float $price): string
    {
        $precision = $symbol === 'WBTC' ? 8 : 2;

        return number_format($amount, $precision, '.', ',') . ' ' . $symbol
            . ' (' . ($price === null ? 'Price unavailable' : '~$' . number_format($amount * $price, 2)) . ')';
    }

    private static function stateKey(int $chainId, string $positionManagerAddress, int $positionId): string
    {
        return sprintf('%d-%s-%d', $chainId, strtolower(substr($positionManagerAddress, 2)), $positionId);
    }
}

<?php

declare(strict_types=1);

/**
 * Vercel Cron Endpoint for Velodrome Monitor
 * 
 * Requires CRON_SECRET in Authorization header:
 * Authorization: Bearer <CRON_SECRET>
 */

declare(strict_types=1);

require_once __DIR__ . '/../../config/config_tg1.php';
require_once __DIR__ . '/../../vendor/autoload.php';

use App\Contracts\StateStoreInterface;
use App\Constants\Token;
use App\Contracts\ERC20Contract;
use App\Contracts\FactoryContract;
use App\Contracts\GaugeContract;
use App\Contracts\PoolContract;
use App\Contracts\PositionManagerContract;
use App\Services\ContractService;
use App\Services\DefiLlamaPriceService;
use App\Services\LocalFileStateStore;
use App\Services\UpstashRedisStateStore;
use App\Services\PositionInitialValueService;
use App\Services\RpcService;
use App\Utils\AbiDecoder;
use App\Utils\PositionValueCalculator;

// CORS headers for Vercel
header('Content-Type: application/json');

/**
 * Send JSON response and exit.
 */
function jsonResponse(int $status, array $data): never
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_SLASHES);
    exit;
}

/**
 * Validate CRON_SECRET header.
 */
function validateCronAuth(): void
{
    $expectedSecret = getenv('CRON_SECRET');
    
    if ($expectedSecret === false || $expectedSecret === '') {
        jsonResponse(500, [
            'status' => 'error',
            'error' => 'CRON_SECRET environment variable not configured',
        ]);
    }
    
    $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    
    if (!str_starts_with($authHeader, 'Bearer ')) {
        jsonResponse(401, [
            'status' => 'error',
            'error' => 'Missing Authorization header',
        ]);
    }
    
    $providedToken = substr($authHeader, 7);
    
    // Use hash_equals for constant-time comparison
    if (!hash_equals($expectedSecret, $providedToken)) {
        jsonResponse(401, [
            'status' => 'error',
            'error' => 'Invalid authorization token',
        ]);
    }
}

/**
 * Create appropriate StateStore based on runtime mode.
 */
function createStateStore(string $runtimeMode, string $stateDirectory): StateStoreInterface
{
    if ($runtimeMode === 'vercel') {
        return new UpstashRedisStateStore();
    }
    return new LocalFileStateStore($stateDirectory);
}

/**
 * Run monitor and return results.
 */
function runMonitor(string $stateDirectory, StateStoreInterface $stateStore): array
{
    $config = require __DIR__ . '/../../config/config.php';
    $notificationsEnabled = filter_var(
        getenv('NOTIFICATIONS_ENABLED') ?: 'true',
        FILTER_VALIDATE_BOOL
    );
    $priceService = new DefiLlamaPriceService();
    
    $result = [
        'positionsChecked' => 0,
        'alertsSent' => 0,
        'errors' => [],
    ];
    
    function stateKey(int $chainId, string $positionManagerAddress, int $positionId): string
    {
        return sprintf('%d-%s-%d', $chainId, strtolower(substr($positionManagerAddress, 2)), $positionId);
    }
    
    foreach ($config['chains'] as $chainName => $chain) {
        if (($chain['enabled'] ?? true) !== true) {
            continue;
        }
        
        try {
            $rpcService = new RpcService(['rpc' => $chain['rpc']]);
            $initialValueService = isset($chain['position_history'])
                ? new PositionInitialValueService(
                    $rpcService,
                    $priceService,
                    $stateDirectory . '/initial-values'
                )
                : null;
            
            if ($rpcService->getChainId() !== $chain['chain_id']) {
                $result['errors'][] = "Chain ID mismatch for $chainName";
                continue;
            }
            
            $contractService = new ContractService($rpcService);
            $erc20Contract = new ERC20Contract($rpcService);
            $poolContract = new PoolContract($contractService);
            $gaugeContract = new GaugeContract($contractService);
            $tokenDecimals = [];
            
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
                $positionManager = new PositionManagerContract($contractService, $gauge['position_manager']);
                $factory = new FactoryContract($contractService, $gauge['factory']);
                $count = $stakedLengths[$gaugeIndex];
                
                for ($index = 0; $index < $count; $index++) {
                    $positionId = $gaugeContract->stakedByIndex(
                        $gauge['address'],
                        $config['wallet']['address'],
                        $index
                    );
                    $result['positionsChecked']++;
                    
                    $position = $positionManager->positions($positionId);
                    
                    if (!$position['success']) {
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
                    
                    $currentTick = $slot0['tick'];
                    $inRange = $currentTick >= $position['tickLower']
                        && $currentTick <= $position['tickUpper'];
                    $tokenPair = Token::symbol($position['token0']) . '/' . Token::symbol($position['token1']);
                    $token0Address = strtolower($position['token0']);
                    $token1Address = strtolower($position['token1']);
                    
                    // Calculate position value
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
                        $positionValue = ($token0Price !== null && $token1Price !== null)
                            ? ($tokenAmounts['token0'] * $token0Price) + ($tokenAmounts['token1'] * $token1Price)
                            : null;
                    } catch (Throwable) {
                        $positionValue = null;
                    }
                    
                    // Get initial value
                    $initialValue = ($initialValueService !== null && isset($tokenDecimals[$token0Address], $tokenDecimals[$token1Address]))
                        ? $initialValueService->getInitialValue(
                            $chain['chain_id'],
                            $chain['position_history'],
                            $chain['price_chain'],
                            $gauge['position_manager'],
                            $positionId,
                            $position['token0'],
                            $position['token1'],
                            $tokenDecimals[$token0Address],
                            $tokenDecimals[$token1Address]
                        )
                        : null;
                    
                    // Get reward
                    try {
                        $rewardTokenAddress = strtolower($gaugeContract->rewardToken($gauge['address']));
                        $rewardDecimals = $erc20Contract->decimals($rewardTokenAddress);
                        $earnedAmount = $gaugeContract->earned(
                            $gauge['address'],
                            $config['wallet']['address'],
                            $positionId
                        ) / (10 ** $rewardDecimals);
                        $rewardPrices = $priceService->getPrices($chain['price_chain'], [$rewardTokenAddress]);
                        $rewardPrice = $rewardPrices[$rewardTokenAddress] ?? null;
                    } catch (Throwable) {
                        $earnedAmount = 0;
                        $rewardPrice = null;
                    }
                    
                    $stateKey = stateKey($chain['chain_id'], $gauge['position_manager'], $positionId) . '.out-of-range';
                    
                    // Send notification if out of range and not already notified
                    if (!$inRange && !$stateStore->exists($stateKey) && $notificationsEnabled) {
                        $valueText = $positionValue === null ? 'Price unavailable' : '~$' . number_format($positionValue, 2);
                        $initialValueText = $initialValue === null ? 'unavailable' : '~$' . number_format($initialValue, 2);
                        $rewardLine = 'Reward ' . number_format($earnedAmount, 2) . ' ' . Token::symbol($rewardTokenAddress)
                            . ' (' . ($rewardPrice === null ? 'Price unavailable' : '~$' . number_format($earnedAmount * $rewardPrice, 2)) . ')';
                        
                        sendNotify2(sprintf(
                            "Out of range: [%s] %s%s%sCurrent Value: %s%s%s%s",
                            strtoupper($chainName),
                            $tokenPair,
                            PHP_EOL,
                            'Initial Value: ' . $initialValueText . PHP_EOL,
                            $valueText,
                            PHP_EOL,
                            $rewardLine
                        ));
                        
                        $stateStore->write($stateKey, sprintf('pair=%s%sgauge=%s', $tokenPair, PHP_EOL, $gauge['address']));
                        $result['alertsSent']++;
                    }
                    
                    // Clear state when back in range
                    if ($inRange && $stateStore->exists($stateKey)) {
                        $stateStore->delete($stateKey);
                    }
                }
            }
        } catch (Throwable $e) {
            $result['errors'][] = $e->getMessage();
        }
    }
    
    return $result;
}

// Main execution
validateCronAuth();

$runtimeMode = getenv('RUNTIME_MODE') ?: 'server';
$stateDirectory = __DIR__ . '/../../state';
$stateStore = createStateStore($runtimeMode, $stateDirectory);

try {
    $result = runMonitor($stateDirectory, $stateStore);
    
    jsonResponse(200, [
        'status' => 'success',
        'positionsChecked' => $result['positionsChecked'],
        'alertsSent' => $result['alertsSent'],
        'runtimeMode' => $runtimeMode,
        'stateBackend' => $runtimeMode === 'vercel' ? 'upstash' : 'local',
        'errors' => $result['errors'],
    ]);
} catch (Throwable $e) {
    jsonResponse(500, [
        'status' => 'error',
        'error' => 'Monitor execution failed: ' . $e->getMessage(),
        'runtimeMode' => $runtimeMode,
        'stateBackend' => $runtimeMode === 'vercel' ? 'upstash' : 'local',
    ]);
}

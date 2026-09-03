<?php

declare(strict_types=1);

namespace App\Services;

use GuzzleHttp\ClientInterface;

class PositionInitialValueService
{
    private const INCREASE_LIQUIDITY_EVENT_TOPIC = '0x3067048beee31b25b2f1681f88dac838c8bba36af25bfb2b7cf7473a5847e35f';
    private const INITIAL_VALUE_ATTEMPTS = 3;
    private const ZERO_ADDRESS = '0x0000000000000000000000000000000000000000';

    private array $values = [];

    public function __construct(
        private RpcService $rpc,
        private DefiLlamaPriceService $priceService,
        private string $cacheDirectory,
        private ?ClientInterface $client = null
    ) {
    }

    private function client(): ClientInterface
    {
        return $this->client ??= HttpClientFactory::create(['timeout' => 15]);
    }

    public function getInitialValue(
        int $chainId,
        array $historySource,
        string $priceChain,
        string $positionManager,
        int $positionId,
        string $token0,
        string $token1,
        int $token0Decimals,
        int $token1Decimals
    ): ?float {
        $key = sprintf('%d-%s-%d', $chainId, strtolower(substr($positionManager, 2)), $positionId);

        if (array_key_exists($key, $this->values)) {
            return $this->values[$key];
        }

        $cachedValue = $this->readCachedValue($key);

        if ($cachedValue !== null) {
            return $this->values[$key] = $cachedValue;
        }

        for ($attempt = 1; $attempt <= self::INITIAL_VALUE_ATTEMPTS; $attempt++) {
            try {
                $initialValue = $this->resolveInitialValue(
                    $historySource,
                    $priceChain,
                    $positionManager,
                    $positionId,
                    $token0,
                    $token1,
                    $token0Decimals,
                    $token1Decimals
                );

                if ($initialValue !== null) {
                    try {
                        $this->writeCachedValue($key, $initialValue);
                    } catch (\Throwable) {
                    }

                    return $this->values[$key] = $initialValue;
                }
            } catch (\Throwable) {
            }

            if ($attempt < self::INITIAL_VALUE_ATTEMPTS) {
                usleep(250000);
            }
        }

        return $this->values[$key] = null;
    }

    public function removeMissingPositionCaches(int $chainId, array $stakedPositionKeys): void
    {
        foreach (glob($this->cacheDirectory . DIRECTORY_SEPARATOR . $chainId . '-*.initial-value.json') ?: [] as $path) {
            $key = basename($path, '.initial-value.json');

            if (!isset($stakedPositionKeys[$key])) {
                unlink($path);
            }
        }
    }

    private function resolveInitialValue(
        array $historySource,
        string $priceChain,
        string $positionManager,
        int $positionId,
        string $token0,
        string $token1,
        int $token0Decimals,
        int $token1Decimals
    ): ?float {
        $mintTransactionHash = $this->getMintTransactionHash(
            $historySource,
            $positionManager,
            $positionId
        );

        if ($mintTransactionHash === null) {
            return null;
        }

        $receipt = $this->rpc->call('eth_getTransactionReceipt', [$mintTransactionHash]);
        $receiptResult = $receipt['result'] ?? null;

        if (!is_array($receiptResult) || !isset($receiptResult['blockNumber'], $receiptResult['logs'])) {
            return null;
        }

        $amounts = $this->getInitialAmounts($receiptResult['logs'], $positionManager, $positionId);

        if ($amounts === null) {
            return null;
        }

        $block = $this->rpc->call('eth_getBlockByNumber', [$receiptResult['blockNumber'], false]);
        $timestampHex = $block['result']['timestamp'] ?? null;

        if (!is_string($timestampHex)) {
            return null;
        }

        $prices = $this->priceService->getHistoricalPrices(
            $priceChain,
            hexdec($timestampHex),
            [$token0, $token1]
        );
        $token0Price = $prices[strtolower($token0)] ?? null;
        $token1Price = $prices[strtolower($token1)] ?? null;

        if ($token0Price === null || $token1Price === null) {
            return null;
        }

        $initialValue = ($amounts['token0'] / (10 ** $token0Decimals) * $token0Price)
            + ($amounts['token1'] / (10 ** $token1Decimals) * $token1Price);

        return $initialValue > 0 ? $initialValue : null;
    }

    private function getMintTransactionHash(
        array $historySource,
        string $positionManager,
        int $positionId
    ): ?string {
        if (($historySource['type'] ?? null) === 'alchemy') {
            return $this->getMintTransactionHashFromAlchemy($positionManager, $positionId);
        }

        if (($historySource['type'] ?? null) !== 'blockscout'
            || !isset($historySource['url'])
        ) {
            return null;
        }

        $response = $this->client()->get(sprintf(
            '%s/api/v2/tokens/%s/instances/%d/transfers',
            rtrim($historySource['url'], '/'),
            $positionManager,
            $positionId
        ));
        $data = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);

        foreach ($data['items'] ?? [] as $transfer) {
            if (strtolower($transfer['from']['hash'] ?? '') === self::ZERO_ADDRESS
                && isset($transfer['transaction_hash'])
            ) {
                return $transfer['transaction_hash'];
            }
        }

        return null;
    }

    private function getMintTransactionHashFromAlchemy(
        string $positionManager,
        int $positionId
    ): ?string {
        $result = $this->rpc->call('alchemy_getAssetTransfers', [[
            'fromBlock' => '0x0',
            'fromAddress' => self::ZERO_ADDRESS,
            'contractAddresses' => [$positionManager],
            'category' => ['erc721'],
            'erc721TokenId' => '0x' . dechex($positionId),
            'withMetadata' => false,
            'maxCount' => '0x1',
        ]]);

        return $result['result']['transfers'][0]['hash'] ?? null;
    }

    private function getInitialAmounts(array $logs, string $positionManager, int $positionId): ?array
    {
        $tokenIdTopic = '0x' . str_pad(dechex($positionId), 64, '0', STR_PAD_LEFT);

        foreach ($logs as $log) {
            if (strtolower($log['address'] ?? '') !== strtolower($positionManager)
                || strtolower($log['topics'][0] ?? '') !== self::INCREASE_LIQUIDITY_EVENT_TOPIC
                || strtolower($log['topics'][1] ?? '') !== $tokenIdTopic
            ) {
                continue;
            }

            $data = substr($log['data'] ?? '', 2);

            if (strlen($data) !== 192) {
                return null;
            }

            return [
                'token0' => hexdec(substr($data, 64, 64)),
                'token1' => hexdec(substr($data, 128, 64)),
            ];
        }

        return null;
    }

    private function readCachedValue(string $key): ?float
    {
        $path = $this->cachePath($key);

        if (!is_file($path)) {
            return null;
        }

        try {
            $data = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
            $value = $data['initial_value'] ?? null;

            return is_numeric($value) && (float) $value > 0 ? (float) $value : null;
        } catch (\Throwable) {
            return null;
        }
    }

    private function writeCachedValue(string $key, float $value): void
    {
        if (!is_dir($this->cacheDirectory)
            && !mkdir($this->cacheDirectory, 0700, true)
            && !is_dir($this->cacheDirectory)
        ) {
            throw new \RuntimeException('Unable to create initial value cache directory.');
        }

        file_put_contents(
            $this->cachePath($key),
            json_encode(['initial_value' => $value], JSON_THROW_ON_ERROR),
            LOCK_EX
        );
    }

    private function cachePath(string $key): string
    {
        return $this->cacheDirectory . DIRECTORY_SEPARATOR . $key . '.initial-value.json';
    }
}

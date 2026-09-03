<?php

declare(strict_types=1);

namespace App\Contracts;

use App\Services\ContractService;
use App\Utils\AbiDecoder;

class GaugeContract
{
    public function __construct(
        private ContractService $contract
    ) {
    }

    public function stakedLength(
        string $gauge,
        string $wallet
    ): int {

        $data = $this->contract->call(
            $gauge,
            '0xae775c32'
            . str_pad(
                substr(strtolower($wallet), 2),
                64,
                '0',
                STR_PAD_LEFT
            )
        );

        return $this->decodeResult($data);
    }

    public function stakedByIndex(
        string $gauge,
        string $wallet,
        int $index
    ): int {

        $data = $this->contract->call(
            $gauge,
            '0x38463937'
            . str_pad(
                substr(strtolower($wallet), 2),
                64,
                '0',
                STR_PAD_LEFT
            )
            . str_pad(
                dechex($index),
                64,
                '0',
                STR_PAD_LEFT
            )
        );

        return $this->decodeResult($data);
    }

    public function stakedLengths(array $gauges, string $wallet): array
    {
        $data = '0xae775c32' . str_pad(
            substr(strtolower($wallet), 2),
            64,
            '0',
            STR_PAD_LEFT
        );
        $responses = $this->contract->callMany(array_map(
            fn (string $gauge): array => ['contract' => $gauge, 'data' => $data],
            $gauges
        ));

        return array_map(fn (array $response): int => $this->decodeResult($response), $responses);
    }

    public function earned(string $gauge, string $wallet, int $tokenId): float
    {
        $data = $this->contract->call(
            $gauge,
            '0x3e491d47'
            . str_pad(substr(strtolower($wallet), 2), 64, '0', STR_PAD_LEFT)
            . str_pad(dechex($tokenId), 64, '0', STR_PAD_LEFT)
        );

        return $this->decodeUnsignedValue($data);
    }

    public function rewardToken(string $gauge): string
    {
        $data = $this->contract->call($gauge, '0xf7c618c1');

        if (isset($data['error']) || !isset($data['result']) || $data['result'] === '0x') {
            throw new \RuntimeException('Unable to read reward token from gauge.');
        }

        return AbiDecoder::address(substr($data['result'], 2));
    }

    public function withdrawData(
        int $tokenId
    ): string {

        return
            '0x2e1a7d4d'
            . str_pad(
                dechex($tokenId),
                64,
                '0',
                STR_PAD_LEFT
            );
    }

    private function decodeResult(array $data): int
    {
        if (isset($data['error']) || !isset($data['result']) || $data['result'] === '0x') {
            throw new \RuntimeException('Unable to read staked position data from gauge.');
        }

        return (int) $this->decodeUnsignedValue($data);
    }

    private function decodeUnsignedValue(array $data): float
    {
        if (isset($data['error']) || !isset($data['result']) || $data['result'] === '0x') {
            throw new \RuntimeException('Unable to read unsigned value from gauge.');
        }

        return hexdec($data['result']);
    }
}

<?php

declare(strict_types=1);

namespace App\Contracts;

use App\Services\ContractService;
use App\Utils\AbiDecoder;

class PoolContract
{
    public function __construct(
        private ContractService $contract
    ) {
    }

    public function slot0(string $pool): array
    {
        $data = $this->contract->call(
            $pool,
            '0x3850c7bd'
        );

        // RPC Error
        if (isset($data['error'])) {
            return [
                'success' => false,
                'message' => $data['error']['message'],
            ];
        }

        // ไม่มีข้อมูล
        if (
            !isset($data['result'])
            || $data['result'] === '0x'
        ) {
            return [
                'success' => false,
                'message' => 'slot0 not found',
            ];
        }

        $chunks = AbiDecoder::split($data['result']);

        return [
            'success'      => true,
            'sqrtPriceX96' => $chunks[0],
            'tick'         => AbiDecoder::int24($chunks[1]),
            'protocolFee'  => hexdec($chunks[2]),
            'lpFee'        => hexdec($chunks[3]),
            'unlocked'     => (bool) hexdec($chunks[5]),
        ];
    }

    public function token1(string $pool): array
    {
        return $this->contract->call(
            $pool,
            '0xd21220a7'
        );
    }
}

<?php

declare(strict_types=1);

namespace App\Contracts;

use App\Services\ContractService;
use App\Utils\AbiDecoder;

class PositionManagerContract
{
    public function __construct(
        private ContractService $contract,
        private string $positionManagerAddress,
    ) {
    }

    public function ownerOf(int $tokenId): array
    {
        return $this->contract->call(
            $this->positionManagerAddress,
            '0x6352211e'
            . str_pad(
                dechex($tokenId),
                64,
                '0',
                STR_PAD_LEFT
            )
        );
    }

    public function positions(int $tokenId): array
    {
        $data = $this->contract->call(
            $this->positionManagerAddress,
            '0x99fbab88'
            . str_pad(
                dechex($tokenId),
                64,
                '0',
                STR_PAD_LEFT
            )

        );

        if (isset($data['error'])) {
            return [
                'success' => false,
                'message' => $data['error']['message'],
            ];
        }

        $chunks = AbiDecoder::split($data['result']);

        return [
            'success'      => true,
            'nonce'        => hexdec($chunks[0]),
            'operator'     => AbiDecoder::address($chunks[1]),
            'token0'       => AbiDecoder::address($chunks[2]),
            'token1'       => AbiDecoder::address($chunks[3]),
            'tickSpacing'  => AbiDecoder::int24($chunks[4]),
            'tickLower'    => AbiDecoder::int24($chunks[5]),
            'tickUpper'    => AbiDecoder::int24($chunks[6]),
            'liquidity'    => hexdec($chunks[7]),

            // เดี๋ยวค่อยเพิ่ม
            // liquidity
            // feeGrowth
            // tokensOwed
        ];
    }
}

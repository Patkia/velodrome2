<?php

declare(strict_types=1);

namespace App\Contracts;

use App\Services\ContractService;

class FactoryContract
{
    public function __construct(
        private ContractService $contract,
        private string $factoryAddress
    ) {
    }

    public function getPool(
        string $token0,
        string $token1,
        int $tickSpacing
    ): array
    {
        $data =
            '0x28af8d0b' // getPool(address,address,int24)
            . str_pad(substr(strtolower($token0), 2), 64, '0', STR_PAD_LEFT)
            . str_pad(substr(strtolower($token1), 2), 64, '0', STR_PAD_LEFT)
            . str_pad(dechex($tickSpacing), 64, '0', STR_PAD_LEFT);

        return $this->contract->call(
            $this->factoryAddress,
            $data
        );
    }
}

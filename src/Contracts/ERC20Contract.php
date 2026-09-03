<?php

declare(strict_types=1);

namespace App\Contracts;

use App\Services\RpcService;

class ERC20Contract
{
    public function __construct(
        private RpcService $rpc
    ) {
    }

    public function symbol(string $contractAddress): array
    {
        return $this->rpc->callContract(
            $contractAddress,
            '0x95d89b41'
        );
    }

    public function decimals(string $contractAddress): int
    {
        $data = $this->rpc->callContract($contractAddress, '0x313ce567');

        if (!isset($data['result']) || $data['result'] === '0x') {
            throw new \RuntimeException('Unable to read token decimals.');
        }

        return hexdec($data['result']);
    }
}

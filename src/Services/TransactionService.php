<?php

declare(strict_types=1);

namespace App\Services;

class TransactionService
{
    public function __construct(
        private RpcService $rpc,
        private array $config,
    ) {
    }

    public function nonce(): int
    {
        $result = $this->rpc->call(
            'eth_getTransactionCount',
            [
                $this->config['wallet']['address'],
                'pending'
            ]
        );

        return hexdec($result['result']);
    }

    public function gasPrice(): string
    {
        $result = $this->rpc->call(
            'eth_gasPrice',
            []
        );

        return $result['result'];
    }
}
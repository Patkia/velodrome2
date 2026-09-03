<?php

declare(strict_types=1);

namespace App\Services;

class ContractService
{
    public function __construct(
        private RpcService $rpc
    ) {
    }

    public function call(
        string $contract,
        string $data,
        string $block = 'latest'
    ): array
    {
        return $this->rpc->callContract(
            $contract,
            $data,
            $block
        );
    }

    public function callMany(array $calls, string $block = 'latest'): array
    {
        return $this->rpc->callMany(array_map(
            fn (array $call): array => [
                'method' => 'eth_call',
                'params' => [[
                    'to' => $call['contract'],
                    'data' => $call['data'],
                ], $block],
            ],
            $calls
        ));
    }

    public function estimateGas(
        string $from,
        string $to,
        string $data
    ): array {

        return $this->rpc->call(
            'eth_estimateGas',
            [[
                'from' => $from,
                'to'   => $to,
                'data' => $data,
            ]]
        );
    }

    public function sendRawTransaction(string $raw): array
    {
        return $this->rpc->call(
            'eth_sendRawTransaction',
            [$raw]
        );
    }
}

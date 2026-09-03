<?php

declare(strict_types=1);

namespace App\Services;

// use App\Contracts\ERC20Contract;
use App\Services\RpcService;
use App\Services\ContractService;
use App\Constants\Contract;

class WalletService
{
    private ContractService $contract;

    public function __construct(ContractService $contract)
    {
        $this->contract = $contract;
    }
        
    private function encodeBalanceOf(string $wallet): string
    {
        return '0x70a08231'
            . str_pad(
                strtolower(ltrim($wallet, '0x')),
                64,
                '0',
                STR_PAD_LEFT
            );
    }

    public function getTokenBalance(
        string $contract,
        string $wallet
    ): string
    {
        $data = $this->contract->call(
            $contract,
            $this->encodeBalanceOf($wallet)
        );

        if (!isset($data['result'])) {
            throw new \RuntimeException('RPC response does not contain result.');
        }

        return (string) hexdec($data['result']);
    }
        
    public function getPositionCount(string $wallet): string
    {
        $data = $this->contract->call(
            Contract::POSITION_MANAGER_V2,
            $this->encodeBalanceOf($wallet)
        );

        if (!isset($data['result'])) {
            throw new \RuntimeException('RPC response does not contain result.');
        }

        return (string) hexdec($data['result']);
    }

    private function encodeTokenOfOwnerByIndex(
        string $wallet,
        int $index
    ): string
    {
        return '0x2f745c59'
            . str_pad(
                strtolower(ltrim($wallet, '0x')),
                64,
                '0',
                STR_PAD_LEFT
            )
            . str_pad(
                dechex($index),
                64,
                '0',
                STR_PAD_LEFT
            );
    }

    public function getPositionId(
        string $wallet,
        int $index
    ): string
    {
        $data = $this->contract->call(
            Contract::POSITION_MANAGER_V2,
            $this->encodeTokenOfOwnerByIndex($wallet, $index)
        );

        if (!isset($data['result'])) {
            throw new \RuntimeException('RPC response does not contain result.');
        }

        return (string) hexdec($data['result']);
    }

    public function getPositionIds(string $wallet): array
    {
        $count = (int) $this->getPositionCount($wallet);

        $positions = [];

        for ($i = 0; $i < $count; $i++) {
            $positions[] = $this->getPositionId($wallet, $i);
        }

        return $positions;
    }

    // public function getEthBalance(string $wallet): string
    // {
    //     $data = $this->rpc->call('eth_getBalance', [
    //         $wallet,
    //         'latest'
    //     ]);

    //     if (!isset($data['result'])) {
    //         throw new \RuntimeException('RPC response does not contain result.');
    //     }

    //     return $data['result'];
    // }
}
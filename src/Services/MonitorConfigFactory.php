<?php

declare(strict_types=1);

namespace App\Services;

use App\Constants\Contract;

class MonitorConfigFactory
{
    public static function fromEnvironment(): array
    {
        $walletAddress = self::requiredEnvironmentVariable('WALLET_ADDRESS');
        $optimismRpcUrl = self::requiredEnvironmentVariable('OPTIMISM_RPC_URL');

        return [
            'wallet' => [
                'address' => $walletAddress,
            ],
            'chains' => [
                'optimism' => [
                    'chain_id' => 10,
                    'price_chain' => 'optimism',
                    'position_history' => [
                        'type' => 'blockscout',
                        'url' => 'https://optimism.blockscout.com',
                    ],
                    'rpc' => [
                        'url' => $optimismRpcUrl,
                    ],
                    'contracts' => [
                        'position_manager' => Contract::POSITION_MANAGER_V1,
                        'factory' => Contract::CL_FACTORY_V1,
                        'gauges' => Contract::GAUGES,
                    ],
                ],
                'celo' => [
                    'chain_id' => Contract::CELO_CHAIN_ID,
                    'price_chain' => 'celo',
                    'position_history' => [
                        'type' => 'blockscout',
                        'url' => 'https://celo.blockscout.com',
                    ],
                    'rpc' => [
                        'url' => 'https://forno.celo.org',
                    ],
                    'contracts' => [
                        'position_manager' => Contract::CELO_POSITION_MANAGER,
                        'factory' => Contract::CELO_CL_FACTORY,
                        'gauges' => Contract::CELO_GAUGES,
                    ],
                ],
                'soneium' => [
                    'chain_id' => Contract::SONEIUM_CHAIN_ID,
                    'price_chain' => 'soneium',
                    'position_history' => [
                        'type' => 'blockscout',
                        'url' => 'https://soneium.blockscout.com',
                    ],
                    'rpc' => [
                        'url' => 'https://rpc.soneium.org',
                    ],
                    'contracts' => [
                        'position_manager' => Contract::SONEIUM_POSITION_MANAGER,
                        'factory' => Contract::SONEIUM_CL_FACTORY,
                        'gauges' => Contract::SONEIUM_GAUGES,
                    ],
                ],
                'ink' => [
                    'enabled' => false,
                    'chain_id' => Contract::INK_CHAIN_ID,
                    'price_chain' => 'ink',
                    'position_history' => [
                        'type' => 'blockscout',
                        'url' => 'https://explorer.inkonchain.com',
                    ],
                    'rpc' => [
                        'url' => 'https://rpc-gel.inkonchain.com',
                    ],
                    'contracts' => [
                        'position_manager' => Contract::INK_POSITION_MANAGER,
                        'factory' => Contract::INK_CL_FACTORY,
                        'gauges' => Contract::INK_GAUGES,
                    ],
                ],
            ],
        ];
    }

    private static function requiredEnvironmentVariable(string $name): string
    {
        $value = getenv($name);

        if ($value === false || $value === '') {
            throw new \RuntimeException($name . ' environment variable is required.');
        }

        return $value;
    }
}

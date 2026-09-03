<?php

declare(strict_types=1);

namespace App\Constants;

class Contract
{
    public const USDC = '0x0b2c639c533813f4aa9d7837caf62653d097ff85';

    public const POSITION_MANAGER_V1 = '0xf7f8ccce99ca2896ec75d3a399d152db96808399';
    public const POSITION_MANAGER_V2 = '0x416b433906b1B72FA758e166e239c43d68dC6F29';
    
    public const CL_FACTORY_V1 = '0xe13Dd1fbA721Aa81a1826D9523AC9BC7d260c879';
    public const CL_FACTORY_V2 = '0xCc0bDDB707055e04e497aB22a59c2aF4391cd12F';

    public const CELO_CHAIN_ID = 42220;
    public const CELO_POSITION_MANAGER = '0x991d5546C4B442B4c5fdc4c8B8b8d131DEB24702';
    public const CELO_CL_FACTORY = '0x04625B046C69577EfC40e6c0Bb83CDBAfab5a55F';

    public const CELO_GAUGES = [
        'CELO/USDC' => '0xff5ec01b541cab692676ac3150d452b3c7fc404d',
        'CELO/USDT' => '0x93c77b19cb0024d1d1c10236ad4552f805a27703',
        'USDT/WETH' => '0x695eaddc1ffa57c95a8148ead292537a92c718e4',
        'CELO/USDm' => '0x9f536e26a6d152543362ed8d15545c11d5970fd1',
        'CELO/WETH' => '0x50854a1b57a0238ba2aa5341a8a03fde027bf75d',
        'USDm/USDC' => '0x6e754393eeb7c5c52b5dbf442e29b70bb009d4d8',
        'USDT/USDC' => '0xe9c37ee5c55bf37cd852dfbfd0b76e9a52d6796d',
    ];

    public const SONEIUM_CHAIN_ID = 1868;
    public const SONEIUM_POSITION_MANAGER = '0x991d5546C4B442B4c5fdc4c8B8b8d131DEB24702';
    public const SONEIUM_CL_FACTORY = '0x04625B046C69577EfC40e6c0Bb83CDBAfab5a55F';

    public const SONEIUM_GAUGES = [
        'ASTR/WETH' => '0x10a2bd31da8582231ba355ec7a6d9c2f06932a77',
        'ASTR/USDC.e' => '0xf7b979caf782dd3456e4d0f4ec185dd7207b44e9',
    ];

    public const INK_CHAIN_ID = 57073;
    public const INK_POSITION_MANAGER = '0x991d5546C4B442B4c5fdc4c8B8b8d131DEB24702';
    public const INK_CL_FACTORY = '0x04625B046C69577EfC40e6c0Bb83CDBAfab5a55F';

    public const INK_GAUGES = [
        'USDC/WETH' => '0x2974573e77ef1948e21b365e0ddaeb654fbf7ef6',
        'USDT0/kBTC' => '0x118adc9ae7d2c802de7209592a1388c9a3eb26d2',
        'USDT0/USDC.e' => '0x2c568357e5e4beee207ab46b5ba5c1196d0d5ecf',
        'USDT0/WETH' => '0x3eaa211d25c04a992a79b57c32d6f307332b187e',
    ];

    public const GAUGES = [
        'USDC/OP' => '0x65759f7f8bc7c1aac4fa57099e6f7a7a1da9b407',
        'WETH/OP' => '0xb2Ba81A92768a980a72d4900A377Ea2fcA7B2394',
        'USDC/WETH' => '0x656C74cB96B612072D01bEc52172AbB2e41E4321',
        'WETH/USDT' => '0xfb3dF761042b957B375aa417336997272F4B32cC',
        'WETH/OP CL50 V2' => [
            'address' => '0x7888c54b5ce4909c485f477a9631fadf60d8ac5b',
            'position_manager' => self::POSITION_MANAGER_V2,
            'factory' => self::CL_FACTORY_V2,
        ],
        'USDC/VELO CL200' => [
            'address' => '0xc8c7b5ae61d97be7d02d606629059487066dc9cf',
            'position_manager' => self::POSITION_MANAGER_V2,
            'factory' => self::CL_FACTORY_V2,
        ],
        'USDC/WBTC CL50 V1' => '0xa18911b77e905602b7cb3824c3712cbb7e1a3534',
        'USDC/WBTC CL100 V1' => '0xa6d2a82e14774916574dcca3ac92b33b1b64552b',
        'USDC/WBTC CL100 V2' => [
            'address' => '0x71794455ddfa1c17dd62310d6de0bb1f14c69699',
            'position_manager' => self::POSITION_MANAGER_V2,
            'factory' => self::CL_FACTORY_V2,
        ],
    ];
}

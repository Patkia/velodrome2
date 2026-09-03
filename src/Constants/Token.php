<?php

declare(strict_types=1);

namespace App\Constants;

class Token
{
    public const OPTIMISM_VELO = '0x9560e827af36c94d2ac33a39bce1fe78631088db';

    public const SYMBOLS = [

        // Optimism
        '0x4200000000000000000000000000000000000042' => 'OP',
        '0x0b2c639c533813f4aa9d7837caf62653d097ff85' => 'USDC',
        '0x4200000000000000000000000000000000000006' => 'WETH',
        '0x9bcef72be871e61ed4fbbc7630889bee758eb81d' => 'rETH',
        '0x1217bf6e6773eec6cc4a38b5dc45b92292b6e189' => 'MAI',
        '0x94b008aa00579c1307b0ef2c499ad98a8ce58e58' => 'USDT',
        '0x9560e827af36c94d2ac33a39bce1fe78631088db' => 'VELO',
        '0x68f180fcce6836688e9084f035309e29bf0a2095' => 'WBTC',

        // Celo
        '0x471ece3750da237f93b8e339c536989b8978a438' => 'CELO',
        '0xceba9300f2b948710d2653dd7b07f33a8b32118c' => 'USDC',
        '0x48065fbbe25f71c9282ddf5e1cd6d6a887483d5e' => 'USDT',
        '0x765de816845861e75a25fca122bb6898b8b1282a' => 'USDm',
        '0xd221812de1bd094f35587ee8e174b07b6167d9af' => 'WETH',
        '0x7f9adfbd38b669f03d1d11000bc76b9aaea28a81' => 'VELO',

        // Soneium
        '0x2cae934a1e84f693fbb78ca5ed3b0a6893259441' => 'ASTR',
        '0xba9986d2381edf1da03b0b9c1f8b00dc4aacc369' => 'USDC.e',

        // Ink
        '0x2d270e6886d130d724215a266106e6832161eaed' => 'USDC',
        '0x0200c29006150606b650577bbe7b6248f58470c1' => 'USDT0',
        '0x73e0c0d45e048d25fc26fa3159b0aa04bfa4db98' => 'kBTC',
        '0xf1815bd50389c46847f0bda824ec8da914045d14' => 'USDC.e',
    ];

    public static function symbol(string $address): string
    {
        return self::SYMBOLS[strtolower($address)] ?? $address;
    }
}

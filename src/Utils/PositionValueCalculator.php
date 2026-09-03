<?php

declare(strict_types=1);

namespace App\Utils;

class PositionValueCalculator
{
    public static function calculateTokenAmounts(
        array $position,
        string $sqrtPriceX96,
        int $token0Decimals,
        int $token1Decimals
    ): array {
        $sqrtCurrent = hexdec($sqrtPriceX96) / (2 ** 96);
        $sqrtLower = 1.0001 ** ($position['tickLower'] / 2);
        $sqrtUpper = 1.0001 ** ($position['tickUpper'] / 2);
        $liquidity = (float) $position['liquidity'];
        $amount0 = 0.0;
        $amount1 = 0.0;

        if ($sqrtCurrent <= $sqrtLower) {
            $amount0 = $liquidity * ($sqrtUpper - $sqrtLower) / ($sqrtLower * $sqrtUpper);
        } elseif ($sqrtCurrent < $sqrtUpper) {
            $amount0 = $liquidity * ($sqrtUpper - $sqrtCurrent) / ($sqrtCurrent * $sqrtUpper);
            $amount1 = $liquidity * ($sqrtCurrent - $sqrtLower);
        } else {
            $amount1 = $liquidity * ($sqrtUpper - $sqrtLower);
        }

        return [
            'token0' => $amount0 / (10 ** $token0Decimals),
            'token1' => $amount1 / (10 ** $token1Decimals),
        ];
    }
}

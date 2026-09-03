<?php

declare(strict_types=1);

namespace App\Utils;

class TokenFormatter
{
    public static function format(
        string $raw,
        int $decimals
    ): string
    {
        $number = number_format(
            ((float)$raw) / (10 ** $decimals),
            $decimals,
            '.',
            ''
        );

        return rtrim(rtrim($number, '0'), '.');
    }
}
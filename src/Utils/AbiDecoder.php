<?php

declare(strict_types=1);

namespace App\Utils;

class AbiDecoder
{
    public static function split(string $hex): array
    {
        return str_split(
            substr($hex, 2),
            64
        );
    }

    public static function address(string $hex): string
    {
        return '0x' . substr($hex, 24);
    }

    public static function uint(string $hex): string
    {
        return ltrim(hexdec($hex), '0');
    }

    public static function int24(string $hex): int
    {
        // เอาแค่ 24 บิตท้าย (6 hex)
        $value = hexdec(substr($hex, -6));

        // ถ้า bit 23 เป็น 1 แสดงว่าเป็นเลขติดลบ
        if ($value & 0x800000) {
            $value -= 0x1000000;
        }

        return $value;
    }
}
<?php

declare(strict_types=1);

namespace App\Services;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Handler\StreamHandler;
use GuzzleHttp\HandlerStack;

class HttpClientFactory
{
    private static bool $useStreamHandler = false;

    public static function create(array $config): ClientInterface
    {
        if (self::$useStreamHandler) {
            $config['handler'] = HandlerStack::create(new StreamHandler());
        }

        return new Client($config);
    }

    public static function useStreamHandler(): void
    {
        self::$useStreamHandler = true;
    }

    public static function usesStreamHandler(): bool
    {
        return self::$useStreamHandler;
    }
}

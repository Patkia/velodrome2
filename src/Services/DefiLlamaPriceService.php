<?php

declare(strict_types=1);

namespace App\Services;

use GuzzleHttp\ClientInterface;

class DefiLlamaPriceService
{
    private array $prices = [];

    public function __construct(
        private ?ClientInterface $client = null
    ) {
    }

    private function client(): ClientInterface
    {
        return $this->client ??= HttpClientFactory::create(['timeout' => 15]);
    }

    public function getPrices(string $chain, array $tokenAddresses): array
    {
        $missingAddresses = array_filter(
            $tokenAddresses,
            fn (string $address): bool => !isset($this->prices[$chain][$chain . ':' . strtolower($address)])
        );

        if ($missingAddresses !== []) {
            $coins = array_map(
                fn (string $address): string => $chain . ':' . strtolower($address),
                $missingAddresses
            );

            try {
                $response = $this->client()->get(
                    'https://coins.llama.fi/prices/current/' . implode(',', $coins)
                );
                $data = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);

                foreach ($data['coins'] ?? [] as $coin => $details) {
                    $this->prices[$chain][strtolower($coin)] = (float) ($details['price'] ?? 0);
                }
            } catch (\Throwable) {
                return [];
            }
        }

        $prices = [];

        foreach ($tokenAddresses as $address) {
            $key = $chain . ':' . strtolower($address);
            $prices[strtolower($address)] = $this->prices[$chain][$key] ?? null;
        }

        return $prices;
    }

    public function getHistoricalPrices(
        string $chain,
        int $timestamp,
        array $tokenAddresses
    ): array {
        $prices = [];
        $coins = array_map(
            fn (string $address): string => $chain . ':' . strtolower($address),
            $tokenAddresses
        );

        try {
            $response = $this->client()->get(
                'https://coins.llama.fi/prices/historical/'
                . $timestamp . '/' . implode(',', $coins)
            );
            $data = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);

            foreach ($tokenAddresses as $address) {
                $key = $chain . ':' . strtolower($address);
                $prices[strtolower($address)] = isset($data['coins'][$key]['price'])
                    ? (float) $data['coins'][$key]['price']
                    : null;
            }
        } catch (\Throwable) {
            return [];
        }

        return $prices;
    }
}

<?php

declare(strict_types=1);

namespace App\Services;

use GuzzleHttp\Client;

class VelodromeApiService
{
    public function __construct(
        private Client $client = new Client()
    ) {
    }

    public function getApr(int $positionId): ?float
    {
        $response = $this->client->get(
            'https://api.velodrome.finance/positions/' . $positionId
        );

        $json = json_decode(
            $response->getBody()->getContents(),
            true
        );

        if (!isset($json['apr'])) {
            return null;
        }

        return (float) $json['apr'];
    }
}
<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\StateStoreInterface;
use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;

/**
 * Upstash Redis implementation of StateStoreInterface for Vercel mode.
 */
class UpstashRedisStateStore implements StateStoreInterface
{
    private const KEY_PREFIX = 'velodrome2:';

    private string $restUrl;
    private string $restToken;
    private ClientInterface $client;

    public function __construct(
        ?string $restUrl = null,
        ?string $restToken = null,
        ?ClientInterface $client = null
    ) {
        $this->restUrl = $restUrl ?? (string) getenv('UPSTASH_REDIS_REST_URL');
        $this->restToken = $restToken ?? (string) getenv('UPSTASH_REDIS_REST_TOKEN');
        $this->client = $client ?? new Client(['timeout' => 10]);

        if ($this->restUrl === '' || $this->restToken === '') {
            throw new \RuntimeException(
                'Upstash Redis environment variables are not configured. '
                . 'UPSTASH_REDIS_REST_URL and UPSTASH_REDIS_REST_TOKEN are required.'
            );
        }
    }

    public function exists(string $key): bool
    {
        $response = $this->executeCommand(['GET' => self::KEY_PREFIX . $key]);

        if (!isset($response['result'])) {
            throw new \RuntimeException('Invalid Upstash Redis response for EXISTS');
        }

        return $response['result'] !== null;
    }

    public function write(string $key, string $content): void
    {
        $response = $this->executeCommand([
            'SET' => self::KEY_PREFIX . $key,
            'value' => $content,
        ]);

        if (!isset($response['result'])) {
            throw new \RuntimeException('Invalid Upstash Redis response for SET');
        }
    }

    public function delete(string $key): void
    {
        $response = $this->executeCommand(['DEL' => self::KEY_PREFIX . $key]);

        if (!isset($response['result'])) {
            throw new \RuntimeException('Invalid Upstash Redis response for DEL');
        }
    }

    /**
     * Execute Upstash Redis command via REST API.
     */
    private function executeCommand(array $command): array
    {
        try {
            $response = $this->client->post($this->restUrl, [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->restToken,
                    'Content-Type' => 'application/json',
                ],
                'json' => $command,
            ]);

            $body = (string) $response->getBody();
            $result = json_decode($body, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new \RuntimeException('Invalid JSON response from Upstash Redis');
            }

            if (isset($result['error'])) {
                throw new \RuntimeException('Upstash Redis command failed');
            }

            return $result;
        } catch (\GuzzleHttp\Exception\GuzzleException $e) {
            throw new \RuntimeException(
                'Failed to communicate with Upstash Redis. State backend unavailable.'
            );
        }
    }
}

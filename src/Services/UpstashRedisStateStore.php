<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\StateStoreInterface;
use App\Exceptions\StateStoreUnavailableException;
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
            throw new StateStoreUnavailableException(
                'Upstash Redis environment variables are not configured.'
            );
        }
    }

    public function exists(string $key): bool
    {
        $response = $this->executeCommand(['GET', self::KEY_PREFIX . $key]);

        if (!array_key_exists('result', $response)) {
            throw new StateStoreUnavailableException('Invalid response from the state backend.');
        }

        return $response['result'] !== null;
    }

    public function write(string $key, string $content): void
    {
        $response = $this->executeCommand(['SET', self::KEY_PREFIX . $key, $content]);

        if (($response['result'] ?? null) !== 'OK') {
            throw new StateStoreUnavailableException('Unable to write to the state backend.');
        }
    }

    public function delete(string $key): void
    {
        $response = $this->executeCommand(['DEL', self::KEY_PREFIX . $key]);

        if (!array_key_exists('result', $response) || !is_int($response['result'])) {
            throw new StateStoreUnavailableException('Unable to delete from the state backend.');
        }
    }

    public function assertAvailable(): void
    {
        $response = $this->executeCommand(['PING']);

        if (($response['result'] ?? null) !== 'PONG') {
            throw new StateStoreUnavailableException('State backend is unavailable.');
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

            if (json_last_error() !== JSON_ERROR_NONE || !is_array($result)) {
                throw new StateStoreUnavailableException('Invalid response from the state backend.');
            }

            if (isset($result['error'])) {
                throw new StateStoreUnavailableException('State backend command failed.');
            }

            return $result;
        } catch (StateStoreUnavailableException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            throw new StateStoreUnavailableException('State backend is unavailable.', 0, $exception);
        }
    }
}

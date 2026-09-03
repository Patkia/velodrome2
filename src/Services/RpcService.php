<?php

declare(strict_types=1);

namespace App\Services;

use GuzzleHttp\ClientInterface;
use GuzzleHttp\Pool;
use Psr\Http\Message\ResponseInterface;

class RpcService
{
    public function __construct(array $config)
    {
        $this->rpcUrl = $config['rpc']['url'];
        if (($config['rpc']['transport'] ?? null) === 'stream') {
            HttpClientFactory::useStreamHandler();
        }

        $this->client = HttpClientFactory::create([
            'timeout' => 30,
            'connect_timeout' => 10,
        ]);
    }

    private ClientInterface $client;
    private string $rpcUrl;

    public function call(string $method, array $params = []): array
    {
        $payload = [
            'jsonrpc' => '2.0',
            'method'  => $method,
            'params'  => $params,
            'id'      => 1,
        ];

        try {
            $response = $this->client->post($this->rpcUrl, ['json' => $payload]);
        } catch (\Throwable $exception) {
            if (!HttpClientFactory::usesStreamHandler()
                && str_contains($exception->getMessage(), 'CURLOPT_PROXY')
            ) {
                HttpClientFactory::useStreamHandler();
                $this->client = HttpClientFactory::create([
                    'timeout' => 30,
                    'connect_timeout' => 10,
                ]);

                return $this->call($method, $params);
            }

            throw new \RuntimeException($exception->getMessage(), 0, $exception);
        }

        return $this->decodeResponse($response);
    }

    public function callMany(array $calls, int $concurrency = 4): array
    {
        if (HttpClientFactory::usesStreamHandler()) {
            return array_map(
                fn (array $call): array => $this->call($call['method'], $call['params'] ?? []),
                $calls
            );
        }

        $responses = [];
        $errors = [];
        $requests = function () use ($calls): \Generator {
            foreach ($calls as $index => $call) {
                yield $index => fn () => $this->client->postAsync($this->rpcUrl, [
                    'json' => [
                        'jsonrpc' => '2.0',
                        'method' => $call['method'],
                        'params' => $call['params'] ?? [],
                        'id' => $index + 1,
                    ],
                ]);
            }
        };

        $pool = new Pool($this->client, $requests(), [
            'concurrency' => $concurrency,
            'fulfilled' => function (ResponseInterface $response, int $index) use (&$responses): void {
                $responses[$index] = $this->decodeResponse($response);
            },
            'rejected' => function ($reason, int $index) use (&$errors): void {
                $errors[$index] = $reason;
            },
        ]);
        $pool->promise()->wait();

        if ($errors !== []) {
            throw new \RuntimeException('Unable to complete all parallel RPC requests.');
        }

        ksort($responses);

        return array_values($responses);
    }
    public function callContract(
        string $contract,
        string $data,
        string $block = 'latest'
    ): array
    {
        return $this->call(
            'eth_call',
            [
                [
                    'to'   => $contract,
                    'data' => $data,
                ],
                $block,
            ]
        );
    }

    public function getChainId(): int
    {
        $data = $this->call('eth_chainId');

        if (!isset($data['result'])) {
            throw new \RuntimeException('RPC response does not contain result.');
        }

        return hexdec($data['result']);
    }

    private function decodeResponse(ResponseInterface $response): array
    {
        if ($response->getStatusCode() !== 200) {
            throw new \RuntimeException('RPC returned HTTP status ' . $response->getStatusCode() . '.');
        }

        $data = json_decode((string) $response->getBody(), true);

        if (!is_array($data)) {
            throw new \RuntimeException('Invalid JSON response.');
        }

        return $data;
    }
}

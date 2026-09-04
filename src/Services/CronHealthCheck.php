<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\StateStoreUnavailableException;

/**
 * Performs the authenticated cron runtime health preflight without running the monitor.
 */
class CronHealthCheck
{
    /** @var \Closure(): array */
    private \Closure $configValidator;

    /** @var \Closure(): void */
    private \Closure $upstashPreflight;

    /**
     * @param callable(): array $configValidator
     * @param callable(): void  $upstashPreflight
     */
    public function __construct(
        callable $configValidator,
        callable $upstashPreflight
    ) {
        $this->configValidator = \Closure::fromCallable($configValidator);
        $this->upstashPreflight = \Closure::fromCallable($upstashPreflight);
    }

    /**
     * @return array{statusCode: int, body: array<string, mixed>}
     */
    public function check(): array
    {
        try {
            ($this->configValidator)();
            ($this->upstashPreflight)();

            return [
                'statusCode' => 200,
                'body' => [
                    'status' => 'ok',
                    'runtimeMode' => 'vercel',
                    'stateBackend' => 'upstash',
                    'notificationsSent' => 0,
                ],
            ];
        } catch (StateStoreUnavailableException) {
            return $this->errorResponse('State backend unavailable.');
        } catch (\Throwable) {
            return $this->errorResponse('Monitor health check failed.');
        }
    }

    /**
     * @return array{statusCode: int, body: array<string, mixed>}
     */
    private function errorResponse(string $message): array
    {
        return [
            'statusCode' => 500,
            'body' => [
                'status' => 'error',
                'runtimeMode' => 'vercel',
                'stateBackend' => 'upstash',
                'notificationsSent' => 0,
                'errors' => [$message],
            ],
        ];
    }
}

<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Validates cron bearer authentication without exposing the configured secret.
 */
class CronAuthenticator
{
    /**
     * @return array{statusCode: int, body: array<string, mixed>}
     */
    public function validate(?string $expectedSecret, string $authorization): array
    {
        if ($expectedSecret === null || $expectedSecret === '') {
            return $this->errorResponse(500, 'CRON_SECRET is not configured.');
        }

        if (!str_starts_with($authorization, 'Bearer ')
            || !hash_equals($expectedSecret, substr($authorization, 7))
        ) {
            return $this->errorResponse(401, 'Unauthorized.');
        }

        return [
            'statusCode' => 200,
            'body' => [],
        ];
    }

    /**
     * @return array{statusCode: int, body: array<string, mixed>}
     */
    private function errorResponse(int $statusCode, string $message): array
    {
        return [
            'statusCode' => $statusCode,
            'body' => [
                'status' => 'error',
                'positionsChecked' => 0,
                'alertsSent' => 0,
                'runtimeMode' => 'vercel',
                'stateBackend' => 'upstash',
                'errors' => [$message],
            ],
        ];
    }
}

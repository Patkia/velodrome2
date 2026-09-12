<?php

declare(strict_types=1);

namespace App\Services;

final class SitePositionsPresenter
{
    private const SCHEMA_VERSION = 1;

    public function present(array $result, string $walletAddress, ?\DateTimeImmutable $generatedAt = null): array
    {
        $observedAt = ($generatedAt ?? new \DateTimeImmutable('now', new \DateTimeZone('UTC')))
            ->setTimezone(new \DateTimeZone('UTC'))
            ->format('Y-m-d\TH:i:s\Z');
        $positions = [];

        foreach ($result['positions'] ?? [] as $position) {
            if (!is_array($position)) {
                continue;
            }

            $positions[] = $this->presentPosition($position, $observedAt);
        }

        $unavailableChains = $this->unavailableChains($result['errors'] ?? []);

        return [
            'schemaVersion' => self::SCHEMA_VERSION,
            'status' => $unavailableChains === [] ? 'ok' : 'partial',
            'generatedAt' => $observedAt,
            'walletAddress' => $this->maskWalletAddress($walletAddress),
            'positionsChecked' => max(0, (int) ($result['positionsChecked'] ?? 0)),
            'positions' => $positions,
            'unavailableChains' => $unavailableChains,
        ];
    }

    public function error(string $code): array
    {
        return [
            'schemaVersion' => self::SCHEMA_VERSION,
            'status' => 'error',
            'error' => [
                'code' => $code,
            ],
        ];
    }

    private function presentPosition(array $position, string $observedAt): array
    {
        $tokens = [];

        foreach ($position['tokens'] ?? [] as $token) {
            if (!is_array($token)) {
                continue;
            }

            $tokens[] = [
                'symbol' => $this->nullableString($token['symbol'] ?? null),
                'amount' => $this->nullableString($token['amount'] ?? null),
                'value' => $this->nullableString($token['value'] ?? null),
                'valueUsd' => $this->nullableFloat($token['valueUsd'] ?? null),
            ];
        }

        $inRange = (bool) ($position['inRange'] ?? false);

        return [
            'chain' => $this->displayChain($position['chain'] ?? null),
            'positionId' => isset($position['positionId']) ? (int) $position['positionId'] : null,
            'pair' => $this->nullableString($position['pair'] ?? $position['pool'] ?? null),
            'status' => $this->nullableString($position['status'] ?? null)
                ?? ($inRange ? 'in-range' : 'out-of-range'),
            'inRange' => $inRange,
            'tokens' => $tokens,
            'currentValue' => $this->nullableString($position['currentValue'] ?? null),
            'currentValueUsd' => $this->nullableFloat($position['currentValueUsd'] ?? null),
            'initialValue' => $this->nullableString($position['initialValue'] ?? null),
            'initialValueUsd' => $this->nullableFloat($position['initialValueUsd'] ?? null),
            'profitLoss' => $this->nullableString($position['profitLoss'] ?? null),
            'profitLossUsd' => $this->nullableFloat($position['profitLossUsd'] ?? null),
            'profitLossPercent' => $this->nullableFloat($position['profitLossPercent'] ?? null),
            'rewards' => $this->nullableString($position['rewards'] ?? $position['reward'] ?? null),
            'observedAt' => $observedAt,
        ];
    }

    private function unavailableChains(array $errors): array
    {
        $chains = [];

        foreach ($errors as $error) {
            if (!is_string($error) || preg_match('/^Unable to monitor ([A-Z0-9_-]+)\.$/', $error, $matches) !== 1) {
                continue;
            }

            $chains[] = ucfirst(strtolower($matches[1]));
        }

        return array_values(array_unique($chains));
    }

    private function displayChain(mixed $chain): ?string
    {
        $chain = $this->nullableString($chain);

        return $chain === null ? null : ucfirst(strtolower($chain));
    }

    private function maskWalletAddress(string $walletAddress): string
    {
        $walletAddress = trim($walletAddress);

        if (strlen($walletAddress) < 12) {
            return '***';
        }

        return substr($walletAddress, 0, 6) . '...' . substr($walletAddress, -4);
    }

    private function nullableString(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    private function nullableFloat(mixed $value): ?float
    {
        if (!is_int($value) && !is_float($value)) {
            return null;
        }

        $value = (float) $value;

        return is_finite($value) ? $value : null;
    }
}

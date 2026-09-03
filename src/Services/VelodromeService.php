<?php

declare(strict_types=1);

namespace App\Services;

use App\Services\ContractService;

class VelodromeService
{
    private ContractService $contract;

    public function __construct(
        ContractService $contract
    ) {
        $this->contract = $contract;
    }

    public function getPosition(int $positionId): array
    {

    }
}
<?php

declare(strict_types=1);

namespace App\Contracts;

interface StateStoreInterface
{
    /**
     * Check if a state key exists.
     */
    public function exists(string $key): bool;

    /**
     * Write state content for a key.
     */
    public function write(string $key, string $content): void;

    /**
     * Delete state for a key.
     */
    public function delete(string $key): void;
}

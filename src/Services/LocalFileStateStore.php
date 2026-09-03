<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\StateStoreInterface;

class LocalFileStateStore implements StateStoreInterface
{
    private string $directory;

    public function __construct(string $directory)
    {
        $this->directory = rtrim($directory, DIRECTORY_SEPARATOR);
    }

    public function exists(string $key): bool
    {
        return file_exists($this->filePath($key));
    }

    public function write(string $key, string $content): void
    {
        $dir = $this->directory;

        if (!is_dir($dir)) {
            if (!mkdir($dir, 0700, true) && !is_dir($dir)) {
                throw new \RuntimeException('Unable to create state directory: ' . $dir);
            }
        }

        $path = $this->filePath($key);

        if (file_put_contents($path, $content) === false) {
            throw new \RuntimeException('Unable to write state file: ' . $path);
        }
    }

    public function delete(string $key): void
    {
        $path = $this->filePath($key);

        if (file_exists($path)) {
            if (!unlink($path)) {
                throw new \RuntimeException('Unable to delete state file: ' . $path);
            }
        }
    }

    private function filePath(string $key): string
    {
        return $this->directory . DIRECTORY_SEPARATOR . $key;
    }
}

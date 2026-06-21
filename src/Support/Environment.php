<?php

declare(strict_types=1);

namespace Emaadali\PestDatabaseIsolation\Support;

use RuntimeException;

final class Environment
{
    public static function optional(string $key): ?string
    {
        $value = $_SERVER[$key] ?? $_ENV[$key] ?? getenv($key);
        if (is_string($value) && $value !== '') {
            return $value;
        }

        return self::file($key);
    }

    public static function required(string $key): string
    {
        $value = self::optional($key);

        if ($value === null) {
            throw new RuntimeException("Environment variable [{$key}] is required but was not set.");
        }

        return $value;
    }

    public static function file(string $key): ?string
    {
        $path = self::workingDirectory().'/.env';
        if (! is_file($path)) {
            return null;
        }

        foreach (file($path, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
            if (! str_starts_with($line, "{$key}=")) {
                continue;
            }

            return self::normalize(mb_substr($line, mb_strlen($key) + 1));
        }

        return null;
    }

    public static function integer(string $key, int $default): int
    {
        $value = self::optional($key);

        return is_numeric($value) ? (int) $value : $default;
    }

    public static function boolean(string $key, bool $default = false): bool
    {
        $value = self::optional($key);

        if ($value === null) {
            return $default;
        }

        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }

    public static function set(string $key, string $value): void
    {
        putenv("{$key}={$value}");
        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
    }

    public static function runningInParallel(): bool
    {
        return self::optional('LARAVEL_PARALLEL_TESTING') !== null;
    }

    public static function commandRequestsParallel(): bool
    {
        $arguments = $_SERVER['argv'] ?? [];

        if (! is_array($arguments)) {
            return self::runningInParallel();
        }

        foreach ($arguments as $argument) {
            if ($argument === '--parallel') {
                return true;
            }
        }

        return self::runningInParallel();
    }

    public static function workingDirectory(): string
    {
        $directory = getcwd();

        return is_string($directory) ? $directory : '.';
    }

    private static function normalize(string $value): string
    {
        $value = mb_trim($value);
        $first = $value[0] ?? '';
        $last = $value !== '' ? $value[mb_strlen($value) - 1] : '';

        if (mb_strlen($value) >= 2 && (($first === '"' && $last === '"') || ($first === "'" && $last === "'"))) {
            return mb_substr($value, 1, -1);
        }

        return $value;
    }
}

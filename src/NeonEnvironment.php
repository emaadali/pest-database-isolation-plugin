<?php

declare(strict_types=1);

namespace Emaadali\PestNeondbPlugin;

use Illuminate\Support\Str;
use RuntimeException;

final class NeonEnvironment
{
    public static function enabled(): bool
    {
        return filter_var(self::optional('NEON_TEST_BRANCHES') ?? false, FILTER_VALIDATE_BOOLEAN);
    }

    public static function required(string $key): string
    {
        $value = self::optional($key);

        if ($value === null) {
            throw new RuntimeException("{$key} must be configured for Neon test branching.");
        }

        return $value;
    }

    public static function optional(string $key): ?string
    {
        $value = $_SERVER[$key] ?? $_ENV[$key] ?? getenv($key);
        if (is_string($value) && $value !== '') {
            return $value;
        }

        return self::file($key);
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

    public static function branchName(string $purpose): string
    {
        $prefix = self::optional('NEON_BRANCH_PREFIX')
            ?? self::optional('APP_NAME')
            ?? basename(self::workingDirectory());

        return Str::limit(Str::slug(implode('-', [
            $prefix,
            $purpose,
            gmdate('YmdHis'),
            Str::lower(Str::random(6)),
        ])), 255, '');
    }

    public static function set(string $key, string $value): void
    {
        putenv("{$key}={$value}");
        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
    }

    public static function applyDatabaseEnvironment(NeonBranch $branch): void
    {
        self::set('DB_CONNECTION', 'pgsql');
        self::set('DB_HOST', self::directHost($branch->host));
        self::set('DB_PORT', self::file('DB_PORT') ?? self::optional('DB_PORT') ?? '5432');
        self::set('DB_DATABASE', self::file('DB_DATABASE') ?? self::optional('DB_DATABASE') ?? '');
        self::set('DB_USERNAME', self::file('DB_USERNAME') ?? self::optional('DB_USERNAME') ?? '');
        self::set('DB_PASSWORD', self::file('DB_PASSWORD') ?? self::optional('DB_PASSWORD') ?? '');
        self::set('NEON_TEST_WORKER_BRANCH_ID', $branch->id);
        self::set('NEON_TEST_WORKER_BRANCH_HOST', self::directHost($branch->host));

        if ($branch->poolerHost !== null) {
            self::set('NEON_TEST_WORKER_BRANCH_POOLER_HOST', $branch->poolerHost);
        }
    }

    public static function directHost(string $host): string
    {
        return str_replace('-pooler.', '.', $host);
    }

    public static function poolerHost(string $host): string
    {
        if (str_contains($host, '-pooler.')) {
            return $host;
        }

        $parts = explode('.', $host);

        if ($parts[0] === '') {
            return $host;
        }

        $parts[0] .= '-pooler';

        return implode('.', $parts);
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

    private static function workingDirectory(): string
    {
        $directory = getcwd();

        return is_string($directory) ? $directory : '.';
    }
}

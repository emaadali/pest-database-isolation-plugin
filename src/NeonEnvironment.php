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

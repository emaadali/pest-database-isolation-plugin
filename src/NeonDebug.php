<?php

declare(strict_types=1);

namespace Emaadali\PestNeondbPlugin;

use RuntimeException;

final class NeonDebug
{
    /**
     * @param  array<string, mixed>  $context
     */
    public static function log(string $event, array $context = []): void
    {
        if (! self::enabled()) {
            return;
        }

        $payload = [
            'time' => gmdate(DATE_ATOM),
            'event' => $event,
            'pid' => getmypid(),
            'test_token' => NeonEnvironment::optional('TEST_TOKEN'),
            'context' => self::mask($context),
        ];

        $path = self::path();
        $directory = dirname($path);
        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        file_put_contents($path, json_encode($payload, JSON_THROW_ON_ERROR).PHP_EOL, FILE_APPEND);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public static function dumpAndExitIfRequested(string $event, array $context = []): void
    {
        if (! filter_var(NeonEnvironment::optional('NEON_TEST_DEBUG_DUMP') ?? false, FILTER_VALIDATE_BOOLEAN)) {
            return;
        }

        $payload = [
            'event' => $event,
            'debug_log' => self::path(),
            'pid' => getmypid(),
            'test_token' => NeonEnvironment::optional('TEST_TOKEN'),
            'context' => self::mask($context),
        ];

        throw new RuntimeException("Neon test debug dump:\n".json_encode($payload, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
    }

    public static function enabled(): bool
    {
        return filter_var(NeonEnvironment::optional('NEON_TEST_DEBUG') ?? false, FILTER_VALIDATE_BOOLEAN)
            || filter_var(NeonEnvironment::optional('NEON_TEST_DEBUG_DUMP') ?? false, FILTER_VALIDATE_BOOLEAN);
    }

    public static function path(): string
    {
        $configuredPath = NeonEnvironment::optional('NEON_TEST_DEBUG_PATH');
        if ($configuredPath !== null) {
            return $configuredPath;
        }

        $storagePath = getcwd().'/storage/logs';
        if (is_dir($storagePath)) {
            return "{$storagePath}/neon-testing.log";
        }

        return getcwd().'/.neon-testing.log';
    }

    /**
     * @param  array<mixed, mixed>  $context
     * @return array<string, mixed>
     */
    private static function mask(array $context): array
    {
        $masked = [];

        foreach ($context as $key => $value) {
            $key = (string) $key;

            if (self::isSensitiveKey($key)) {
                $masked[$key] = self::maskValue($value);

                continue;
            }

            $masked[$key] = is_array($value) ? self::mask($value) : $value;
        }

        return $masked;
    }

    private static function isSensitiveKey(string $key): bool
    {
        $key = mb_strtolower($key);

        return str_contains($key, 'password')
            || str_contains($key, 'secret')
            || str_contains($key, 'token')
            || str_contains($key, 'api_key');
    }

    private static function maskValue(mixed $value): string
    {
        if (! is_string($value) || $value === '') {
            return '[empty]';
        }

        return mb_substr($value, 0, 4).'...'.mb_substr($value, -4);
    }
}

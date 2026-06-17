<?php

declare(strict_types=1);

namespace Emaadali\PestNeondbPlugin;

final class NeonTiming
{
    /**
     * @param  array<string, mixed>  $context
     */
    public static function log(string $event, array $context = []): void
    {
        if (! NeonEnvironment::enabled()) {
            return;
        }

        $payload = [
            'time' => gmdate(DATE_ATOM),
            'event' => $event,
            'pid' => getmypid(),
            'test_token' => NeonEnvironment::optional('TEST_TOKEN'),
            'context' => $context,
        ];

        $path = self::path();
        $directory = dirname($path);
        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        file_put_contents($path, json_encode($payload, JSON_THROW_ON_ERROR).PHP_EOL, FILE_APPEND);
    }

    public static function path(): string
    {
        $configuredPath = NeonEnvironment::optional('NEON_TEST_TIMING_LOG');
        if ($configuredPath !== null) {
            return $configuredPath;
        }

        $storageLogs = getcwd().'/storage/logs';
        if (is_dir($storageLogs)) {
            return "{$storageLogs}/neon-testing-timing.log";
        }

        return getcwd().'/.neon-testing-timing.log';
    }
}

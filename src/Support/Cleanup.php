<?php

declare(strict_types=1);

namespace Emaadali\PestDatabaseIsolation\Support;

use Throwable;

final class Cleanup
{
    /** @var array<int, callable(): void> */
    private static array $callbacks = [];

    private static bool $handlersInstalled = false;

    private static bool $hasRun = false;

    /**
     * Register a cleanup callback to run on normal shutdown or on interruption.
     *
     * @param  callable(): void  $callback
     */
    public static function register(callable $callback): void
    {
        self::$callbacks[] = $callback;
        self::installHandlers();
    }

    public static function run(): void
    {
        if (self::$hasRun) {
            return;
        }

        self::$hasRun = true;

        foreach (self::$callbacks as $callback) {
            try {
                $callback();
            } catch (Throwable) {
                // Cleanup is best-effort and must not mask the original failure.
            }
        }

        self::$callbacks = [];
    }

    private static function installHandlers(): void
    {
        if (self::$handlersInstalled) {
            return;
        }

        self::$handlersInstalled = true;

        register_shutdown_function(static fn () => self::run());

        if (! function_exists('pcntl_signal') || ! function_exists('pcntl_async_signals')) {
            return;
        }

        pcntl_async_signals(true);

        foreach ([SIGINT, SIGTERM, SIGHUP] as $signal) {
            pcntl_signal($signal, static function (int $received): never {
                self::run();

                exit(128 + $received);
            });
        }
    }
}

<?php

declare(strict_types=1);

namespace Emaadali\PestDatabaseIsolation\Drivers;

use Emaadali\PestDatabaseIsolation\Drivers\Neon\NeonDriver;
use Emaadali\PestDatabaseIsolation\Drivers\Postgres\PostgresDriver;
use Emaadali\PestDatabaseIsolation\Support\Environment;
use RuntimeException;

final class DriverManager
{
    private static ?TestingDatabaseDriver $driver = null;

    /**
     * Whether test database isolation is active for this process.
     */
    public static function enabled(): bool
    {
        return Environment::boolean('PEST_TEST_DATABASE_ISOLATION');
    }

    /**
     * The configured or inferred driver name.
     */
    public static function driverName(): string
    {
        $configured = Environment::optional('PEST_TEST_DATABASE_DRIVER');
        if ($configured !== null) {
            return strtolower($configured);
        }

        if (self::looksLikeNeon()) {
            return 'neon';
        }

        return 'pgsql';
    }

    /**
     * Resolve a single shared driver instance for this process.
     */
    public static function resolve(): TestingDatabaseDriver
    {
        return self::$driver ??= self::make(self::driverName());
    }

    /**
     * Forget the resolved driver instance (intended for tests).
     */
    public static function flush(): void
    {
        self::$driver = null;
    }

    private static function make(string $name): TestingDatabaseDriver
    {
        return match ($name) {
            'neon' => new NeonDriver,
            'pgsql', 'postgres', 'postgresql' => new PostgresDriver,
            default => throw new RuntimeException("Unsupported test database isolation driver [{$name}]."),
        };
    }

    private static function looksLikeNeon(): bool
    {
        foreach (['DB_URL', 'DATABASE_URL', 'DB_HOST'] as $key) {
            $value = Environment::optional($key);

            if ($value !== null && str_contains(strtolower($value), 'neon.tech')) {
                return true;
            }
        }

        return false;
    }
}

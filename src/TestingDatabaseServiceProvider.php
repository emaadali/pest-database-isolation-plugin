<?php

declare(strict_types=1);

namespace Emaadali\PestDatabaseIsolation;

use Emaadali\PestDatabaseIsolation\Drivers\DriverManager;
use Emaadali\PestDatabaseIsolation\Drivers\TestingDatabaseDriver;
use Emaadali\PestDatabaseIsolation\Support\Environment;
use Emaadali\PestDatabaseIsolation\Support\Timing;
use Illuminate\Support\Facades\ParallelTesting;
use Illuminate\Support\ServiceProvider;

final class TestingDatabaseServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        if (! $this->app->runningInConsole() || ! DriverManager::enabled()) {
            return;
        }

        $driver = DriverManager::resolve();

        ParallelTesting::resolveOptionsUsing(function (string $option) use ($driver): mixed {
            if ($option === 'without_databases') {
                return $driver->disablesLaravelDatabases();
            }

            return $_SERVER['LARAVEL_PARALLEL_TESTING_'.strtoupper($option)] ?? false;
        });

        Timing::log('provider.boot', [
            'driver' => $driver->name(),
            'running_in_parallel' => Environment::runningInParallel(),
            'parallel_requested' => Environment::commandRequestsParallel(),
        ]);

        if (! Environment::runningInParallel() && $driver->hasWorkerEnvironment()) {
            $driver->applyWorkerFromEnvironment();
        }

        $this->registerWorkerHooks($driver);
    }

    private function registerWorkerHooks(TestingDatabaseDriver $driver): void
    {
        ParallelTesting::setUpTestCase(function (mixed $testCase, string|int $token) use ($driver): void {
            $startedAt = hrtime(true);

            $driver->applyWorker((string) $token);

            Timing::log('provider.worker.setup', [
                'driver' => $driver->name(),
                'token' => $token,
                'test_case' => is_object($testCase) ? $testCase::class : null,
                'duration_ms' => round((hrtime(true) - $startedAt) / 1_000_000, 3),
            ]);
        });

        ParallelTesting::setUpTestDatabaseBeforeMigrating(
            fn (string $database, string|int $token) => $driver->applyWorker((string) $token)
        );

        ParallelTesting::setUpTestDatabase(
            fn (string $database, string|int $token) => $driver->applyWorker((string) $token)
        );
    }
}

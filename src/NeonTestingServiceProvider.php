<?php

declare(strict_types=1);

namespace Emaadali\PestNeondbPlugin;

use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Database\Events\MigrationsEnded;
use Illuminate\Database\Events\MigrationsStarted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\ParallelTesting;
use Illuminate\Support\ServiceProvider;

final class NeonTestingServiceProvider extends ServiceProvider
{
    private static ?NeonBranch $workerBranch = null;

    private static ?string $pooledMigrationHost = null;

    public function boot(): void
    {
        if (! $this->app->runningInConsole()) {
            return;
        }

        if (NeonEnvironment::enabled()) {
            $this->registerMigrationHostSwap();

            ParallelTesting::resolveOptionsUsing(fn (string $option): mixed => $option === 'without_databases'
                ? true
                : ($_SERVER['LARAVEL_PARALLEL_TESTING_'.strtoupper($option)] ?? false));

            if (! NeonEnvironment::runningInParallel() && NeonEnvironment::optional('NEON_TEST_WORKER_BRANCH_HOST') !== null) {
                $this->applyDatabaseHost(NeonEnvironment::required('NEON_TEST_WORKER_BRANCH_HOST'));
            }
        }

        ParallelTesting::setUpTestCase(function (mixed $testCase, string|int $token): void {
            if (! NeonEnvironment::enabled()) {
                return;
            }

            $this->applyWorkerBranch((string) $token);
        });

        ParallelTesting::setUpTestDatabaseBeforeMigrating(function (string $database, string|int $token): void {
            if (NeonEnvironment::enabled()) {
                $this->applyWorkerBranch((string) $token);
            }
        });

        ParallelTesting::setUpTestDatabase(function (string $database, string|int $token): void {
            if (NeonEnvironment::enabled()) {
                $this->applyWorkerBranch((string) $token);
            }
        });

        ParallelTesting::tearDownProcess(function (): void {});
    }

    private function registerMigrationHostSwap(): void
    {
        Event::listen(CommandStarting::class, function (CommandStarting $event): void {
            if (! str_starts_with($event->command, 'migrate')) {
                return;
            }

            $this->switchPooledHostToDirect();
        });

        Event::listen(CommandFinished::class, function (CommandFinished $event): void {
            if (! str_starts_with($event->command, 'migrate')) {
                return;
            }

            $this->restorePooledHost();
        });

        Event::listen(MigrationsStarted::class, function (): void {
            $this->switchPooledHostToDirect();
        });

        Event::listen(MigrationsEnded::class, function (): void {
            $this->restorePooledHost();
        });
    }

    private function switchPooledHostToDirect(): void
    {
        if (self::$pooledMigrationHost !== null) {
            return;
        }

        $host = config('database.connections.pgsql.host');

        if (! is_string($host) || ! str_contains($host, '-pooler.')) {
            return;
        }

        self::$pooledMigrationHost = $host;

        config(['database.connections.pgsql.host' => str_replace('-pooler.', '.', $host)]);

        DB::purge('pgsql');
    }

    private function restorePooledHost(): void
    {
        if (self::$pooledMigrationHost === null) {
            return;
        }

        config(['database.connections.pgsql.host' => self::$pooledMigrationHost]);

        self::$pooledMigrationHost = null;

        DB::purge('pgsql');
    }

    private function applyWorkerBranch(string $token): void
    {
        if (! self::$workerBranch instanceof NeonBranch) {
            self::$workerBranch = NeonApi::createBranch(
                parentBranchId: NeonEnvironment::required('NEON_TEST_PARENT_BRANCH_ID'),
                name: NeonEnvironment::branchName("test-worker-p{$token}"),
                ttlSeconds: NeonEnvironment::integer('NEON_TEST_BRANCH_TTL_SECONDS', 21600),
            );

            register_shutdown_function(static function (): void {
                if (self::$workerBranch instanceof NeonBranch) {
                    NeonApi::deleteBranch(self::$workerBranch->id);
                }
            });
        }

        config(['services.neon.testing_worker_branch_id' => self::$workerBranch->id]);
        $this->applyDatabaseHost(self::$workerBranch->host);
    }

    private function applyDatabaseHost(string $host): void
    {
        config([
            'database.default' => 'pgsql',
            'database.connections.pgsql.host' => NeonEnvironment::directHost($host),
            'database.connections.pgsql.port' => NeonEnvironment::file('DB_PORT') ?? NeonEnvironment::optional('DB_PORT') ?? '5432',
            'database.connections.pgsql.database' => NeonEnvironment::file('DB_DATABASE') ?? NeonEnvironment::optional('DB_DATABASE'),
            'database.connections.pgsql.username' => NeonEnvironment::file('DB_USERNAME') ?? NeonEnvironment::optional('DB_USERNAME'),
            'database.connections.pgsql.password' => NeonEnvironment::file('DB_PASSWORD') ?? NeonEnvironment::optional('DB_PASSWORD'),
            'database.connections.pgsql.sslmode' => 'require',
        ]);

        DB::purge('pgsql');
    }
}

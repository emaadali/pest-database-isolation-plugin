<?php

declare(strict_types=1);

namespace Emaadali\PestNeondbPlugin;

use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\ParallelTesting;
use Illuminate\Support\ServiceProvider;

final class NeonTestingServiceProvider extends ServiceProvider
{
    private static ?NeonBranch $workerBranch = null;

    public function boot(): void
    {
        if (! $this->app->runningInConsole()) {
            return;
        }

        if (NeonEnvironment::enabled()) {
            ParallelTesting::resolveOptionsUsing(fn (string $option): mixed => $option === 'without_databases'
                ? true
                : ($_SERVER['LARAVEL_PARALLEL_TESTING_'.strtoupper($option)] ?? false));

            if (! NeonEnvironment::runningInParallel() && NeonEnvironment::optional('NEON_TEST_WORKER_BRANCH_HOST') !== null) {
                $this->applyDatabaseHost(NeonEnvironment::required('NEON_TEST_WORKER_BRANCH_HOST'));
                RefreshDatabaseState::$migrated = true;
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

        $workerBranch = self::$workerBranch;

        config(['services.neon.testing_worker_branch_id' => $workerBranch->id]);
        $this->applyDatabaseHost($workerBranch->poolerHost ?? $workerBranch->host);
        RefreshDatabaseState::$migrated = true;

        NeonEnvironment::applyDatabaseEnvironment($workerBranch);
    }

    private function applyDatabaseHost(string $host): void
    {
        config([
            'database.default' => 'pgsql',
            'database.connections.pgsql.host' => $host,
            'database.connections.pgsql.port' => NeonEnvironment::file('DB_PORT') ?? NeonEnvironment::optional('DB_PORT') ?? '5432',
            'database.connections.pgsql.database' => NeonEnvironment::file('DB_DATABASE') ?? NeonEnvironment::optional('DB_DATABASE'),
            'database.connections.pgsql.username' => NeonEnvironment::file('DB_USERNAME') ?? NeonEnvironment::optional('DB_USERNAME'),
            'database.connections.pgsql.password' => NeonEnvironment::file('DB_PASSWORD') ?? NeonEnvironment::optional('DB_PASSWORD'),
            'database.connections.pgsql.sslmode' => 'require',
        ]);

        DB::purge('pgsql');
    }
}

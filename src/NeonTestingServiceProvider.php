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
        }

        ParallelTesting::setUpTestCase(function (mixed $testCase, string $token): void {
            if (! NeonEnvironment::enabled()) {
                return;
            }

            if (! self::$workerBranch instanceof NeonBranch) {
                NeonDebug::log('worker-creating-in-test-process', [
                    'parallel_token' => $token,
                    'NEON_PROJECT_ID' => NeonEnvironment::optional('NEON_PROJECT_ID'),
                    'NEON_TEST_PARENT_BRANCH_ID' => NeonEnvironment::optional('NEON_TEST_PARENT_BRANCH_ID'),
                    'NEON_TEST_BRANCH_TTL_SECONDS' => NeonEnvironment::optional('NEON_TEST_BRANCH_TTL_SECONDS') ?? 21600,
                    'DB_DATABASE' => NeonEnvironment::file('DB_DATABASE') ?? NeonEnvironment::optional('DB_DATABASE'),
                    'DB_USERNAME' => NeonEnvironment::file('DB_USERNAME') ?? NeonEnvironment::optional('DB_USERNAME'),
                    'DB_PASSWORD' => NeonEnvironment::file('DB_PASSWORD') ?? NeonEnvironment::optional('DB_PASSWORD'),
                ]);

                self::$workerBranch = NeonApi::createBranch(
                    parentBranchId: NeonEnvironment::required('NEON_TEST_PARENT_BRANCH_ID'),
                    name: NeonEnvironment::branchName("test-worker-p{$token}"),
                    ttlSeconds: NeonEnvironment::integer('NEON_TEST_BRANCH_TTL_SECONDS', 21600),
                );

                register_shutdown_function(static function (): void {
                    if (self::$workerBranch instanceof NeonBranch) {
                        NeonDebug::log('worker-deleting-at-shutdown', ['branch_id' => self::$workerBranch->id]);
                        NeonApi::deleteBranch(self::$workerBranch->id);
                        NeonDebug::log('worker-delete-requested-at-shutdown', ['branch_id' => self::$workerBranch->id]);
                    }
                });
            }

            $branchId = self::$workerBranch->id;
            $host = self::$workerBranch->host;

            config(['services.neon.testing_worker_branch_id' => $branchId]);
            $this->applyDatabaseHost($host);
            RefreshDatabaseState::$migrated = true;

            NeonDebug::log('worker-applied-in-test-process', [
                'parallel_token' => $token,
                'branch_id' => $branchId,
                'host' => $host,
                'test_case' => is_object($testCase) ? $testCase::class : null,
                'database.default' => config('database.default'),
                'database.connections.pgsql.host' => config('database.connections.pgsql.host'),
                'database.connections.pgsql.port' => config('database.connections.pgsql.port'),
                'database.connections.pgsql.database' => config('database.connections.pgsql.database'),
                'database.connections.pgsql.username' => config('database.connections.pgsql.username'),
                'database.connections.pgsql.password' => config('database.connections.pgsql.password'),
                'refresh_database_state.migrated' => RefreshDatabaseState::$migrated,
            ]);

            NeonDebug::dumpAndExitIfRequested('worker-applied-in-test-process', [
                'parallel_token' => $token,
                'branch_id' => $branchId,
                'host' => $host,
                'test_case' => is_object($testCase) ? $testCase::class : null,
                'database.default' => config('database.default'),
                'database.connections.pgsql.host' => config('database.connections.pgsql.host'),
                'database.connections.pgsql.port' => config('database.connections.pgsql.port'),
                'database.connections.pgsql.database' => config('database.connections.pgsql.database'),
                'database.connections.pgsql.username' => config('database.connections.pgsql.username'),
                'database.connections.pgsql.password' => config('database.connections.pgsql.password'),
                'refresh_database_state.migrated' => RefreshDatabaseState::$migrated,
            ]);
        });

        ParallelTesting::tearDownProcess(function (): void {});
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

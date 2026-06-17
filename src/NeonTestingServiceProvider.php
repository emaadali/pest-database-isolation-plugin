<?php

declare(strict_types=1);

namespace Emaadali\PestNeondbPlugin;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\ParallelTesting;
use Illuminate\Support\ServiceProvider;

final class NeonTestingServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        if (! $this->app->runningInConsole()) {
            return;
        }

        ParallelTesting::setUpProcess(function (int $token): void {
            if (! NeonEnvironment::enabled()) {
                NeonDebug::log('worker-skipped-disabled', ['parallel_token' => $token]);

                return;
            }

            NeonDebug::log('worker-creating', [
                'parallel_token' => $token,
                'NEON_PROJECT_ID' => NeonEnvironment::optional('NEON_PROJECT_ID'),
                'NEON_TEST_PARENT_BRANCH_ID' => NeonEnvironment::optional('NEON_TEST_PARENT_BRANCH_ID'),
                'NEON_TEST_BRANCH_TTL_SECONDS' => NeonEnvironment::optional('NEON_TEST_BRANCH_TTL_SECONDS') ?? 21600,
                'DB_DATABASE' => NeonEnvironment::optional('DB_DATABASE'),
                'DB_USERNAME' => NeonEnvironment::optional('DB_USERNAME'),
                'DB_PASSWORD' => NeonEnvironment::optional('DB_PASSWORD'),
            ]);

            $branch = NeonApi::createBranch(
                parentBranchId: NeonEnvironment::required('NEON_TEST_PARENT_BRANCH_ID'),
                name: NeonEnvironment::branchName("test-worker-p{$token}"),
                ttlSeconds: NeonEnvironment::integer('NEON_TEST_BRANCH_TTL_SECONDS', 21600),
            );

            config(['services.neon.testing_worker_branch_id' => $branch->id]);
            $this->applyDatabaseHost($branch->host);

            NeonDebug::log('worker-created-and-applied', [
                'parallel_token' => $token,
                'branch_id' => $branch->id,
                'branch_name' => $branch->name,
                'host' => $branch->host,
                'database.default' => config('database.default'),
                'database.connections.pgsql.host' => config('database.connections.pgsql.host'),
                'database.connections.pgsql.port' => config('database.connections.pgsql.port'),
                'database.connections.pgsql.database' => config('database.connections.pgsql.database'),
                'database.connections.pgsql.username' => config('database.connections.pgsql.username'),
                'database.connections.pgsql.password' => config('database.connections.pgsql.password'),
            ]);

            NeonDebug::dumpAndExitIfRequested('worker-created-and-applied', [
                'parallel_token' => $token,
                'branch_id' => $branch->id,
                'branch_name' => $branch->name,
                'host' => $branch->host,
                'database.default' => config('database.default'),
                'database.connections.pgsql.host' => config('database.connections.pgsql.host'),
                'database.connections.pgsql.port' => config('database.connections.pgsql.port'),
                'database.connections.pgsql.database' => config('database.connections.pgsql.database'),
                'database.connections.pgsql.username' => config('database.connections.pgsql.username'),
                'database.connections.pgsql.password' => config('database.connections.pgsql.password'),
            ]);
        });

        ParallelTesting::tearDownProcess(function (): void {
            $workerBranchId = config('services.neon.testing_worker_branch_id');
            if (is_string($workerBranchId) && $workerBranchId !== '') {
                NeonDebug::log('worker-deleting', ['branch_id' => $workerBranchId]);
                NeonApi::deleteBranch($workerBranchId);
                NeonDebug::log('worker-delete-requested', ['branch_id' => $workerBranchId]);
            }
        });
    }

    private function applyDatabaseHost(string $host): void
    {
        config([
            'database.default' => 'pgsql',
            'database.connections.pgsql.host' => $host,
            'database.connections.pgsql.port' => NeonEnvironment::optional('DB_PORT') ?? '5432',
            'database.connections.pgsql.database' => NeonEnvironment::optional('DB_DATABASE'),
            'database.connections.pgsql.username' => NeonEnvironment::optional('DB_USERNAME'),
            'database.connections.pgsql.password' => NeonEnvironment::optional('DB_PASSWORD'),
        ]);

        DB::purge('pgsql');
    }
}

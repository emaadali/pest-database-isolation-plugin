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
                return;
            }

            $branch = NeonApi::createBranch(
                parentBranchId: NeonEnvironment::required('NEON_TEST_PARENT_BRANCH_ID'),
                name: NeonEnvironment::branchName("test-worker-p{$token}"),
                ttlSeconds: NeonEnvironment::integer('NEON_TEST_BRANCH_TTL_SECONDS', 21600),
            );

            config(['services.neon.testing_worker_branch_id' => $branch->id]);
            $this->applyDatabaseHost($branch->host);
        });

        ParallelTesting::tearDownProcess(function (): void {
            $workerBranchId = config('services.neon.testing_worker_branch_id');
            if (is_string($workerBranchId) && $workerBranchId !== '') {
                NeonApi::deleteBranch($workerBranchId);
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

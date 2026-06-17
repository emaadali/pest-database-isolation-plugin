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
            NeonTiming::log('provider.boot.enabled', [
                'running_in_parallel' => NeonEnvironment::runningInParallel(),
                'parallel_requested' => NeonEnvironment::commandRequestsParallel(),
            ]);

            ParallelTesting::resolveOptionsUsing(fn (string $option): mixed => $option === 'without_databases'
                ? true
                : ($_SERVER['LARAVEL_PARALLEL_TESTING_'.strtoupper($option)] ?? false));

            if (! NeonEnvironment::runningInParallel() && NeonEnvironment::optional('NEON_TEST_WORKER_BRANCH_HOST') !== null) {
                $startedAt = hrtime(true);

                $configuredHost = NeonEnvironment::required('NEON_TEST_WORKER_BRANCH_HOST');

                $this->applyDatabaseHost($configuredHost);
                RefreshDatabaseState::$migrated = true;

                NeonTiming::log('provider.nonparallel-worker.applied', [
                    'configured_host' => $configuredHost,
                    'direct_host' => NeonEnvironment::optional('NEON_TEST_WORKER_BRANCH_DIRECT_HOST'),
                    'pooler_host' => NeonEnvironment::optional('NEON_TEST_WORKER_BRANCH_POOLER_HOST'),
                    'duration_ms' => $this->durationMs($startedAt),
                ]);
            }
        }

        ParallelTesting::setUpTestCase(function (mixed $testCase, string|int $token): void {
            if (! NeonEnvironment::enabled()) {
                return;
            }

            $startedAt = hrtime(true);

            NeonTiming::log('provider.setUpTestCase.start', [
                'token' => $token,
                'test_case' => is_object($testCase) ? $testCase::class : null,
            ]);

            $this->applyWorkerBranch((string) $token);

            NeonTiming::log('provider.setUpTestCase.end', [
                'token' => $token,
                'test_case' => is_object($testCase) ? $testCase::class : null,
                'duration_ms' => round((hrtime(true) - $startedAt) / 1_000_000, 3),
            ]);
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
        $startedAt = hrtime(true);

        NeonTiming::log('provider.worker.apply.start', [
            'token' => $token,
        ]);

        if (! self::$workerBranch instanceof NeonBranch) {
            self::$workerBranch = NeonApi::createBranch(
                parentBranchId: NeonEnvironment::required('NEON_TEST_PARENT_BRANCH_ID'),
                name: NeonEnvironment::branchName("test-worker-p{$token}"),
                ttlSeconds: NeonEnvironment::integer('NEON_TEST_BRANCH_TTL_SECONDS', 21600),
            );

            register_shutdown_function(static function (): void {
                if (self::$workerBranch instanceof NeonBranch) {
                    $startedAt = hrtime(true);

                    NeonApi::deleteBranch(self::$workerBranch->id);

                    NeonTiming::log('provider.worker.delete.shutdown.end', [
                        'branch_id' => self::$workerBranch->id,
                        'duration_ms' => round((hrtime(true) - $startedAt) / 1_000_000, 3),
                    ]);
                }
            });
        }

        $workerBranch = self::$workerBranch;
        $configuredHost = NeonEnvironment::databaseHost($workerBranch);

        config(['services.neon.testing_worker_branch_id' => $workerBranch->id]);
        $this->applyDatabaseHost($configuredHost);
        RefreshDatabaseState::$migrated = true;

        NeonEnvironment::applyDatabaseEnvironment($workerBranch);

        NeonTiming::log('provider.worker.apply.end', [
            'token' => $token,
            'branch_id' => $workerBranch->id,
            'configured_host' => $configuredHost,
            'direct_host' => NeonEnvironment::directHost($workerBranch->host),
            'pooler_host' => $workerBranch->poolerHost,
            'duration_ms' => $this->durationMs($startedAt),
        ]);
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
            'database.connections.pgsql.options' => NeonEnvironment::databaseOptions(),
        ]);

        DB::purge('pgsql');
    }

    private function durationMs(int $startedAt): float
    {
        return round((hrtime(true) - $startedAt) / 1_000_000, 3);
    }
}

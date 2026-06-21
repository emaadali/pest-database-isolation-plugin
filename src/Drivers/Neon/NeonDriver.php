<?php

declare(strict_types=1);

namespace Emaadali\PestDatabaseIsolation\Drivers\Neon;

use Emaadali\PestDatabaseIsolation\Drivers\TestingDatabaseDriver;
use Emaadali\PestDatabaseIsolation\Support\Environment;
use Emaadali\PestDatabaseIsolation\Support\Timing;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class NeonDriver implements TestingDatabaseDriver
{
    private ?NeonBranch $workerBranch = null;

    public function name(): string
    {
        return 'neon';
    }

    public function initializeRoot(): void
    {
        $this->assertConfigured();

        if (Environment::optional('NEON_TEST_PARENT_BRANCH_ID') !== null) {
            Timing::log('neon.root.skipped.existing-parent', [
                'parent_branch_id' => Environment::optional('NEON_TEST_PARENT_BRANCH_ID'),
            ]);

            return;
        }

        if (Environment::optional('TEST_TOKEN') !== null) {
            throw new RuntimeException('NEON_TEST_PARENT_BRANCH_ID was not initialized before the parallel test worker booted.');
        }

        if (! Environment::commandRequestsParallel()) {
            $workerBranch = NeonApi::createBranch(
                parentBranchId: Environment::required('NEON_PARENT_BRANCH_ID'),
                name: NeonSettings::branchName('test-worker'),
                ttlSeconds: Environment::integer('NEON_TEST_BRANCH_TTL_SECONDS', 21600),
            );

            Environment::set('NEON_TEST_PARENT_BRANCH_ID', Environment::required('NEON_PARENT_BRANCH_ID'));
            NeonSettings::applyDatabaseEnvironment($workerBranch);

            Timing::log('neon.root.nonparallel-worker.applied', [
                'worker_branch_id' => $workerBranch->id,
                'host' => $workerBranch->host,
                'pooler_host' => $workerBranch->poolerHost,
            ]);

            register_shutdown_function(static fn () => NeonApi::deleteBranch($workerBranch->id));

            return;
        }

        $branch = NeonApi::createBranch(
            parentBranchId: Environment::required('NEON_PARENT_BRANCH_ID'),
            name: NeonSettings::branchName('test-root'),
            withEndpoint: false,
        );

        Environment::set('NEON_TEST_PARENT_BRANCH_ID', $branch->id);

        Timing::log('neon.root.initialized', [
            'parent_branch_id' => $branch->id,
        ]);

        register_shutdown_function(static fn () => NeonApi::deleteBranch($branch->id));
    }

    public function hasWorkerEnvironment(): bool
    {
        return Environment::optional('NEON_TEST_WORKER_BRANCH_HOST') !== null;
    }

    public function applyWorkerFromEnvironment(): void
    {
        $configuredHost = Environment::required('NEON_TEST_WORKER_BRANCH_HOST');

        $this->applyConnection($configuredHost);
        RefreshDatabaseState::$migrated = true;

        Timing::log('neon.worker.applied-from-environment', [
            'configured_host' => $configuredHost,
            'direct_host' => Environment::optional('NEON_TEST_WORKER_BRANCH_DIRECT_HOST'),
            'pooler_host' => Environment::optional('NEON_TEST_WORKER_BRANCH_POOLER_HOST'),
        ]);
    }

    public function applyWorker(string $token): void
    {
        $branch = $this->workerBranch ??= $this->createWorkerBranch($token);

        config(['services.neon.testing_worker_branch_id' => $branch->id]);
        $this->applyConnection(NeonSettings::databaseHost($branch));
        RefreshDatabaseState::$migrated = true;

        NeonSettings::applyDatabaseEnvironment($branch);
    }

    public function disablesLaravelDatabases(): bool
    {
        return true;
    }

    private function assertConfigured(): void
    {
        $missing = array_values(array_filter(
            ['NEON_API_KEY', 'NEON_PROJECT_ID', 'NEON_PARENT_BRANCH_ID'],
            static fn (string $key): bool => Environment::optional($key) === null,
        ));

        if ($missing !== []) {
            throw new RuntimeException(
                'The neon test database isolation driver requires ['.implode(', ', $missing).'] to be configured.'
            );
        }
    }

    private function createWorkerBranch(string $token): NeonBranch
    {
        $branch = NeonApi::createBranch(
            parentBranchId: Environment::required('NEON_TEST_PARENT_BRANCH_ID'),
            name: NeonSettings::branchName("test-worker-p{$token}"),
            ttlSeconds: Environment::integer('NEON_TEST_BRANCH_TTL_SECONDS', 21600),
        );

        register_shutdown_function(static fn () => NeonApi::deleteBranch($branch->id));

        return $branch;
    }

    private function applyConnection(string $host): void
    {
        config([
            'database.default' => 'pgsql',
            'database.connections.pgsql.host' => $host,
            'database.connections.pgsql.port' => Environment::file('DB_PORT') ?? Environment::optional('DB_PORT') ?? '5432',
            'database.connections.pgsql.database' => Environment::file('DB_DATABASE') ?? Environment::optional('DB_DATABASE'),
            'database.connections.pgsql.username' => Environment::file('DB_USERNAME') ?? Environment::optional('DB_USERNAME'),
            'database.connections.pgsql.password' => Environment::file('DB_PASSWORD') ?? Environment::optional('DB_PASSWORD'),
            'database.connections.pgsql.sslmode' => 'require',
            'database.connections.pgsql.options' => NeonSettings::databaseOptions(),
        ]);

        DB::purge('pgsql');
    }
}

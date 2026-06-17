<?php

declare(strict_types=1);

namespace Emaadali\PestNeondbPlugin;

use RuntimeException;

final class NeonTestingRootBranch
{
    public static function initialize(): void
    {
        NeonTiming::log('pest.root.initialize.start', [
            'parallel_requested' => NeonEnvironment::commandRequestsParallel(),
            'running_in_parallel' => NeonEnvironment::runningInParallel(),
        ]);

        if (! NeonEnvironment::enabled()) {
            NeonTiming::log('pest.root.initialize.skipped.disabled');

            return;
        }

        if (NeonEnvironment::optional('NEON_TEST_PARENT_BRANCH_ID') !== null) {
            NeonTiming::log('pest.root.initialize.skipped.existing-parent', [
                'parent_branch_id' => NeonEnvironment::optional('NEON_TEST_PARENT_BRANCH_ID'),
            ]);

            return;
        }

        if (NeonEnvironment::optional('TEST_TOKEN') !== null) {
            throw new RuntimeException('NEON_TEST_PARENT_BRANCH_ID was not initialized before the parallel test worker booted.');
        }

        if (! NeonEnvironment::commandRequestsParallel()) {
            $workerBranch = NeonApi::createBranch(
                parentBranchId: NeonEnvironment::required('NEON_PARENT_BRANCH_ID'),
                name: NeonEnvironment::branchName('test-worker'),
                ttlSeconds: NeonEnvironment::integer('NEON_TEST_BRANCH_TTL_SECONDS', 21600),
            );

            NeonEnvironment::set('NEON_TEST_PARENT_BRANCH_ID', NeonEnvironment::required('NEON_PARENT_BRANCH_ID'));
            NeonEnvironment::applyDatabaseEnvironment($workerBranch);

            NeonTiming::log('pest.root.nonparallel-worker.applied', [
                'worker_branch_id' => $workerBranch->id,
                'host' => $workerBranch->host,
                'pooler_host' => $workerBranch->poolerHost,
            ]);

            register_shutdown_function(static fn () => NeonApi::deleteBranch($workerBranch->id));

            return;
        }

        $branch = NeonApi::createBranch(
            parentBranchId: NeonEnvironment::required('NEON_PARENT_BRANCH_ID'),
            name: NeonEnvironment::branchName('test-root'),
            withEndpoint: false,
        );

        NeonEnvironment::set('NEON_TEST_PARENT_BRANCH_ID', $branch->id);

        NeonTiming::log('pest.root.initialize.end', [
            'parent_branch_id' => $branch->id,
        ]);

        register_shutdown_function(static fn () => NeonApi::deleteBranch($branch->id));
    }
}

<?php

declare(strict_types=1);

namespace Emaadali\PestNeondbPlugin;

use RuntimeException;

final class NeonTestingRootBranch
{
    public static function initialize(): void
    {
        if (! NeonEnvironment::enabled()) {
            return;
        }

        if (NeonEnvironment::optional('NEON_TEST_PARENT_BRANCH_ID') !== null) {
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

            register_shutdown_function(static fn () => NeonApi::deleteBranch($workerBranch->id));

            return;
        }

        $branch = NeonApi::createBranch(
            parentBranchId: NeonEnvironment::required('NEON_PARENT_BRANCH_ID'),
            name: NeonEnvironment::branchName('test-root'),
            withEndpoint: false,
        );

        NeonEnvironment::set('NEON_TEST_PARENT_BRANCH_ID', $branch->id);

        register_shutdown_function(static fn () => NeonApi::deleteBranch($branch->id));
    }
}

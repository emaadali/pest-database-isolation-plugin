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

        $branch = NeonApi::createBranch(
            parentBranchId: NeonEnvironment::required('NEON_PARENT_BRANCH_ID'),
            name: NeonEnvironment::branchName('test-root'),
        );

        NeonEnvironment::set('NEON_TEST_PARENT_BRANCH_ID', $branch->id);

        $workerBranch = null;

        if (! NeonEnvironment::runningInParallel()) {
            $workerBranch = NeonApi::createBranch(
                parentBranchId: $branch->id,
                name: NeonEnvironment::branchName('test-worker'),
                ttlSeconds: NeonEnvironment::integer('NEON_TEST_BRANCH_TTL_SECONDS', 21600),
            );

            NeonEnvironment::applyDatabaseEnvironment($workerBranch);
        }

        register_shutdown_function(static function () use ($branch, $workerBranch): void {
            if ($workerBranch instanceof NeonBranch) {
                NeonApi::deleteBranch($workerBranch->id);
            }

            NeonApi::deleteBranch($branch->id);
        });
    }
}

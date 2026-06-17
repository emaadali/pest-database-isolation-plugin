<?php

declare(strict_types=1);

namespace Emaadali\PestNeondbPlugin;

use RuntimeException;

final class NeonTestingRootBranch
{
    public static function initialize(): void
    {
        if (! NeonEnvironment::enabled()) {
            NeonDebug::log('root-skipped-disabled');

            return;
        }

        if (NeonEnvironment::optional('NEON_TEST_PARENT_BRANCH_ID') !== null) {
            NeonDebug::log('root-skipped-existing-parent', [
                'NEON_TEST_PARENT_BRANCH_ID' => NeonEnvironment::optional('NEON_TEST_PARENT_BRANCH_ID'),
            ]);

            return;
        }

        if (NeonEnvironment::optional('TEST_TOKEN') !== null) {
            throw new RuntimeException('NEON_TEST_PARENT_BRANCH_ID was not initialized before the parallel test worker booted.');
        }

        NeonDebug::log('root-creating', [
            'NEON_PROJECT_ID' => NeonEnvironment::optional('NEON_PROJECT_ID'),
            'NEON_PARENT_BRANCH_ID' => NeonEnvironment::optional('NEON_PARENT_BRANCH_ID'),
            'NEON_BRANCH_PREFIX' => NeonEnvironment::optional('NEON_BRANCH_PREFIX'),
            'APP_NAME' => NeonEnvironment::optional('APP_NAME'),
        ]);

        $branch = NeonApi::createBranch(
            parentBranchId: NeonEnvironment::required('NEON_PARENT_BRANCH_ID'),
            name: NeonEnvironment::branchName('test-root'),
        );

        NeonEnvironment::set('NEON_TEST_PARENT_BRANCH_ID', $branch->id);

        NeonDebug::log('root-created', [
            'branch_id' => $branch->id,
            'branch_name' => $branch->name,
            'host' => $branch->host,
            'NEON_TEST_PARENT_BRANCH_ID' => NeonEnvironment::optional('NEON_TEST_PARENT_BRANCH_ID'),
        ]);

        register_shutdown_function(static function () use ($branch): void {
            NeonDebug::log('root-deleting', ['branch_id' => $branch->id]);
            NeonApi::deleteBranch($branch->id);
            NeonDebug::log('root-delete-requested', ['branch_id' => $branch->id]);
        });
    }
}

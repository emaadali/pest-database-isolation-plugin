<?php

declare(strict_types=1);

namespace Emaadali\PestNeondbPlugin;

use Illuminate\Support\Facades\DB;

final class NeonTestingRuntime
{
    public static function switchToPoolerHost(): void
    {
        if (! NeonEnvironment::enabled()) {
            return;
        }

        $poolerHost = NeonEnvironment::optional('NEON_TEST_WORKER_BRANCH_POOLER_HOST');
        if ($poolerHost === null) {
            return;
        }

        config(['database.connections.pgsql.host' => $poolerHost]);

        DB::purge('pgsql');
    }
}

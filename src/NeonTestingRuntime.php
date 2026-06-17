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
            NeonTiming::log('runtime.pooler-switch.skipped.no-pooler');

            return;
        }

        $startedAt = hrtime(true);

        config(['database.connections.pgsql.host' => $poolerHost]);

        DB::purge('pgsql');

        NeonTiming::log('runtime.pooler-switch.applied', [
            'host' => $poolerHost,
            'duration_ms' => round((hrtime(true) - $startedAt) / 1_000_000, 3),
        ]);
    }
}

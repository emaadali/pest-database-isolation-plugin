<?php

declare(strict_types=1);

namespace Emaadali\PestDatabaseIsolation\Drivers\Neon;

use Emaadali\PestDatabaseIsolation\Drivers\DriverManager;
use Emaadali\PestDatabaseIsolation\Support\Environment;
use Emaadali\PestDatabaseIsolation\Support\Timing;
use Illuminate\Support\Facades\DB;

final class NeonRuntime
{
    public static function switchToPoolerHost(): void
    {
        if (! DriverManager::enabled()) {
            return;
        }

        $poolerHost = Environment::optional('NEON_TEST_WORKER_BRANCH_POOLER_HOST');
        if ($poolerHost === null) {
            Timing::log('neon.runtime.pooler-switch.skipped.no-pooler');

            return;
        }

        $startedAt = hrtime(true);

        config(['database.connections.pgsql.host' => $poolerHost]);

        DB::purge('pgsql');

        Timing::log('neon.runtime.pooler-switch.applied', [
            'host' => $poolerHost,
            'duration_ms' => round((hrtime(true) - $startedAt) / 1_000_000, 3),
        ]);
    }
}

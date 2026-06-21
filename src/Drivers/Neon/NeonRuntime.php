<?php

declare(strict_types=1);

namespace Emaadali\PestDatabaseIsolation\Drivers\Neon;

use Emaadali\PestDatabaseIsolation\Drivers\DriverManager;
use Emaadali\PestDatabaseIsolation\Support\Environment;
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
            return;
        }

        config(['database.connections.pgsql.host' => $poolerHost]);

        DB::purge('pgsql');
    }
}

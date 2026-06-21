<?php

declare(strict_types=1);

namespace Emaadali\PestDatabaseIsolation;

use Emaadali\PestDatabaseIsolation\Drivers\DriverManager;
use Emaadali\PestDatabaseIsolation\Support\Environment;
use Emaadali\PestDatabaseIsolation\Support\Timing;

function usesDatabaseTestingIsolation(): void
{
    Environment::set('PEST_TEST_DATABASE_ISOLATION', 'true');

    DriverManager::resolve()->initializeRoot();

    pest()->beforeEach(function (): void {
        $GLOBALS['__pest_testing_database_started_at'] = hrtime(true);
        Timing::log('pest.test.beforeEach');
    });

    pest()->afterEach(function (): void {
        $startedAt = $GLOBALS['__pest_testing_database_started_at'] ?? null;

        Timing::log('pest.test.afterEach', [
            'duration_ms' => is_int($startedAt) ? round((hrtime(true) - $startedAt) / 1_000_000, 3) : null,
        ]);
    });
}

/**
 * @deprecated Use usesDatabaseTestingIsolation() instead.
 */
function usesNeonTestingRootBranch(): void
{
    usesDatabaseTestingIsolation();
}

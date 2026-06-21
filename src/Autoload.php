<?php

declare(strict_types=1);

namespace Emaadali\PestDatabaseIsolation;

use Emaadali\PestDatabaseIsolation\Drivers\DriverManager;
use Emaadali\PestDatabaseIsolation\Support\Environment;

function usesDatabaseTestingIsolation(): void
{
    Environment::set('PEST_TEST_DATABASE_ISOLATION', 'true');

    DriverManager::resolve()->initializeRoot();
}

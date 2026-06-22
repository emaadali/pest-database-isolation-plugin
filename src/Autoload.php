<?php

declare(strict_types=1);

namespace Emaadali\PestDatabaseIsolation;

use Emaadali\PestDatabaseIsolation\Drivers\DriverManager;
use Emaadali\PestDatabaseIsolation\Support\Environment;

function testRunId(): string
{
    return Environment::testRunId();
}

function usesDatabaseTestingIsolation(): void
{
    Environment::set('PEST_TEST_DATABASE_ISOLATION', 'true');
    Environment::testRunId();

    DriverManager::resolve()->initializeRoot();
}

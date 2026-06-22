<?php

use Emaadali\PestDatabaseIsolation\Drivers\DriverManager;
use Emaadali\PestDatabaseIsolation\Drivers\Neon\NeonApi;
use Emaadali\PestDatabaseIsolation\Drivers\Neon\NeonBranch;
use Emaadali\PestDatabaseIsolation\Drivers\Neon\NeonSettings;
use Emaadali\PestDatabaseIsolation\Drivers\Postgres\PostgresDriver;
use Emaadali\PestDatabaseIsolation\Support\Environment;

use function Emaadali\PestDatabaseIsolation\testRunId;

function restoreEnvironment(string $key, ?string $value): void
{
    if ($value === null) {
        putenv($key);
        unset($_ENV[$key], $_SERVER[$key]);

        return;
    }

    putenv("{$key}={$value}");
    $_ENV[$key] = $value;
    $_SERVER[$key] = $value;
}

it('normalizes quoted environment values from .env', function (): void {
    $directory = sys_get_temp_dir().'/pest-testing-database-'.bin2hex(random_bytes(4));
    mkdir($directory);
    file_put_contents($directory.'/.env', 'NEON_BRANCH_PREFIX="Example App"'.PHP_EOL);

    $previous = getcwd();
    chdir($directory);

    expect(Environment::optional('NEON_BRANCH_PREFIX'))->toBe('Example App');

    chdir($previous);
    unlink($directory.'/.env');
    rmdir($directory);
});

it('builds branch names from the configured application name', function (): void {
    $directory = sys_get_temp_dir().'/pest-testing-database-'.bin2hex(random_bytes(4));
    mkdir($directory);
    file_put_contents($directory.'/.env', 'APP_NAME="My Laravel App"'.PHP_EOL);

    $previous = getcwd();
    chdir($directory);

    expect(NeonSettings::branchName('test-root'))->toStartWith('my-laravel-app-test-root-');

    chdir($previous);
    unlink($directory.'/.env');
    rmdir($directory);
});

it('keeps direct and pooled endpoint hosts separately', function (): void {
    $method = new ReflectionMethod(NeonApi::class, 'endpointHosts');
    $method->setAccessible(true);

    expect($method->invoke(null, [
        ['host' => 'ep-restless-field-atfmtke7.c-9.us-east-1.aws.neon.tech'],
        ['host' => 'ep-restless-field-atfmtke7-pooler.c-9.us-east-1.aws.neon.tech'],
    ]))->toBe([
        'ep-restless-field-atfmtke7.c-9.us-east-1.aws.neon.tech',
        'ep-restless-field-atfmtke7-pooler.c-9.us-east-1.aws.neon.tech',
    ]);
});

it('synthesizes a pooler host when no pooler host is returned', function (): void {
    $method = new ReflectionMethod(NeonApi::class, 'endpointHosts');
    $method->setAccessible(true);

    expect($method->invoke(null, [
        ['host' => 'ep-restless-field-atfmtke7.c-9.us-east-1.aws.neon.tech'],
        ['host' => 'ep-other-field-atfmtke7.c-9.us-east-1.aws.neon.tech'],
    ]))->toBe([
        'ep-restless-field-atfmtke7.c-9.us-east-1.aws.neon.tech',
        'ep-restless-field-atfmtke7-pooler.c-9.us-east-1.aws.neon.tech',
    ]);
});

it('converts pooled endpoint hosts to direct hosts', function (): void {
    expect(NeonSettings::directHost('ep-restless-field-atfmtke7-pooler.c-9.us-east-1.aws.neon.tech'))
        ->toBe('ep-restless-field-atfmtke7.c-9.us-east-1.aws.neon.tech');
});

it('converts direct endpoint hosts to pooler hosts', function (): void {
    expect(NeonSettings::poolerHost('ep-restless-field-atfmtke7.c-9.us-east-1.aws.neon.tech'))
        ->toBe('ep-restless-field-atfmtke7-pooler.c-9.us-east-1.aws.neon.tech');
});

it('uses the pooler host for worker database connections', function (): void {
    $branch = new NeonBranch(
        id: 'br-worker',
        name: 'worker',
        host: 'ep-restless-field-atfmtke7.c-9.us-east-1.aws.neon.tech',
        poolerHost: 'ep-restless-field-atfmtke7-pooler.c-9.us-east-1.aws.neon.tech',
    );

    NeonSettings::applyDatabaseEnvironment($branch);

    expect(Environment::optional('DB_HOST'))
        ->toBe('ep-restless-field-atfmtke7-pooler.c-9.us-east-1.aws.neon.tech')
        ->and(Environment::optional('NEON_TEST_WORKER_BRANCH_HOST'))
        ->toBe('ep-restless-field-atfmtke7-pooler.c-9.us-east-1.aws.neon.tech')
        ->and(Environment::optional('NEON_TEST_WORKER_BRANCH_DIRECT_HOST'))
        ->toBe('ep-restless-field-atfmtke7.c-9.us-east-1.aws.neon.tech');
});

it('uses PgBouncer-compatible database connection options without emulating bindings', function (): void {
    expect(NeonSettings::databaseOptions())->toMatchArray([
        PDO::PGSQL_ATTR_DISABLE_PREPARES => true,
    ]);
});

it('detects parallel CLI requests from argv', function (): void {
    $originalArgv = $_SERVER['argv'] ?? null;
    $_SERVER['argv'] = ['vendor/bin/pest', '--parallel'];

    expect(Environment::commandRequestsParallel())->toBeTrue();

    if ($originalArgv === null) {
        unset($_SERVER['argv']);
    } else {
        $_SERVER['argv'] = $originalArgv;
    }
});

it('exposes a stable test run id for external test artifacts', function (): void {
    $previousRunId = Environment::optional('PEST_TEST_RUN_ID');
    $directory = sys_get_temp_dir().'/pest-testing-database-'.bin2hex(random_bytes(4));
    mkdir($directory);

    $previous = getcwd();
    chdir($directory);
    restoreEnvironment('PEST_TEST_RUN_ID', null);

    $runId = testRunId();

    expect($runId)
        ->toMatch('/^[a-f0-9]{8}$/')
        ->and(testRunId())->toBe($runId)
        ->and(Environment::optional('PEST_TEST_RUN_ID'))->toBe($runId)
        ->and(getenv('PEST_TEST_RUN_ID'))->toBe($runId);

    chdir($previous);
    rmdir($directory);
    restoreEnvironment('PEST_TEST_RUN_ID', $previousRunId);
});

it('infers the Neon driver from a database URL when no driver is configured', function (): void {
    $previousDriver = Environment::optional('PEST_TEST_DATABASE_DRIVER');
    $previousUrl = Environment::optional('DB_URL');

    restoreEnvironment('PEST_TEST_DATABASE_DRIVER', null);
    restoreEnvironment('DB_URL', 'postgres://user:pass@ep-restless-field-atfmtke7.c-9.us-east-1.aws.neon.tech/app');

    expect(DriverManager::driverName())->toBe('neon');

    restoreEnvironment('PEST_TEST_DATABASE_DRIVER', $previousDriver);
    restoreEnvironment('DB_URL', $previousUrl);
});

it('defaults to the pgsql driver for a local database host', function (): void {
    $previousDriver = Environment::optional('PEST_TEST_DATABASE_DRIVER');
    $previousUrl = Environment::optional('DB_URL');
    $previousHost = Environment::optional('DB_HOST');

    restoreEnvironment('PEST_TEST_DATABASE_DRIVER', null);
    restoreEnvironment('DB_URL', null);
    restoreEnvironment('DB_HOST', '127.0.0.1');

    expect(DriverManager::driverName())->toBe('pgsql');

    restoreEnvironment('PEST_TEST_DATABASE_DRIVER', $previousDriver);
    restoreEnvironment('DB_URL', $previousUrl);
    restoreEnvironment('DB_HOST', $previousHost);
});

it('allows the test database isolation driver to be configured explicitly', function (): void {
    $previousDriver = Environment::optional('PEST_TEST_DATABASE_DRIVER');

    restoreEnvironment('PEST_TEST_DATABASE_DRIVER', 'pgsql');

    expect(DriverManager::driverName())->toBe('pgsql');

    restoreEnvironment('PEST_TEST_DATABASE_DRIVER', $previousDriver);
});

it('builds run-scoped Postgres worker database names', function (): void {
    $previousRunId = Environment::optional('PEST_TEST_RUN_ID');

    restoreEnvironment('PEST_TEST_RUN_ID', 'abcdef12');

    $method = new ReflectionMethod(PostgresDriver::class, 'workerDatabaseName');
    $method->setAccessible(true);

    $database = $method->invoke(new PostgresDriver, 'example-application-testing-database-with-a-long-name', '3');

    expect($database)
        ->toEndWith('_pest_abcdef12_test_3')
        ->and(strlen($database))->toBeLessThanOrEqual(63);

    restoreEnvironment('PEST_TEST_RUN_ID', $previousRunId);
});

it('can represent a branch created without an endpoint', function (): void {
    $branch = new NeonBranch(
        id: 'br-root',
        name: 'root',
        host: '',
    );

    expect($branch->host)->toBe('')
        ->and($branch->poolerHost)->toBeNull();
});

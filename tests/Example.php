<?php

use Emaadali\PestNeondbPlugin\NeonApi;
use Emaadali\PestNeondbPlugin\NeonBranch;
use Emaadali\PestNeondbPlugin\NeonEnvironment;

it('normalizes quoted environment values from .env', function (): void {
    $directory = sys_get_temp_dir().'/pest-neondb-plugin-'.bin2hex(random_bytes(4));
    mkdir($directory);
    file_put_contents($directory.'/.env', 'NEON_BRANCH_PREFIX="Example App"'.PHP_EOL);

    $previous = getcwd();
    chdir($directory);

    expect(NeonEnvironment::optional('NEON_BRANCH_PREFIX'))->toBe('Example App');

    chdir($previous);
    unlink($directory.'/.env');
    rmdir($directory);
});

it('builds branch names from the configured application name', function (): void {
    $directory = sys_get_temp_dir().'/pest-neondb-plugin-'.bin2hex(random_bytes(4));
    mkdir($directory);
    file_put_contents($directory.'/.env', 'APP_NAME="My Laravel App"'.PHP_EOL);

    $previous = getcwd();
    chdir($directory);

    expect(NeonEnvironment::branchName('test-root'))->toStartWith('my-laravel-app-test-root-');

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
    expect(NeonEnvironment::directHost('ep-restless-field-atfmtke7-pooler.c-9.us-east-1.aws.neon.tech'))
        ->toBe('ep-restless-field-atfmtke7.c-9.us-east-1.aws.neon.tech');
});

it('converts direct endpoint hosts to pooler hosts', function (): void {
    expect(NeonEnvironment::poolerHost('ep-restless-field-atfmtke7.c-9.us-east-1.aws.neon.tech'))
        ->toBe('ep-restless-field-atfmtke7-pooler.c-9.us-east-1.aws.neon.tech');
});

it('uses the pooler host for worker database connections', function (): void {
    $branch = new NeonBranch(
        id: 'br-worker',
        name: 'worker',
        host: 'ep-restless-field-atfmtke7.c-9.us-east-1.aws.neon.tech',
        poolerHost: 'ep-restless-field-atfmtke7-pooler.c-9.us-east-1.aws.neon.tech',
    );

    NeonEnvironment::applyDatabaseEnvironment($branch);

    expect(NeonEnvironment::optional('DB_HOST'))
        ->toBe('ep-restless-field-atfmtke7-pooler.c-9.us-east-1.aws.neon.tech')
        ->and(NeonEnvironment::optional('NEON_TEST_WORKER_BRANCH_HOST'))
        ->toBe('ep-restless-field-atfmtke7-pooler.c-9.us-east-1.aws.neon.tech')
        ->and(NeonEnvironment::optional('NEON_TEST_WORKER_BRANCH_DIRECT_HOST'))
        ->toBe('ep-restless-field-atfmtke7.c-9.us-east-1.aws.neon.tech');
});

it('uses PgBouncer-compatible database connection options without emulating bindings', function (): void {
    expect(NeonEnvironment::databaseOptions())->toMatchArray([
        PDO::PGSQL_ATTR_DISABLE_PREPARES => true,
    ]);
});

it('detects parallel CLI requests from argv', function (): void {
    $originalArgv = $_SERVER['argv'] ?? null;
    $_SERVER['argv'] = ['vendor/bin/pest', '--parallel'];

    expect(NeonEnvironment::commandRequestsParallel())->toBeTrue();

    if ($originalArgv === null) {
        unset($_SERVER['argv']);
    } else {
        $_SERVER['argv'] = $originalArgv;
    }
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

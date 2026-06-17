<?php

use Emaadali\PestNeondbPlugin\NeonApi;
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

it('falls back to the first endpoint host when no pooler host is returned', function (): void {
    $method = new ReflectionMethod(NeonApi::class, 'endpointHosts');
    $method->setAccessible(true);

    expect($method->invoke(null, [
        ['host' => 'ep-restless-field-atfmtke7.c-9.us-east-1.aws.neon.tech'],
        ['host' => 'ep-other-field-atfmtke7.c-9.us-east-1.aws.neon.tech'],
    ]))->toBe([
        'ep-restless-field-atfmtke7.c-9.us-east-1.aws.neon.tech',
        null,
    ]);
});

it('converts pooled endpoint hosts to direct hosts for test database connections', function (): void {
    expect(NeonEnvironment::directHost('ep-restless-field-atfmtke7-pooler.c-9.us-east-1.aws.neon.tech'))
        ->toBe('ep-restless-field-atfmtke7.c-9.us-east-1.aws.neon.tech');
});

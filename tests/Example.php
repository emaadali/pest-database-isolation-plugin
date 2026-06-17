<?php

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

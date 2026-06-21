<?php

declare(strict_types=1);

namespace Emaadali\PestDatabaseIsolation\Drivers\Neon;

use Emaadali\PestDatabaseIsolation\Support\Environment;
use Illuminate\Support\Str;
use PDO;

final class NeonSettings
{
    public static function branchName(string $purpose): string
    {
        $prefix = Environment::optional('NEON_BRANCH_PREFIX')
            ?? Environment::optional('APP_NAME')
            ?? basename(Environment::workingDirectory());

        return Str::limit(Str::slug(implode('-', [
            $prefix,
            $purpose,
            gmdate('YmdHis'),
            Str::lower(Str::random(6)),
        ])), 255, '');
    }

    public static function applyDatabaseEnvironment(NeonBranch $branch): void
    {
        $databaseHost = self::databaseHost($branch);
        $directHost = self::directHost($branch->host);

        Environment::set('DB_CONNECTION', 'pgsql');
        Environment::set('DB_HOST', $databaseHost);
        Environment::set('DB_PORT', Environment::file('DB_PORT') ?? Environment::optional('DB_PORT') ?? '5432');
        Environment::set('DB_DATABASE', Environment::file('DB_DATABASE') ?? Environment::optional('DB_DATABASE') ?? '');
        Environment::set('DB_USERNAME', Environment::file('DB_USERNAME') ?? Environment::optional('DB_USERNAME') ?? '');
        Environment::set('DB_PASSWORD', Environment::file('DB_PASSWORD') ?? Environment::optional('DB_PASSWORD') ?? '');
        Environment::set('NEON_TEST_WORKER_BRANCH_ID', $branch->id);
        Environment::set('NEON_TEST_WORKER_BRANCH_HOST', $databaseHost);
        Environment::set('NEON_TEST_WORKER_BRANCH_DIRECT_HOST', $directHost);

        if ($branch->poolerHost !== null) {
            Environment::set('NEON_TEST_WORKER_BRANCH_POOLER_HOST', $branch->poolerHost);
        }
    }

    public static function databaseHost(NeonBranch $branch): string
    {
        return $branch->poolerHost ?? self::poolerHost(self::directHost($branch->host));
    }

    /**
     * @return array<int, mixed>
     */
    public static function databaseOptions(): array
    {
        return [
            PDO::PGSQL_ATTR_DISABLE_PREPARES => true,
        ];
    }

    public static function directHost(string $host): string
    {
        return str_replace('-pooler.', '.', $host);
    }

    public static function poolerHost(string $host): string
    {
        if (str_contains($host, '-pooler.')) {
            return $host;
        }

        $parts = explode('.', $host);

        if ($parts[0] === '') {
            return $host;
        }

        $parts[0] .= '-pooler';

        return implode('.', $parts);
    }
}

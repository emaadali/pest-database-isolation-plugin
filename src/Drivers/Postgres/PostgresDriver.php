<?php

declare(strict_types=1);

namespace Emaadali\PestDatabaseIsolation\Drivers\Postgres;

use Emaadali\PestDatabaseIsolation\Drivers\TestingDatabaseDriver;
use Emaadali\PestDatabaseIsolation\Support\Environment;
use Emaadali\PestDatabaseIsolation\Support\Timing;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use PDO;
use RuntimeException;

final class PostgresDriver implements TestingDatabaseDriver
{
    private ?string $workerDatabase = null;

    public function name(): string
    {
        return 'pgsql';
    }

    public function initializeRoot(): void
    {
        $this->ensureRunId();

        if (Environment::commandRequestsParallel()) {
            return;
        }

        $database = $this->createWorkerDatabase('single');
        $this->setWorkerEnvironment($database);

        Timing::log('pgsql.root.nonparallel-worker.applied', [
            'database' => $database,
        ]);
    }

    public function hasWorkerEnvironment(): bool
    {
        return Environment::optional('PEST_TEST_WORKER_DATABASE') !== null;
    }

    public function applyWorkerFromEnvironment(): void
    {
        $database = Environment::required('PEST_TEST_WORKER_DATABASE');

        $this->applyConnection($database);

        Timing::log('pgsql.worker.applied-from-environment', [
            'database' => $database,
        ]);
    }

    public function applyWorker(string $token): void
    {
        $database = $this->workerDatabase;

        if ($database === null) {
            $database = $this->workerDatabase = $this->createWorkerDatabase($token);
            $this->setWorkerEnvironment($database);

            RefreshDatabaseState::$migrated = false;
        }

        $this->applyConnection($database);
    }

    public function disablesLaravelDatabases(): bool
    {
        return true;
    }

    private function ensureRunId(): string
    {
        $runId = Environment::optional('PEST_TEST_RUN_ID');
        if ($runId !== null) {
            return $runId;
        }

        $runId = strtolower(bin2hex(random_bytes(4)));
        Environment::set('PEST_TEST_RUN_ID', $runId);

        return $runId;
    }

    private function createWorkerDatabase(string $token): string
    {
        $settings = $this->connectionSettings();
        $database = $this->workerDatabaseName($settings['database'], $token);
        $pdo = $this->adminConnection($settings);

        $this->dropDatabase($pdo, $database);
        $pdo->exec('CREATE DATABASE '.$this->quoteIdentifier($database));

        register_shutdown_function(function () use ($database): void {
            $this->dropDatabase($this->adminConnection($this->connectionSettings()), $database);

            Timing::log('pgsql.database.drop.shutdown', [
                'database' => $database,
            ]);
        });

        Timing::log('pgsql.database.created', [
            'database' => $database,
        ]);

        return $database;
    }

    /**
     * @param  array{host: string, port: string, database: string, username: string, password: ?string, sslmode: ?string}  $settings
     */
    private function adminConnection(array $settings): PDO
    {
        $this->assertSafeHost($settings['host']);

        $adminDatabase = Environment::optional('PEST_TEST_PGSQL_ADMIN_DATABASE') ?? 'postgres';
        $dsn = "pgsql:host={$settings['host']};port={$settings['port']};dbname={$adminDatabase}";

        if ($settings['sslmode'] !== null) {
            $dsn .= ";sslmode={$settings['sslmode']}";
        }

        return new PDO($dsn, $settings['username'], $settings['password'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);
    }

    private function dropDatabase(PDO $pdo, string $database): void
    {
        $terminate = $pdo->prepare('select pg_terminate_backend(pid) from pg_stat_activity where datname = ? and pid <> pg_backend_pid()');
        $terminate->execute([$database]);

        $pdo->exec('DROP DATABASE IF EXISTS '.$this->quoteIdentifier($database));
    }

    private function applyConnection(string $database): void
    {
        $settings = $this->connectionSettings();

        config([
            'database.default' => 'pgsql',
            'database.connections.pgsql.url' => null,
            'database.connections.pgsql.host' => $settings['host'],
            'database.connections.pgsql.port' => $settings['port'],
            'database.connections.pgsql.database' => $database,
            'database.connections.pgsql.username' => $settings['username'],
            'database.connections.pgsql.password' => $settings['password'],
            'database.connections.pgsql.sslmode' => $settings['sslmode'],
        ]);

        DB::purge('pgsql');
    }

    private function setWorkerEnvironment(string $database): void
    {
        $settings = $this->connectionSettings();

        Environment::set('DB_CONNECTION', 'pgsql');
        Environment::set('DB_HOST', $settings['host']);
        Environment::set('DB_PORT', $settings['port']);
        Environment::set('DB_DATABASE', $database);
        Environment::set('DB_USERNAME', $settings['username']);

        if ($settings['password'] !== null) {
            Environment::set('DB_PASSWORD', $settings['password']);
        }

        Environment::set('PEST_TEST_WORKER_DATABASE', $database);
    }

    /**
     * @return array{host: string, port: string, database: string, username: string, password: ?string, sslmode: ?string}
     */
    private function connectionSettings(): array
    {
        $url = $this->urlSettings();

        return [
            'host' => $url['host'] ?? Environment::file('DB_HOST') ?? Environment::optional('DB_HOST') ?? '127.0.0.1',
            'port' => $url['port'] ?? Environment::file('DB_PORT') ?? Environment::optional('DB_PORT') ?? '5432',
            'database' => $url['database'] ?? Environment::file('DB_DATABASE') ?? Environment::optional('DB_DATABASE') ?? 'testing',
            'username' => $url['username'] ?? Environment::file('DB_USERNAME') ?? Environment::optional('DB_USERNAME') ?? '',
            'password' => $url['password'] ?? Environment::file('DB_PASSWORD') ?? Environment::optional('DB_PASSWORD'),
            'sslmode' => $url['sslmode'] ?? Environment::file('DB_SSLMODE') ?? Environment::optional('DB_SSLMODE'),
        ];
    }

    /**
     * @return array{host?: string, port?: string, database?: string, username?: string, password?: string, sslmode?: string}
     */
    private function urlSettings(): array
    {
        $url = Environment::optional('DB_URL') ?? Environment::optional('DATABASE_URL');
        if ($url === null) {
            return [];
        }

        $parts = parse_url($url);
        if (! is_array($parts)) {
            return [];
        }

        $settings = [];

        if (isset($parts['host'])) {
            $settings['host'] = $parts['host'];
        }

        if (isset($parts['port'])) {
            $settings['port'] = (string) $parts['port'];
        }

        if (isset($parts['path']) && $parts['path'] !== '') {
            $settings['database'] = ltrim($parts['path'], '/');
        }

        if (isset($parts['user'])) {
            $settings['username'] = rawurldecode($parts['user']);
        }

        if (isset($parts['pass'])) {
            $settings['password'] = rawurldecode($parts['pass']);
        }

        if (isset($parts['query'])) {
            parse_str($parts['query'], $query);

            if (isset($query['sslmode']) && is_string($query['sslmode'])) {
                $settings['sslmode'] = $query['sslmode'];
            }
        }

        return $settings;
    }

    private function workerDatabaseName(string $baseDatabase, string $token): string
    {
        $suffix = '_pest_'.$this->ensureRunId().'_test_'.$this->sanitizeIdentifierPart($token);
        $maxBaseLength = max(1, 63 - strlen($suffix));
        $base = substr($this->sanitizeIdentifierPart($baseDatabase), 0, $maxBaseLength);

        return $base.$suffix;
    }

    private function sanitizeIdentifierPart(string $value): string
    {
        $value = strtolower(preg_replace('/[^a-zA-Z0-9_]+/', '_', $value) ?? '');
        $value = trim($value, '_');

        return $value !== '' ? $value : 'testing';
    }

    private function quoteIdentifier(string $identifier): string
    {
        return '"'.str_replace('"', '""', $identifier).'"';
    }

    private function assertSafeHost(string $host): void
    {
        if (in_array($host, ['localhost', '127.0.0.1', '::1'], true) || str_starts_with($host, '/')) {
            return;
        }

        throw new RuntimeException("The pgsql test isolation driver only creates and drops databases on a local Postgres host, but [{$host}] is not local.");
    }
}

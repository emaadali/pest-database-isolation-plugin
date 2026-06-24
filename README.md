# Pest Database Isolation Plugin

Laravel-focused Pest plugin for isolating concurrent test runs and parallel test workers with Neon branches or local Postgres databases.

## Installation

```bash
composer require --dev emaadali/pest-database-isolation-plugin
```

## Usage

In `tests/Pest.php`:

```php
use function Emaadali\PestDatabaseIsolation\usesDatabaseTestingIsolation;

usesDatabaseTestingIsolation();
```

The plugin also exposes a stable run id for isolating other test artifacts created during the same Pest command:

```php
use function Emaadali\PestDatabaseIsolation\testRunId;

pest()->browser()->screenshots(
    dirname(__DIR__).'/tests/Browser/Screenshots/'.testRunId()
);
```

The run id is stored in `PEST_TEST_RUN_ID` and is shared with Laravel parallel test workers.

The plugin auto-selects a driver. If your `DB_URL`, `DATABASE_URL`, or `DB_HOST` contains `neon.tech`, it uses the `neon` driver. Otherwise it uses the `pgsql` driver.

You can configure the driver explicitly:

```env
PEST_TEST_DATABASE_DRIVER=neon
# or
PEST_TEST_DATABASE_DRIVER=pgsql
```

## Neon Driver

Configure Neon:

```env
NEON_API_KEY=...
NEON_PROJECT_ID=...
NEON_PARENT_BRANCH_ID=...
NEON_TEST_BRANCH_TTL_SECONDS=21600
```

The root test branch is created once per Pest run and deleted when the parent test process exits. Laravel parallel workers automatically create expiring child branches from that root branch and point the worker database connection at the child branch.

When the `neon` driver is selected but `NEON_API_KEY`, `NEON_PROJECT_ID`, or `NEON_PARENT_BRANCH_ID` is missing, the plugin throws an exception instead of silently falling back.

## Postgres Driver

The `pgsql` driver creates a unique database per test run and worker, such as:

```text
app_pest_ab12cd34_test_1
app_pest_ab12cd34_test_2
```

Laravel's `RefreshDatabase` trait then runs `migrate:fresh` against that worker database normally. The plugin drops the worker database on shutdown.

Because this driver creates and drops databases, it only operates on a local Postgres host (`localhost`, `127.0.0.1`, `::1`, or a Unix socket). Pointing it at a remote host throws an exception.

The plugin connects to the `postgres` maintenance database to create and drop worker databases. Set `PEST_TEST_PGSQL_ADMIN_DATABASE` to use a different one:

```env
PEST_TEST_PGSQL_ADMIN_DATABASE=template1
```

## Worktree databases

The package ships a `vendor/bin/setup-worktree-database` binary for giving each git worktree its own isolated local Postgres database. It reads the connection details from the worktree's `.env`, creates a database named `<base>_wt_<worktree>` (worktree name from `CURSOR_WORKTREE_NAME`, falling back to the directory name), and rewrites `.env` to point at it.

Call it from your worktree setup, after `composer install` and before migrating:

```bash
cp "$ROOT_WORKTREE_PATH/.env" .env
composer install
vendor/bin/setup-worktree-database
php artisan migrate
```

The database is created empty (migrate it yourself), recreated fresh on each run, and—like the `pgsql` test driver—only operates on a local Postgres host.

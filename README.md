# Pest Database Isolation Plugin

Laravel-focused Pest plugin for isolating concurrent test runs and parallel test workers with Neon branches or local Postgres databases.

## Installation

```bash
composer require --dev emaadali/pest-neondb-plugin
```

## Usage

In `tests/Pest.php`:

```php
use function Emaadali\PestDatabaseIsolation\usesDatabaseTestingIsolation;

usesDatabaseTestingIsolation();
```

The plugin auto-selects a driver. If your `DB_URL`, `DATABASE_URL`, or `DB_HOST` contains `neon.tech`, it uses the `neon` driver. Otherwise it uses the `pgsql` driver.

You can configure the driver explicitly:

```env
PEST_TEST_DATABASE_DRIVER=neon
# or
PEST_TEST_DATABASE_DRIVER=pgsql
```

The previous helper still works but is deprecated in favor of `usesDatabaseTestingIsolation()`:

```php
use function Emaadali\PestDatabaseIsolation\usesNeonTestingRootBranch;

usesNeonTestingRootBranch();
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

### Admin database

PostgreSQL will not let you run `CREATE DATABASE` or `DROP DATABASE` while you are connected to the database you are creating or dropping. You have to issue those statements from a *different*, already-existing database. Every PostgreSQL server ships with a maintenance database named `postgres` for exactly this purpose, so the plugin connects there to create and drop each worker database. (MySQL has no equivalent restriction, which is why you have not run into this before.)

If your server has no `postgres` database, point the plugin at another existing one:

```env
PEST_TEST_PGSQL_ADMIN_DATABASE=template1
```

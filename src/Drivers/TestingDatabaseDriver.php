<?php

declare(strict_types=1);

namespace Emaadali\PestDatabaseIsolation\Drivers;

interface TestingDatabaseDriver
{
    /**
     * The short identifier for this driver (e.g. "neon", "pgsql").
     */
    public function name(): string;

    /**
     * Prepare shared, run-wide isolation once, before any worker boots.
     */
    public function initializeRoot(): void;

    /**
     * Whether a previously prepared worker target is already present in the environment.
     */
    public function hasWorkerEnvironment(): bool;

    /**
     * Point the connection at the worker target described by the current environment.
     */
    public function applyWorkerFromEnvironment(): void;

    /**
     * Provision (once) and point the connection at the isolated target for the given worker token.
     */
    public function applyWorker(string $token): void;

    /**
     * Whether Laravel's built-in per-worker test database creation should be disabled,
     * because this driver provisions the worker target itself.
     */
    public function disablesLaravelDatabases(): bool;
}

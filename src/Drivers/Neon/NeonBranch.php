<?php

declare(strict_types=1);

namespace Emaadali\PestDatabaseIsolation\Drivers\Neon;

final readonly class NeonBranch
{
    public function __construct(
        public string $id,
        public string $name,
        public string $host,
        public ?string $poolerHost = null,
    ) {}
}

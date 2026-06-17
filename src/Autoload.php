<?php

declare(strict_types=1);

namespace Emaadali\PestNeondbPlugin;

function usesNeonTestingRootBranch(): void
{
    NeonTestingRootBranch::initialize();

    pest()->beforeEach(function (): void {
        $GLOBALS['__pest_neondb_test_started_at'] = hrtime(true);
        NeonTiming::log('pest.test.beforeEach.start');

        NeonTiming::log('pest.test.beforeEach.end');
    });

    pest()->afterEach(function (): void {
        $startedAt = $GLOBALS['__pest_neondb_test_started_at'] ?? null;

        NeonTiming::log('pest.test.afterEach', [
            'duration_ms' => is_int($startedAt) ? round((hrtime(true) - $startedAt) / 1_000_000, 3) : null,
        ]);
    });
}

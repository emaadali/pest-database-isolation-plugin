<?php

declare(strict_types=1);

namespace Emaadali\PestNeondbPlugin;

function usesNeonTestingRootBranch(): void
{
    NeonTestingRootBranch::initialize();

    pest()->beforeEach(function (): void {
        NeonTestingRuntime::switchToPoolerHost();
    });
}

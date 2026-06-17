# Pest NeonDB Plugin

Laravel-focused Pest plugin for isolating parallel test workers with Neon database branches.

## Installation

```bash
composer require --dev emaadali/pest-neondb-plugin
```

## Usage

In `tests/Pest.php`:

```php
use function Emaadali\PestNeondbPlugin\usesNeonTestingRootBranch;

usesNeonTestingRootBranch();
```

Configure your test environment:

```env
NEON_TEST_BRANCHES=true
NEON_API_KEY=...
NEON_PROJECT_ID=...
NEON_PARENT_BRANCH_ID=...
NEON_TEST_BRANCH_TTL_SECONDS=21600
```

The root test branch is created once per Pest run and deleted when the parent test process exits. Laravel parallel workers automatically create expiring child branches from that root branch and point the worker database connection at the child branch.

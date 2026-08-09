# 0005: PHP 8.4 and Laravel 13 as the floor

**Status:** accepted, implemented. Revised once during the work: the first
answer was Laravel 12.

## Context

The floor is not an aesthetic choice; the tooling imposes it. Surveyed
2026-08-05:

| Runtime / tool | Minimum PHP |
|---|---|
| Laravel 13 | `^8.3` |
| `orchestra/testbench` 11 | `^8.3` |
| Pest 4 | `^8.3` |
| Pest 5 + plugins (arch, type-coverage, laravel, mutate) | `^8.4` |
| Larastan 3 | `^8.2` |
| PHPStan 2, Pint, Rector, dependency-analyser | ≤ 8.2 |

### The Laravel floor does not come from PHP 8.3

| Laravel | Supported PHP | Bug fixes until | Security until | Status |
|---|---|---|---|---|
| 10 | 8.1 – 8.3 | 2024-08-06 | 2025-02-04 | EOL |
| 11 | 8.2 – 8.4 | 2025-09-03 | 2026-03-12 | EOL |
| 12 | 8.2 – 8.5 | 2026-08-13 | 2027-02-24 | active |
| 13 | 8.3 – 8.5 | Q3 2027 | 2028-03-17 | active |

PHP 8.3, required by Laravel 13, sits inside the range supported by L10 through
L13, so it excludes no version and is not the limiting factor.

**What determines the floor is the ceiling.** Supporting PHP 8.5 means only
versions that reach it qualify: L10 stops at 8.3, L11 at 8.4. Only L12 and L13
cover 8.5, and they are also the only two still under security support. Two
independent criteria converging on the same point gave: floor Laravel 12.

### The PHP floor

| Branch | Active until | Security until |
|---|---|---|
| 8.3 | 2025-12-31 | 2027-12-31 |
| 8.4 | 2026-12-31 | 2028-12-31 |
| 8.5 | 2027-12-31 | 2029-12-31 |

An 8.3 floor is defensible on lifecycle grounds, but it forces **two Pest majors
in parallel**: Pest 4 on the 8.3 jobs, Pest 5 on 8.4 and 8.5. An 8.4 floor puts
Pest 5 and every plugin on every job.

## Decision

**PHP 8.4, Laravel 13.** `composer.json` constraint `">=8.4 <8.6"`.

Laravel 12 was the original answer and was dropped: the analysis above weighs
the framework alone and misses the test stack. **Pest 5 requires
`symfony/process ^8.1` while Laravel 12 requires `^7.2`**, so the two cannot be
installed in the same tree: the Laravel 12 cell fails at `composer update`,
before a single test runs. Keeping it would mean either Pest 4 for that cell,
which is the split stack this decision exists to avoid, or shipping support CI
never exercises.

## Consequences

- A two-cell matrix (PHP 8.4 and 8.5 against Laravel 13), down from eleven.
- The local development floor sits above what is typically installed on a
  developer machine, which is why `.docker/` reproduces any cell.
- v1's constraint `">=8.1 <8.5"` actively blocked PHP 8.5; that is fixed.

# ADR-0001: Repository structure and stable public entry points

## Status

Accepted

## Date

2026-09-17

## Problem

SentryIQ currently contains public PHP endpoints, internal application logic, browser assets, diagnostics, and cloud services in a mixed repository layout. Moving files without accounting for deployed URLs and relative include paths can break the live application.

## Decision

SentryIQ will use a domain-oriented internal application layer under `app/`, while keeping the current public PHP entry-point filenames at the repository root during the migration.

Internal implementation will be grouped into:

- `app/Auth/`
- `app/Documents/`
- `app/Gallery/`
- `app/Security/`
- `app/Vault/`

Root-level public endpoints may become thin wrappers that delegate to the corresponding internal implementation.

The existing `cloud/` layer remains separate from `app/` because it represents reusable cloud/domain services rather than local HTTP entry points.

Static browser assets will be moved under `assets/` only after all consumers and relative paths have been identified.

## Reasoning

This approach preserves deployed URLs while improving separation of responsibilities. It also makes each migration small and reversible, reducing the risk of breaking the live installation.

Keeping `cloud/` separate prevents a future repository cleanup from accidentally coupling local application concerns to cloud storage and processing components.

## Consequences

Positive consequences:

- Public URLs can remain unchanged.
- Internal PHP logic becomes easier to locate and maintain.
- Domain responsibilities become explicit.
- Individual migrations can be verified before the next subsystem is moved.
- Cloud services retain a clear architectural boundary.

Trade-offs:

- Temporary root-level wrapper files will exist during the migration.
- Relative include paths must be reviewed when implementation files move.
- The repository will remain in a transitional state until all suitable internal files have been migrated.

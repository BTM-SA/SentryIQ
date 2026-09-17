# ADR-0002: Remove migrated root endpoint wrappers

## Status

Accepted

## Date

2026-09-17

## Problem

After migrating application implementations into `app/`, the repository still contained small root PHP wrapper files whose only purpose was to include those implementations. Keeping a separate wrapper for every migrated endpoint left the root directory cluttered and made the new application boundaries less clear.

## Decision

Remove the migrated endpoint wrapper files from the repository root.

Preserve their existing public URLs through root `.htaccess` rewrite rules that internally route each legacy endpoint path to its corresponding implementation under `app/`.

Shared bootstrap entry points that are still used as filesystem includes are retained at the root until a dedicated bootstrap migration can be completed without unnecessary disruption.

## Reasoning

This removes duplicate endpoint files while preserving the deployed URL surface. Existing forms, JavaScript requests, bookmarks, and integrations can continue using their current endpoint URLs while the actual implementations remain organized under the domain-oriented `app/` tree.

The routing layer also makes the distinction explicit: the root path is the public URL surface, while `app/` contains the implementation.

## Consequences

Positive consequences:

- Migrated action wrappers no longer clutter the root directory.
- Existing endpoint URLs continue to resolve through internal routing.
- Application implementations remain organized under `app/`.
- Future URL migration can be handled separately from repository organization.

Trade-offs:

- The deployment now depends on Apache-style `.htaccess` rewriting for the preserved legacy endpoint URLs.
- Two shared root bootstrap entry points remain temporarily until their consumers can be migrated safely.

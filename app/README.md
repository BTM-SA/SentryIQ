# SentryIQ Application Code

This directory contains internal application code grouped by responsibility.

## Boundaries

- `Auth/` — authentication, passkeys, and access-flow logic.
- `Vault/` — vault records, categories, folders, icons, and vault-specific services.
- `Documents/` — document storage and document services.
- `Gallery/` — gallery application services that are not public HTTP routes.
- `Security/` — security bootstrap, first-run installation, auditing, and security services.
- `Shared/` — small reusable application components that do not belong to one domain.

Public PHP endpoints remain at the repository root during the migration. Where an endpoint is part of the deployed interface, the root file may be a thin compatibility wrapper that delegates to the implementation in `app/`.

The `cloud/` directory is a separate layer and should not be collapsed into `app/`.

# SentryIQ Application Code

This directory contains internal application code grouped by responsibility.

## Planned boundaries

- `Auth/` — authentication, passkeys, and access-flow logic.
- `Vault/` — vault records, categories, folders, icons, and vault-specific services.
- `Documents/` — document storage and document services.
- `Gallery/` — gallery domain services that are not public HTTP entry points.
- `Security/` — security bootstrap, auditing, and shared security services.
- `Shared/` — small reusable application components that do not belong to one domain.

Public PHP endpoints remain at the repository root during the migration. They should become thin entry points that delegate into this directory rather than being moved blindly.
